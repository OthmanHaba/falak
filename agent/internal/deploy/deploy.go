// Package deploy implements the native release lifecycle:
//
//	/srv/kiln/sites/<site>/
//	├── releases/<release-ulid>/
//	├── shared/          (.env, storage/, custom shared paths)
//	└── current -> releases/<release-ulid>
//
// All symlinks are relative so a site directory can be moved (and tested under a temp root).
package deploy

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/obs"
	"github.com/kiln/agent/internal/runner"
)

// ProcRestarter restarts supervised programs (implemented by the supervisor).
type ProcRestarter interface {
	Restart(ctx context.Context, names []string) error
}

// WorkerRestarter restarts FrankenPHP worker scripts (implemented by edge.Client).
type WorkerRestarter interface {
	RestartFrankenPHPWorkers(ctx context.Context) error
}

// Options for the Deployer.
type Options struct {
	FS        hostfs.FS
	Runner    runner.Runner
	HTTP      *http.Client
	SitesRoot string // default /srv/kiln/sites
	Procs     ProcRestarter
	Workers   WorkerRestarter
	Events    obs.Sink // deployment lifecycle log records (kiln.event.type=deployment); nil disables
	Logger    *slog.Logger
}

// Deployer implements deploy.* executors (except container.swap, which lives in the docker package).
type Deployer struct {
	o     Options
	locks sync.Map // site → *sync.Mutex
}

// New creates a Deployer.
func New(o Options) *Deployer {
	if o.SitesRoot == "" {
		o.SitesRoot = "/srv/kiln/sites"
	}
	if o.HTTP == nil {
		o.HTTP = &http.Client{Timeout: 30 * time.Minute}
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	return &Deployer{o: o}
}

// Register adds the deploy.* executors.
func (d *Deployer) Register(reg *commands.Registry) {
	reg.Register("deploy.fetch", commands.Typed(d.Fetch))
	reg.Register("deploy.prepare", commands.Typed(d.Prepare))
	reg.Register("deploy.hook", commands.Typed(d.Hook))
	reg.Register("deploy.activate", commands.Typed(d.Activate))
	reg.Register("deploy.rollback", commands.Typed(d.Rollback))
	reg.Register("deploy.prune", commands.Typed(d.Prune))
}

var (
	slugRE = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)
	ulidRE = regexp.MustCompile(`^[0-9A-HJKMNP-TV-Z]{26}$`)
)

// Owner applied to created files.
type Owner struct {
	User  string `json:"user"`
	Group string `json:"group,omitempty"`
}

// site holds resolved (real) paths of one site.
type site struct {
	host string // host path, e.g. /srv/kiln/sites/shop
	real string // real path (under the fs root)
}

func (s site) releases() string             { return filepath.Join(s.real, "releases") }
func (s site) release(id string) string     { return filepath.Join(s.real, "releases", id) }
func (s site) shared() string               { return filepath.Join(s.real, "shared") }
func (s site) current() string              { return filepath.Join(s.real, "current") }
func (s site) hostRelease(id string) string { return filepath.Join(s.host, "releases", id) }

func (d *Deployer) site(name, root string) (site, error) {
	if !slugRE.MatchString(name) {
		return site{}, &commands.PayloadError{Err: fmt.Errorf("invalid site slug %q", name)}
	}
	if root == "" {
		root = d.o.SitesRoot
	}
	if !filepath.IsAbs(root) {
		return site{}, &commands.PayloadError{Err: fmt.Errorf("sites_root must be absolute")}
	}
	host := filepath.Join(root, name)
	return site{host: host, real: d.o.FS.P(host)}, nil
}

func checkRelease(id string) error {
	if !ulidRE.MatchString(id) {
		return &commands.PayloadError{Err: fmt.Errorf("invalid release id %q", id)}
	}
	return nil
}

// lock serializes mutations per site (activate vs. prune vs. rollback).
func (d *Deployer) lock(site string) func() {
	m, _ := d.locks.LoadOrStore(site, &sync.Mutex{})
	mu := m.(*sync.Mutex)
	mu.Lock()
	return mu.Unlock
}

// currentRelease returns the release id `current` points to ("" when absent).
func currentRelease(s site) (string, error) {
	target, err := os.Readlink(s.current())
	if errors.Is(err, fs.ErrNotExist) {
		return "", nil
	}
	if err != nil {
		st, serr := os.Lstat(s.current())
		if serr == nil && !st.Mode().IsRegular() && st.IsDir() {
			return "", fmt.Errorf("%s is a directory, not a symlink (in-place site?)", s.current())
		}
		return "", err
	}
	return filepath.Base(target), nil
}

// listReleases returns release ids sorted oldest → newest (ULIDs sort chronologically).
func listReleases(s site) ([]string, error) {
	ents, err := os.ReadDir(s.releases())
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var ids []string
	for _, e := range ents {
		if e.IsDir() && ulidRE.MatchString(e.Name()) {
			ids = append(ids, e.Name())
		}
	}
	sort.Strings(ids)
	return ids, nil
}

// swapCurrent atomically points current → releases/<id> (symlink tmp + rename(2)).
func swapCurrent(s site, id string) error {
	if st, err := os.Stat(s.release(id)); err != nil || !st.IsDir() {
		return fmt.Errorf("release %s not found in %s", id, s.releases())
	}
	var rnd [6]byte
	_, _ = rand.Read(rnd[:])
	tmp := filepath.Join(s.real, ".current.tmp-"+hex.EncodeToString(rnd[:]))
	if err := os.Symlink(filepath.Join("releases", id), tmp); err != nil {
		return err
	}
	if err := os.Rename(tmp, s.current()); err != nil {
		os.Remove(tmp)
		return err
	}
	// Persist the directory entry change.
	if dir, err := os.Open(s.real); err == nil {
		_ = dir.Sync()
		dir.Close()
	}
	return nil
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func relLink(from, to string) (string, error) {
	return filepath.Rel(filepath.Dir(from), to)
}

// cleanRel validates a relative path inside a release.
func cleanRel(p string) (string, error) {
	c := filepath.Clean(p)
	if c == "." || filepath.IsAbs(c) || c == ".." || strings.HasPrefix(c, "../") {
		return "", &commands.PayloadError{Err: fmt.Errorf("path %q must be relative and inside the release", p)}
	}
	return c, nil
}
