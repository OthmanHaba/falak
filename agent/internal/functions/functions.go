// Package functions implements the fn.* commands: it writes a function release (the code files the control
// plane sends), installs its dependencies with the runtime image's falak-fn-install in a one-shot container, keeps
// falak-fn-gateway.service running and registers the release with it (see package fngateway).
package functions

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/fngateway"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/redact"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

const (
	// Root holds every function: <site>/releases/<release>/ and .cache/<runtime>/.
	Root            = fngateway.DefaultStateDir
	GatewayUnitPath = "/etc/systemd/system/falak-fn-gateway.service"
	gatewayUnit     = "falak-fn-gateway.service"
	markerFile      = ".falak-release.json"

	maxFiles     = 200
	maxBytes     = 2 << 20
	nobodyID     = 65534
	defaultKeep  = 5
	installLabel = "falak.fn.install"
)

var (
	siteRe    = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)
	releaseRe = regexp.MustCompile(`^[a-z0-9]{1,64}$`)
	fileRe    = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_.-]*(/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$`)
)

// Docker is the subset of the Engine API client used here.
type Docker interface {
	ImagePull(ctx context.Context, ref string, auth *docker.Auth, w io.Writer) error
	ImageInspect(ctx context.Context, ref string) (string, bool, error)
	ContainerCreate(ctx context.Context, name string, body docker.CreateBody) (string, error)
	ContainerStart(ctx context.Context, id string) error
	ContainerLogs(ctx context.Context, id string, follow bool, tail int, w io.Writer) error
	ContainerWait(ctx context.Context, id string) (int, error)
	ContainerRemove(ctx context.Context, id string) error
	NetworkExists(ctx context.Context, name string) (bool, error)
	NetworkCreate(ctx context.Context, name string, labels map[string]string) error
}

// Gateway is the admin API of falak-fn-gateway.
type Gateway interface {
	Version(ctx context.Context) (string, error)
	Apply(ctx context.Context, spec fngateway.Spec) (fngateway.ApplyResult, error)
	Delete(ctx context.Context, site string) (bool, error)
	Status(ctx context.Context) ([]fngateway.Status, error)
	Run(ctx context.Context, site string, r fngateway.RunRequest, w io.Writer) (int, error)
}

// Deps of the executors.
type Deps struct {
	FS      hostfs.FS
	Runner  runner.Runner
	Docker  Docker
	Gateway Gateway
	Logger  *slog.Logger
	// Binary runs the gateway (default /usr/local/bin/falak-agent).
	Binary string
	// Version is this agent's version; a gateway reporting another one is restarted on the next release.
	Version string
	// SocketWait is how long to wait for the gateway's admin socket after (re)starting it (default 15s).
	SocketWait time.Duration
}

// Functions holds the executors.
type Functions struct {
	d Deps
	// gatewayMu serialises ensureGateway: a release and the start-up refresh must not restart the gateway twice.
	gatewayMu sync.Mutex
}

// New builds the fn.* executors.
func New(d Deps) *Functions {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.Binary == "" {
		d.Binary = "/usr/local/bin/falak-agent"
	}
	if d.SocketWait <= 0 {
		d.SocketWait = 15 * time.Second
	}
	return &Functions{d: d}
}

// Register adds fn.release.apply, fn.release.remove and fn.status.
func (f *Functions) Register(reg *commands.Registry) {
	reg.Register("fn.release.apply", commands.Typed(f.Apply))
	reg.Register("fn.release.remove", commands.Typed(f.Remove))
	reg.Register("fn.status", commands.Typed(f.Status))
	reg.Register("fn.run", commands.Typed(f.Run))
}

// RunPayload is fn.run ("Run now" of a schedule).
type RunPayload struct {
	Site     string `json:"site"`
	Schedule string `json:"schedule"`
	Name     string `json:"name,omitempty"`
	Cron     string `json:"cron,omitempty"`
	TimeoutS int    `json:"timeout_s,omitempty"`
}

// RunResult is fn.run's result.
type RunResult struct {
	ExitCode   int   `json:"exit_code"`
	DurationMS int64 `json:"duration_ms"`
}

// Run is fn.run: the schedule runs once now, its output streamed as the command's.
func (f *Functions) Run(ctx context.Context, p RunPayload, st commands.Stream) (any, error) {
	if !siteRe.MatchString(p.Site) {
		return nil, &commands.PayloadError{Err: errors.New("invalid site")}
	}
	start := time.Now()
	code, err := f.d.Gateway.Run(ctx, p.Site, fngateway.RunRequest{Schedule: p.Schedule, Name: p.Name, Cron: p.Cron, Trigger: "manual", TimeoutS: p.TimeoutS}, st.Stdout())
	res := RunResult{ExitCode: code, DurationMS: time.Since(start).Milliseconds()}
	if err != nil {
		return res, err
	}
	if code != 0 {
		return res, &commands.ExitError{Code: code, Err: fmt.Errorf("the run exited with code %d", code)}
	}
	return res, nil
}

// File is one source file of a release.
type File struct {
	Path    string `json:"path"`
	Content string `json:"content"`
}

// ApplyPayload is fn.release.apply.
type ApplyPayload struct {
	Site            string             `json:"site"`
	Release         string             `json:"release"`
	Image           string             `json:"image"`
	Pull            string             `json:"pull,omitempty"`
	RegistryAuth    *docker.Auth       `json:"registry_auth,omitempty"`
	Entrypoint      string             `json:"entrypoint"`
	Files           []File             `json:"files"`
	Env             map[string]string  `json:"env,omitempty"`
	Scaling         *fngateway.Scaling `json:"scaling,omitempty"`
	Limits          *fngateway.Limits  `json:"limits,omitempty"`
	Access          *fngateway.Access  `json:"access,omitempty"`
	InstallTimeoutS int                `json:"install_timeout_s,omitempty"`
	KeepReleases    int                `json:"keep_releases,omitempty"`
	Labels          map[string]string  `json:"labels,omitempty"`
	// Mask names the secret variables of env.
	Mask []string `json:"mask,omitempty"`
}

// Secrets are the masked env values.
func (p ApplyPayload) Secrets() []string { return redact.FromEnv(p.Env, p.Mask) }

// ApplyResult is the fn.release.apply result.
type ApplyResult struct {
	Release         string `json:"release"`
	PreviousRelease string `json:"previous_release,omitempty"`
	Installed       bool   `json:"installed"`
	BootMS          int64  `json:"boot_ms"`
}

// RemovePayload is fn.release.remove.
type RemovePayload struct {
	Site string `json:"site"`
}

// StatusPayload is fn.status.
type StatusPayload struct {
	Site string `json:"site,omitempty"`
}

// StatusResult is the fn.status result.
type StatusResult struct {
	Functions []fngateway.Status `json:"functions"`
}

type marker struct {
	Hash       string `json:"hash"`
	Image      string `json:"image"`
	Entrypoint string `json:"entrypoint"`
}

func (p *ApplyPayload) validate() error {
	if !siteRe.MatchString(p.Site) || !releaseRe.MatchString(p.Release) {
		return errors.New("invalid site or release")
	}
	if p.Image == "" {
		return errors.New("image is required")
	}
	if p.Access != nil {
		if err := p.Access.Normalize(); err != nil {
			return err
		}
	}
	switch p.Pull {
	case "", "always", "missing", "never":
	default:
		return fmt.Errorf("invalid pull %q", p.Pull)
	}
	if len(p.Files) == 0 || len(p.Files) > maxFiles {
		return fmt.Errorf("a release has 1 to %d files", maxFiles)
	}
	total, seen, entry := 0, map[string]bool{}, false
	for _, f := range p.Files {
		if !validPath(f.Path) {
			return fmt.Errorf("invalid file path %q", f.Path)
		}
		if seen[f.Path] {
			return fmt.Errorf("duplicate file %q", f.Path)
		}
		seen[f.Path] = true
		total += len(f.Content)
		entry = entry || f.Path == p.Entrypoint
	}
	if total > maxBytes {
		return fmt.Errorf("files exceed %d bytes", maxBytes)
	}
	if !entry {
		return fmt.Errorf("entrypoint %q is not one of the files", p.Entrypoint)
	}
	// A file can't also be a folder ("lib" and "lib/db.ts"): writing the tree would fail halfway.
	for name := range seen {
		for dir := path.Dir(name); dir != "."; dir = path.Dir(dir) {
			if seen[dir] {
				return fmt.Errorf("%q is both a file and a folder", dir)
			}
		}
	}
	return nil
}

// maxDepth is how many path segments a function file may have.
const maxDepth = 8

// validPath: relative, clean, not too deep, no "..", no dot-files (reserved for Falak's markers), no folder the
// installers create (node_modules, __pycache__).
func validPath(p string) bool {
	if !fileRe.MatchString(p) || path.Clean(p) != p || len(p) > 255 {
		return false
	}
	segs := strings.Split(p, "/")
	if len(segs) > maxDepth {
		return false
	}
	for _, seg := range segs {
		if seg == ".." || seg == "." || seg == "node_modules" || seg == "__pycache__" {
			return false
		}
	}
	return true
}

func (p *ApplyPayload) hash() string {
	files := append([]File(nil), p.Files...)
	sort.Slice(files, func(i, j int) bool { return files[i].Path < files[j].Path })
	h := sha256.New()
	fmt.Fprintf(h, "%s\x00%s\x00", p.Image, p.Entrypoint)
	for _, f := range files {
		fmt.Fprintf(h, "%s\x00%d\x00%s", f.Path, len(f.Content), f.Content)
	}
	return hex.EncodeToString(h.Sum(nil))
}

// codeHash covers the function's own files only (not the runtime image): the key of its saved lock files.
func (p *ApplyPayload) codeHash() string {
	files := append([]File(nil), p.Files...)
	sort.Slice(files, func(i, j int) bool { return files[i].Path < files[j].Path })
	h := sha256.New()
	fmt.Fprintf(h, "%s\x00", p.Entrypoint)
	for _, f := range files {
		fmt.Fprintf(h, "%s\x00%d\x00%s", f.Path, len(f.Content), f.Content)
	}
	return hex.EncodeToString(h.Sum(nil))
}

// keepLocks is how many code versions' lock files a function keeps.
const keepLocks = 20

func locksDir(site, codeHash string) string { return path.Join(Root, site, "locks", codeHash) }

func siteDir(site string) string             { return path.Join(Root, site) }
func releasesDir(site string) string         { return path.Join(Root, site, "releases") }
func releaseDir(site, release string) string { return path.Join(releasesDir(site), release) }

// cacheDir is the package cache shared by every release of one runtime image (repository without tag/digest).
func cacheDir(image string) string {
	repo := image
	if i := strings.Index(repo, "@"); i >= 0 {
		repo = repo[:i]
	}
	if i := strings.LastIndex(repo, ":"); i > strings.LastIndex(repo, "/") {
		repo = repo[:i]
	}
	key := regexp.MustCompile(`[^a-z0-9]+`).ReplaceAllString(strings.ToLower(repo), "-")
	return path.Join(Root, ".cache", strings.Trim(key, "-"))
}

// Apply is fn.release.apply.
func (f *Functions) Apply(ctx context.Context, p ApplyPayload, st commands.Stream) (any, error) {
	if err := p.validate(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	hash := p.hash()
	dir := releaseDir(p.Site, p.Release)
	installed := false
	if !f.current(dir, hash) {
		if err := f.build(ctx, p, hash, dir, st); err != nil {
			return nil, err
		}
		installed = true
	} else {
		fmt.Fprintf(st.Stdout(), "release %s already installed\n", p.Release)
	}

	if err := f.ensureGateway(ctx, st); err != nil {
		return nil, err
	}
	spec := fngateway.Spec{Site: p.Site, Release: p.Release, Image: p.Image, Entrypoint: p.Entrypoint,
		ReleaseDir: f.d.FS.P(dir), Env: p.Env, Labels: p.Labels}
	if p.Scaling != nil {
		spec.Scaling = *p.Scaling
	}
	if p.Limits != nil {
		spec.Limits = *p.Limits
	}
	if p.Access != nil {
		spec.Access = *p.Access
	}
	if err := spec.Normalize(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	fmt.Fprintf(st.Stdout(), "starting release %s\n", p.Release)
	actx, cancel := context.WithTimeout(ctx, time.Duration(spec.Limits.StartTimeoutS)*time.Second+time.Minute)
	defer cancel()
	res, err := f.d.Gateway.Apply(actx, spec)
	if err != nil {
		return nil, fmt.Errorf("release %s did not start: %w", p.Release, err)
	}
	fmt.Fprintf(st.Stdout(), "release %s is live (booted in %dms)\n", p.Release, res.BootMS)

	keep := p.KeepReleases
	if keep <= 0 {
		keep = defaultKeep
	}
	f.prune(p.Site, keep, p.Release, res.PreviousRelease)
	return ApplyResult{Release: p.Release, PreviousRelease: res.PreviousRelease, Installed: installed, BootMS: res.BootMS}, nil
}

// current reports whether dir holds this exact release, installed.
func (f *Functions) current(dir, hash string) bool {
	b, err := f.d.FS.ReadFile(path.Join(dir, markerFile))
	if err != nil {
		return false
	}
	var m marker
	return json.Unmarshal(b, &m) == nil && m.Hash == hash
}

// build writes the files to a temporary directory, installs the dependencies there and moves it into place, so
// a failed install never leaves a half release behind.
func (f *Functions) build(ctx context.Context, p ApplyPayload, hash, dir string, st commands.Stream) error {
	fs := f.d.FS
	if err := fs.MkdirAll(releasesDir(p.Site), 0o755); err != nil {
		return err
	}
	tmp := path.Join(releasesDir(p.Site), ".tmp-"+p.Release+"-"+randHex())
	defer func() { _ = os.RemoveAll(fs.P(tmp)) }()
	for _, file := range p.Files {
		if _, err := fs.WriteFile(path.Join(tmp, file.Path), []byte(file.Content), 0o644); err != nil {
			return err
		}
	}
	fmt.Fprintf(st.Stdout(), "wrote %d file(s)\n", len(p.Files))
	if n := f.restoreLocks(p, tmp); n > 0 {
		fmt.Fprintln(st.Stdout(), "reusing the dependency versions this code was installed with")
	}
	if err := f.chownNobody(tmp, true); err != nil {
		return err
	}
	if err := f.pull(ctx, p, st); err != nil {
		return err
	}
	cache := cacheDir(p.Image)
	if err := fs.MkdirAll(cache, 0o755); err != nil {
		return err
	}
	if err := f.chownNobody(cache, false); err != nil {
		return err
	}
	if err := f.install(ctx, p, fs.P(tmp), fs.P(cache), st); err != nil {
		return err
	}
	f.saveLocks(p, tmp)
	m, _ := json.Marshal(marker{Hash: hash, Image: p.Image, Entrypoint: p.Entrypoint})
	if _, err := fs.WriteFile(path.Join(tmp, markerFile), m, 0o644); err != nil {
		return err
	}
	if fs.Exists(dir) {
		if err := os.RemoveAll(fs.P(dir)); err != nil {
			return err
		}
	}
	return os.Rename(fs.P(tmp), fs.P(dir))
}

// Lock files: what the runtime's install generated at the top of the release (bun.lock, a generated package.json,
// uv.lock, go.sum …). They are saved per code hash, so a later release of the same code — a redeploy, a scaling
// change, "deploy this version" — installs the same dependency versions instead of resolving them again.

// restoreLocks copies the saved lock files of this code into dir; it returns how many.
func (f *Functions) restoreLocks(p ApplyPayload, dir string) int {
	src := f.d.FS.P(locksDir(p.Site, p.codeHash()))
	entries, err := os.ReadDir(src)
	if err != nil {
		return 0
	}
	own := ownFiles(p)
	n := 0
	for _, e := range entries {
		if !e.Type().IsRegular() || own[e.Name()] {
			continue
		}
		b, err := os.ReadFile(filepath.Join(src, e.Name()))
		if err != nil {
			continue
		}
		if _, err := f.d.FS.WriteFile(path.Join(dir, e.Name()), b, 0o644); err == nil {
			n++
		}
	}
	return n
}

// saveLocks keeps the top-level files the install generated, and prunes the lock files of old code versions.
func (f *Functions) saveLocks(p ApplyPayload, dir string) {
	entries, err := os.ReadDir(f.d.FS.P(dir))
	if err != nil {
		return
	}
	own := ownFiles(p)
	dst := locksDir(p.Site, p.codeHash())
	for _, e := range entries {
		if !e.Type().IsRegular() || own[e.Name()] || e.Name() == markerFile {
			continue
		}
		info, err := e.Info()
		if err != nil || info.Size() > 8<<20 {
			continue
		}
		b, err := os.ReadFile(filepath.Join(f.d.FS.P(dir), e.Name()))
		if err != nil {
			continue
		}
		_, _ = f.d.FS.WriteFile(path.Join(dst, e.Name()), b, 0o644)
	}
	// Mark it used now (unchanged files are not rewritten), so pruning keeps recently deployed code.
	now := time.Now()
	_ = os.Chtimes(f.d.FS.P(dst), now, now)

	root := f.d.FS.P(path.Join(Root, p.Site, "locks"))
	all, err := os.ReadDir(root)
	if err != nil || len(all) <= keepLocks {
		return
	}
	type lock struct {
		name string
		mod  time.Time
	}
	var locks []lock
	for _, e := range all {
		if info, err := e.Info(); err == nil && e.IsDir() {
			locks = append(locks, lock{e.Name(), info.ModTime()})
		}
	}
	sort.Slice(locks, func(i, j int) bool { return locks[i].mod.After(locks[j].mod) })
	current := p.codeHash()
	for _, l := range locks[min(len(locks), keepLocks):] {
		if l.name != current {
			_ = os.RemoveAll(filepath.Join(root, l.name))
		}
	}
}

// ownFiles are the top-level names of the function's own files (never overwritten by saved lock files).
func ownFiles(p ApplyPayload) map[string]bool {
	own := map[string]bool{}
	for _, file := range p.Files {
		top, _, _ := strings.Cut(file.Path, "/")
		own[top] = true
	}
	return own
}

func (f *Functions) chownNobody(dir string, recursive bool) error {
	if !f.d.FS.IsReal() {
		return nil
	}
	if !recursive {
		return os.Lchown(f.d.FS.P(dir), nobodyID, nobodyID)
	}
	return filepath.WalkDir(f.d.FS.P(dir), func(p string, _ os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		return os.Lchown(p, nobodyID, nobodyID)
	})
}

func (f *Functions) pull(ctx context.Context, p ApplyPayload, st commands.Stream) error {
	switch p.Pull {
	case "never":
		return nil
	case "always":
	default: // missing
		if _, ok, err := f.d.Docker.ImageInspect(ctx, p.Image); err != nil {
			return err
		} else if ok {
			return nil
		}
	}
	fmt.Fprintf(st.Stdout(), "pulling %s\n", p.Image)
	return f.d.Docker.ImagePull(ctx, p.Image, p.RegistryAuth, st.Stdout())
}

// install runs the runtime's falak-fn-install in a one-shot, hardened container (with network, for the package
// registry), streaming its output.
func (f *Functions) install(ctx context.Context, p ApplyPayload, appDir, cache string, st commands.Stream) error {
	d := f.d.Docker
	if ok, err := d.NetworkExists(ctx, fngateway.Network); err != nil {
		return err
	} else if !ok {
		if err := d.NetworkCreate(ctx, fngateway.Network, map[string]string{fngateway.LabelManaged: "true"}); err != nil {
			return err
		}
	}
	timeout := time.Duration(p.InstallTimeoutS) * time.Second
	if timeout <= 0 {
		timeout = 180 * time.Second
	}
	rel := p.Release
	if len(rel) > 12 {
		rel = rel[:12]
	}
	name := "falak-fn-install-" + p.Site + "-" + rel
	_ = d.ContainerRemove(ctx, name)
	hc := fngateway.Hardened(1<<30, 0, 1024, "512m")
	hc.Binds = []string{appDir + ":/app:rw", cache + ":/cache:rw"}
	id, err := d.ContainerCreate(ctx, name, docker.CreateBody{
		Image: p.Image, Cmd: []string{fngateway.InstallCommand}, User: fngateway.UID, WorkingDir: "/app",
		Env:        []string{"FALAK_ENTRYPOINT=" + p.Entrypoint, "HOME=/tmp"},
		Labels:     map[string]string{fngateway.LabelManaged: "true", installLabel: p.Site},
		HostConfig: hc,
	})
	if err != nil {
		return fmt.Errorf("create install container: %w", err)
	}
	defer func() { _ = d.ContainerRemove(context.Background(), id) }()
	if err := d.ContainerStart(ctx, id); err != nil {
		return fmt.Errorf("start install container: %w", err)
	}
	fmt.Fprintf(st.Stdout(), "installing dependencies\n")

	ictx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	logsDone := make(chan struct{})
	go func() {
		defer close(logsDone)
		_ = d.ContainerLogs(ictx, id, true, 0, st.Stdout())
	}()
	code, err := d.ContainerWait(ictx, id)
	select {
	case <-logsDone:
	case <-time.After(2 * time.Second):
	}
	if ictx.Err() != nil && ctx.Err() == nil {
		return fmt.Errorf("installing dependencies timed out after %s", timeout)
	}
	if err != nil {
		return fmt.Errorf("install: %w", err)
	}
	if code != 0 {
		return &commands.ExitError{Code: 1, Err: fmt.Errorf("installing dependencies failed (exit %d)", code)}
	}
	return nil
}

// RenderGatewayUnit is falak-fn-gateway.service: root (it drives Docker), its own runtime directory for the admin
// socket, and a restart that leaves the function containers running (they are adopted on start).
func RenderGatewayUnit(binary string) string {
	return `[Unit]
Description=Falak: function gateway
After=network-online.target docker.service
Wants=network-online.target docker.service

[Service]
ExecStart=` + binary + ` fn-gateway
EnvironmentFile=-/etc/falak/agent.env
Restart=always
RestartSec=2
RuntimeDirectory=falak-fn
RuntimeDirectoryMode=0750
KillMode=mixed
TimeoutStopSec=40
LimitNOFILE=65536
Environment=GOMEMLIMIT=64MiB
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
`
}

// RefreshGateway runs when the agent starts: a gateway an agent upgrade left on the old version is restarted (it
// adopts the running function containers), so gateway fixes apply without waiting for the next release. Servers
// without functions are left alone.
func (f *Functions) RefreshGateway(ctx context.Context) error {
	if _, err := os.Stat(f.d.FS.P(GatewayUnitPath)); err != nil {
		return nil
	}
	return f.ensureGateway(ctx, quietStream{})
}

// quietStream drops the output of start-up work that no command is waiting for.
type quietStream struct{}

func (quietStream) Stdout() io.Writer   { return io.Discard }
func (quietStream) Stderr() io.Writer   { return io.Discard }
func (quietStream) Progress(float64)    {}
func (quietStream) Emit(string, string) {}

// ensureGateway installs/updates the unit and makes sure a gateway of this agent's version answers.
func (f *Functions) ensureGateway(ctx context.Context, st commands.Stream) error {
	f.gatewayMu.Lock()
	defer f.gatewayMu.Unlock()
	systemctl := func(args ...string) error {
		_, err := runner.Check(ctx, f.d.Runner, runner.Cmd{Name: "systemctl", Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
		return err
	}
	changed, err := f.d.FS.WriteFile(GatewayUnitPath, []byte(RenderGatewayUnit(f.d.Binary)), 0o644)
	if err != nil {
		return err
	}
	if changed {
		if err := systemctl("daemon-reload"); err != nil {
			return err
		}
		if err := systemctl("enable", gatewayUnit); err != nil {
			return err
		}
	}
	vctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	v, verr := f.d.Gateway.Version(vctx)
	cancel()
	switch {
	case changed || (verr == nil && f.d.Version != "" && v != f.d.Version):
		fmt.Fprintf(st.Stdout(), "restarting the function gateway\n")
		if err := systemctl("restart", gatewayUnit); err != nil {
			return err
		}
	case verr != nil:
		fmt.Fprintf(st.Stdout(), "starting the function gateway\n")
		if err := systemctl("start", gatewayUnit); err != nil {
			return err
		}
	default:
		return nil
	}
	deadline := time.Now().Add(f.d.SocketWait)
	for {
		vctx, cancel := context.WithTimeout(ctx, 2*time.Second)
		_, err := f.d.Gateway.Version(vctx)
		cancel()
		if err == nil {
			return nil
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("function gateway did not come up: %w", err)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(100 * time.Millisecond):
		}
	}
}

// prune keeps the newest keep releases (never the current or previous one) and removes stale temp dirs.
func (f *Functions) prune(site string, keep int, current, previous string) {
	root := f.d.FS.P(releasesDir(site))
	entries, err := os.ReadDir(root)
	if err != nil {
		return
	}
	type rel struct {
		name string
		mod  time.Time
	}
	var rels []rel
	for _, e := range entries {
		if !e.IsDir() {
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		if strings.HasPrefix(e.Name(), ".tmp-") {
			if time.Since(info.ModTime()) > time.Hour {
				_ = os.RemoveAll(filepath.Join(root, e.Name()))
			}
			continue
		}
		rels = append(rels, rel{e.Name(), info.ModTime()})
	}
	sort.Slice(rels, func(i, j int) bool { return rels[i].mod.After(rels[j].mod) })
	kept := 0
	for _, r := range rels {
		if r.name == current || r.name == previous || kept < keep {
			kept++
			continue
		}
		_ = os.RemoveAll(filepath.Join(root, r.name))
	}
}

// Remove is fn.release.remove.
func (f *Functions) Remove(ctx context.Context, p RemovePayload, st commands.Stream) (any, error) {
	if !siteRe.MatchString(p.Site) {
		return nil, &commands.PayloadError{Err: errors.New("invalid site")}
	}
	removed := false
	if f.d.FS.Exists(GatewayUnitPath) {
		if err := f.ensureGateway(ctx, st); err != nil {
			return nil, err
		}
		r, err := f.d.Gateway.Delete(ctx, p.Site)
		if err != nil {
			return nil, err
		}
		removed = r
	}
	if f.d.FS.Exists(siteDir(p.Site)) {
		if err := os.RemoveAll(f.d.FS.P(siteDir(p.Site))); err != nil {
			return nil, err
		}
		removed = true
	}
	fmt.Fprintf(st.Stdout(), "function %s removed=%v\n", p.Site, removed)
	return map[string]bool{"removed": removed}, nil
}

// Status is fn.status.
func (f *Functions) Status(ctx context.Context, p StatusPayload, _ commands.Stream) (any, error) {
	if p.Site != "" && !siteRe.MatchString(p.Site) {
		return nil, &commands.PayloadError{Err: errors.New("invalid site")}
	}
	res := StatusResult{Functions: []fngateway.Status{}}
	if !f.d.FS.Exists(GatewayUnitPath) {
		return res, nil
	}
	sctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	all, err := f.d.Gateway.Status(sctx)
	if err != nil {
		return nil, fmt.Errorf("function gateway: %w", err)
	}
	for _, s := range all {
		if p.Site == "" || s.Site == p.Site {
			res.Functions = append(res.Functions, s)
		}
	}
	return res, nil
}

func randHex() string {
	b := make([]byte, 4)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}
