// Package volumes implements the volume.* commands: Docker named volumes, sized volumes (an ext4 image file
// loop-mounted by a systemd mount unit, which gives a hard size limit that grows online), bind paths from an
// allowlist and classic sites' shared paths. Snapshots are tar | zstd streams PUT to presigned URLs, so servers
// never hold storage credentials. Every file access inside a volume goes through an os.Root opened at the volume's
// root and never follows a symbolic link.
//
// Bind and shared-path volumes live in directories other users may write (a site's user owns its site directory),
// so their path is never re-walked as a string: it is opened once from a trusted anchor (the bind allowlist entry,
// the sites root) one component at a time, refusing symlinks, and every later access uses that handle.
package volumes

import (
	"context"
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
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Kinds of volume.
const (
	KindDocker     = "docker"
	KindSized      = "sized"
	KindBind       = "bind"
	KindSharedPath = "shared_path"
)

// noFollow opens the final path element without following a symlink.
const noFollow = syscall.O_NOFOLLOW

var (
	idRe         = regexp.MustCompile(`^[0-9a-z]{26}$`)
	dockerNameRe = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$`)
	labelKeyRe   = regexp.MustCompile(`^[a-z0-9][a-z0-9_.-]{0,62}$`)
)

// Docker is the subset of the Engine API the volume commands use (*docker.Client; tests fake it).
type Docker interface {
	VolumeCreate(ctx context.Context, name string, labels map[string]string) (docker.Volume, error)
	VolumeInspect(ctx context.Context, name string) (docker.Volume, bool, error)
	VolumeList(ctx context.Context) ([]docker.Volume, error)
	VolumeRemove(ctx context.Context, name string) (bool, error)
	ContainerList(ctx context.Context, all bool, labels []string) ([]docker.ContainerSummary, error)
	ContainerPause(ctx context.Context, id string) error
	ContainerUnpause(ctx context.Context, id string) error
	ContainerStop(ctx context.Context, id string, timeout time.Duration) (bool, error)
	ContainerStart(ctx context.Context, id string) error
}

// Usage is a filesystem's size, free space and use in bytes.
type Usage struct {
	Size, Available, Used uint64
}

// Deps are the dependencies of the volume commands.
type Deps struct {
	Runner runner.Runner
	FS     hostfs.FS
	HTTP   *http.Client
	Docker Docker
	Logger *slog.Logger
	// Root holds sized volumes: <Root>/<id> is the mountpoint, <Root>/images/<id>.img the image (host path).
	Root string
	// UnitDir is where mount units are written (/etc/systemd/system).
	UnitDir string
	// SitesRoot confines shared_path volumes to <SitesRoot>/<site>/shared/.
	SitesRoot string
	// BindAllow lists the host directories bind volumes may live below (empty refuses them; never the
	// directory itself, like the control plane).
	BindAllow []string
	// StatFS and Mounted are injectable for tests (defaults: statfs(2), /proc/self/mountinfo).
	StatFS  func(path string) (Usage, error)
	Mounted func(path string) bool
	// Poll is how often delete waits for containers to let go of a volume (default 1s).
	Poll time.Duration
}

// Service runs the volume.* commands.
type Service struct{ d Deps }

// New returns a Service with defaults filled in.
func New(d Deps) *Service {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = &http.Client{Timeout: 6 * time.Hour}
	}
	if d.Root == "" {
		d.Root = "/var/lib/falak/volumes"
	}
	if d.UnitDir == "" {
		d.UnitDir = "/etc/systemd/system"
	}
	if d.SitesRoot == "" {
		d.SitesRoot = "/srv/falak/sites"
	}
	if d.StatFS == nil {
		d.StatFS = statFS
	}
	if d.Mounted == nil {
		d.Mounted = mounted
	}
	if d.Poll == 0 {
		d.Poll = time.Second
	}
	return &Service{d: d}
}

// Register adds the volume.* executors.
func (s *Service) Register(reg *commands.Registry) {
	reg.Register("volume.create", commands.Typed(s.Create))
	reg.Register("volume.resize", commands.Typed(s.Resize))
	reg.Register("volume.delete", commands.Typed(s.Delete))
	reg.Register("volume.inventory", commands.Typed(s.Inventory))
	reg.Register("volume.archive", commands.Typed(s.Archive))
	reg.Register("volume.restore", commands.Typed(s.Restore))
	reg.Register("volume.clone", commands.Typed(s.Clone))
	reg.Register("volume.browse", commands.Typed(s.Browse))
	reg.Register("volume.download", commands.Typed(s.Download))
	reg.Register("volume.drill", commands.Typed(s.Drill))
}

// Ref names a volume in every payload.
type Ref struct {
	ID   string `json:"id"`
	Kind string `json:"kind"`
	Name string `json:"name,omitempty"`
	Path string `json:"path,omitempty"`
}

func payloadErr(format string, a ...any) error {
	return &commands.PayloadError{Err: fmt.Errorf(format, a...)}
}

// check validates a ref and, for bind and shared paths, that the path is one the agent may touch (as a string: the
// directories themselves are opened through anchor and descend).
func (s *Service) check(r Ref) error {
	if !idRe.MatchString(r.ID) {
		return payloadErr("invalid volume id %q", r.ID)
	}
	switch r.Kind {
	case KindDocker:
		if !dockerNameRe.MatchString(r.Name) {
			return payloadErr("invalid docker volume name %q", r.Name)
		}
	case KindSized:
	case KindBind:
		if err := s.checkPath(r.Path); err != nil {
			return err
		}
		if _, _, ok := s.bindAnchor(r.Path); !ok {
			return payloadErr("bind path %s is not below a directory of the agent's FALAK_VOLUME_BIND_ALLOW", r.Path)
		}
	case KindSharedPath:
		if err := s.checkPath(r.Path); err != nil {
			return err
		}
		rel, err := filepath.Rel(filepath.Clean(s.d.SitesRoot), r.Path)
		parts := strings.Split(filepath.ToSlash(rel), "/")
		if err != nil || len(parts) < 3 || parts[0] == ".." || parts[1] != "shared" {
			return payloadErr("shared path %s is not under %s/<site>/shared/", r.Path, s.d.SitesRoot)
		}
	default:
		return payloadErr("unknown volume kind %q", r.Kind)
	}
	return nil
}

func (s *Service) checkPath(p string) error {
	if !filepath.IsAbs(p) || filepath.Clean(p) != p || strings.ContainsRune(p, 0) {
		return payloadErr("volume path %q must be absolute and clean", p)
	}
	return nil
}

// bindAnchor returns the allowlisted directory a bind path is strictly below, and the path relative to it.
func (s *Service) bindAnchor(p string) (string, string, bool) {
	for _, a := range s.d.BindAllow {
		a = filepath.Clean(a)
		if a != "/" && strings.HasPrefix(p, a+string(filepath.Separator)) {
			return a, strings.TrimPrefix(p, a+string(filepath.Separator)), true
		}
	}
	return "", "", false
}

// anchor returns the trusted directory a bind or shared-path volume is opened from (root-owned or configured by the
// admin) and the volume's path relative to it: the bind allowlist entry, or the sites root for <site>/shared/<path>.
func (s *Service) anchor(r Ref) (string, string, error) {
	if r.Kind == KindBind {
		a, rel, ok := s.bindAnchor(r.Path)
		if !ok {
			return "", "", payloadErr("bind path %s is not allowed", r.Path)
		}
		return a, rel, nil
	}
	root := filepath.Clean(s.d.SitesRoot)
	return root, strings.TrimPrefix(r.Path, root+string(filepath.Separator)), nil
}

// errSymlink is returned when a component of a volume's path is a symbolic link.
var errSymlink = errors.New("is a symbolic link")

// descendHook runs between a component's check and its opening (tests swap the directory there).
var descendHook func(comp string)

// descend opens rel below root one component at a time: every component must be a real directory (Lstat, never
// followed), and the directory opened must be the one that was checked, so a component swapped for a symlink in
// between is refused. It closes the roots it opened on the way, never root.
func descend(root *os.Root, rel string) (*os.Root, error) {
	cur := root
	closeCur := func() {
		if cur != root {
			cur.Close()
		}
	}
	for _, comp := range strings.Split(rel, string(filepath.Separator)) {
		if comp == "" || comp == "." || comp == ".." {
			closeCur()
			return nil, fmt.Errorf("invalid path component %q", comp)
		}
		fi, err := cur.Lstat(comp)
		if err != nil {
			closeCur()
			return nil, err
		}
		if fi.Mode()&fs.ModeSymlink != 0 {
			closeCur()
			return nil, fmt.Errorf("%s %w", comp, errSymlink)
		}
		if !fi.IsDir() {
			closeCur()
			return nil, fmt.Errorf("%s is not a directory", comp)
		}
		if descendHook != nil {
			descendHook(comp)
		}
		next, err := cur.OpenRoot(comp)
		if err != nil {
			closeCur()
			return nil, err
		}
		got, err := next.Stat(".")
		if err != nil || !os.SameFile(fi, got) {
			next.Close()
			closeCur()
			return nil, fmt.Errorf("%s changed while it was opened", comp)
		}
		closeCur()
		cur = next
	}
	if cur == root {
		return nil, errors.New("empty volume path")
	}
	return cur, nil
}

// openPath opens a bind or shared-path volume's directory from its anchor (see descend). With create, missing
// directories are made below the anchor first (os.Root never lets that leave it). ok is false when it doesn't exist.
func (s *Service) openPath(r Ref, create bool) (*os.Root, bool, error) {
	a, rel, err := s.anchor(r)
	if err != nil {
		return nil, false, err
	}
	if create {
		if err := s.d.FS.MkdirAll(a, 0o755); err != nil {
			return nil, false, err
		}
	}
	ar, err := os.OpenRoot(s.d.FS.P(a))
	if errors.Is(err, os.ErrNotExist) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	defer ar.Close()
	if create {
		if err := ar.MkdirAll(filepath.ToSlash(rel), 0o755); err != nil {
			return nil, false, err
		}
	}
	root, err := descend(ar, rel)
	if errors.Is(err, os.ErrNotExist) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	return root, true, nil
}

// openParent opens the directory holding a bind or shared-path volume (see descend) and returns the volume's name in it.
func (s *Service) openParent(r Ref) (*os.Root, string, error) {
	a, rel, err := s.anchor(r)
	if err != nil {
		return nil, "", err
	}
	ar, err := os.OpenRoot(s.d.FS.P(a))
	if err != nil {
		return nil, "", err
	}
	parent, base := filepath.Split(rel)
	parent = strings.TrimSuffix(parent, string(filepath.Separator))
	if parent == "" {
		return ar, base, nil
	}
	defer ar.Close()
	pr, err := descend(ar, parent)
	if err != nil {
		return nil, "", err
	}
	return pr, base, nil
}

func (s *Service) mountpoint(id string) string { return filepath.Join(s.d.Root, id) }
func (s *Service) image(id string) string      { return filepath.Join(s.d.Root, "images", id+".img") }

// hostPath is where a volume's data lives on the host (unmapped), and whether the volume exists.
func (s *Service) hostPath(ctx context.Context, r Ref) (string, bool, error) {
	switch r.Kind {
	case KindDocker:
		if s.d.Docker == nil {
			return "", false, errors.New("docker is not available")
		}
		v, ok, err := s.d.Docker.VolumeInspect(ctx, r.Name)
		return v.Mountpoint, ok, err
	case KindSized:
		mp := s.mountpoint(r.ID)
		return mp, s.d.FS.Exists(s.image(r.ID)), nil
	default:
		root, ok, err := s.openPath(r, false)
		if err != nil || !ok {
			return r.Path, false, err
		}
		root.Close()
		return r.Path, true, nil
	}
}

// openRoot opens a volume's root for reading or writing its files. Sized volumes must be mounted (an unmounted
// mountpoint is an empty directory of the host's disk).
func (s *Service) openRoot(ctx context.Context, r Ref) (*os.Root, string, error) {
	if err := s.check(r); err != nil {
		return nil, "", err
	}
	if r.Kind == KindBind || r.Kind == KindSharedPath {
		root, ok, err := s.openPath(r, false)
		if err != nil {
			return nil, "", err
		}
		if !ok {
			return nil, "", fmt.Errorf("volume %s does not exist", r.ID)
		}
		return root, r.Path, nil
	}
	p, ok, err := s.hostPath(ctx, r)
	if err != nil {
		return nil, "", err
	}
	if !ok {
		return nil, "", fmt.Errorf("volume %s does not exist", r.ID)
	}
	if r.Kind == KindSized && !s.d.Mounted(s.d.FS.P(p)) {
		return nil, "", fmt.Errorf("volume %s is not mounted", r.ID)
	}
	root, err := os.OpenRoot(s.d.FS.P(p))
	if err != nil {
		return nil, "", err
	}
	return root, p, nil
}

// container is a running container that mounts a volume.
type container struct{ id, name string }

// users lists the running containers that mount the volume (by name for Docker volumes, by host path for the
// others: the path itself or anything below it).
func (s *Service) users(ctx context.Context, r Ref, hostPath string) ([]container, error) {
	if s.d.Docker == nil {
		return nil, nil
	}
	list, err := s.d.Docker.ContainerList(ctx, false, nil)
	if err != nil {
		return nil, fmt.Errorf("list containers: %w", err)
	}
	var out []container
	for _, c := range list {
		for _, m := range c.Mounts {
			match := r.Kind == KindDocker && m.Name == r.Name
			if !match && r.Kind != KindDocker && hostPath != "" {
				match = m.Source == hostPath || strings.HasPrefix(m.Source, hostPath+"/")
			}
			if match {
				name := c.ID
				if len(c.Names) > 0 {
					name = strings.TrimPrefix(c.Names[0], "/")
				}
				out = append(out, container{id: c.ID, name: name})
				break
			}
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].name < out[j].name })
	return out, nil
}

func names(cs []container) []string {
	out := make([]string, 0, len(cs))
	for _, c := range cs {
		out = append(out, c.name)
	}
	return out
}

// quiesce applies a consistency mode to the containers that mount a volume and returns the function that undoes
// it, plus the containers it touched. A move keeps them stopped (the caller skips undo once the archive is safe).
func (s *Service) quiesce(ctx context.Context, r Ref, hostPath, mode string, st commands.Stream) (func(), []string, error) {
	if mode == "" || mode == "none" {
		return func() {}, nil, nil
	}
	if mode != "pause" && mode != "stop" {
		return nil, nil, payloadErr("unknown consistency %q", mode)
	}
	cs, err := s.users(ctx, r, hostPath)
	if err != nil {
		return nil, nil, err
	}
	var done []container
	undo := func() {
		// The volume was read whatever happened: bring the containers back even when the command was cancelled.
		bg, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		for _, c := range done {
			var err error
			if mode == "pause" {
				err = s.d.Docker.ContainerUnpause(bg, c.id)
			} else {
				err = s.d.Docker.ContainerStart(bg, c.id)
			}
			if err != nil {
				fmt.Fprintf(st.Stderr(), "could not resume %s: %v\n", c.name, err)
			}
		}
	}
	for _, c := range cs {
		if mode == "pause" {
			err = s.d.Docker.ContainerPause(ctx, c.id)
		} else {
			_, err = s.d.Docker.ContainerStop(ctx, c.id, 30*time.Second)
		}
		if err != nil {
			undo()
			return nil, nil, fmt.Errorf("%s %s: %w", mode, c.name, err)
		}
		done = append(done, c)
		fmt.Fprintf(st.Stdout(), "%s %s\n", map[string]string{"pause": "paused", "stop": "stopped"}[mode], c.name)
	}
	return undo, names(cs), nil
}

func statFS(path string) (Usage, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return Usage{}, err
	}
	bs := uint64(st.Bsize)
	return Usage{Size: st.Blocks * bs, Available: uint64(st.Bavail) * bs, Used: (st.Blocks - st.Bfree) * bs}, nil
}

// mounted reports whether path is a mountpoint (/proc/self/mountinfo field 5).
func mounted(path string) bool {
	b, err := os.ReadFile("/proc/self/mountinfo")
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) > 4 && unescapeMount(f[4]) == path {
			return true
		}
	}
	return false
}

func unescapeMount(s string) string {
	return strings.NewReplacer(`\040`, " ", `\011`, "\t", `\012`, "\n", `\134`, `\`).Replace(s)
}

// Margin is added to every free-space estimate (10%).
const freeMargin = 1.1

// stagingDir is where snapshots and downloads are staged: on the volume store's filesystem (never /tmp, often a
// small tmpfs), readable by root only.
func (s *Service) stagingDir() (string, error) {
	dir := filepath.Join(s.d.Root, ".staging")
	if err := s.d.FS.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	return s.d.FS.P(dir), nil
}

// staging returns the staging directory once it has room for need bytes (plus the margin).
func (s *Service) staging(need int64) (string, error) {
	dir, err := s.stagingDir()
	if err != nil {
		return "", err
	}
	if err := s.room(dir, need, "staging ("+filepath.Join(s.d.Root, ".staging")+")"); err != nil {
		return "", err
	}
	return dir, nil
}

// room fails when the filesystem holding dir has less than need bytes (plus the margin) free.
func (s *Service) room(dir string, need int64, what string) error {
	if need <= 0 {
		return nil
	}
	us, err := s.d.StatFS(dir)
	if err != nil {
		return fmt.Errorf("free space of %s: %w", what, err)
	}
	want := uint64(float64(need) * freeMargin)
	if us.Available < want {
		return fmt.Errorf("not enough free space for %s: %d bytes free, %d needed", what, us.Available, want)
	}
	return nil
}
