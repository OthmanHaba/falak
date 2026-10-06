// Package volumes implements the volume.* commands: Docker named volumes, sized volumes (an ext4 image file
// loop-mounted by a systemd mount unit, which gives a hard size limit that grows online), bind paths from an
// allowlist and classic sites' shared paths. Snapshots are tar | zstd streams PUT to presigned URLs, so servers
// never hold storage credentials. Every file access inside a volume goes through an os.Root opened at the volume's
// root and never follows a symbolic link.
package volumes

import (
	"context"
	"errors"
	"fmt"
	"io"
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

// Sealer wraps the compressed snapshot stream before it is stored. Step 4 (backup encryption) plugs in here;
// nil stores the snapshot as is.
type Sealer func(w io.Writer) (io.WriteCloser, error)

// Opener undoes a Sealer when a snapshot is restored (nil: the snapshot is plain tar.zst).
type Opener func(r io.Reader) (io.Reader, error)

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
	// BindAllow lists the host directories bind volumes may use (empty refuses them).
	BindAllow []string
	TempDir   string
	// StatFS and Mounted are injectable for tests (defaults: statfs(2), /proc/self/mountinfo).
	StatFS  func(path string) (Usage, error)
	Mounted func(path string) bool
	// Seal / Open: the snapshot encryption seam (step 4).
	Seal Sealer
	Open Opener
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

// check validates a ref and, for bind and shared paths, that the path is one the agent may touch.
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
		if !s.within(r.Path, s.d.BindAllow) {
			return payloadErr("bind path %s is not within the agent's FALAK_VOLUME_BIND_ALLOW", r.Path)
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
		if !s.within(r.Path, []string{filepath.Join(s.d.SitesRoot, parts[0], "shared")}) {
			return payloadErr("shared path %s leaves its site's shared directory", r.Path)
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

// within reports whether host path p is one of the allowed directories or below one, also once symlinks in the
// existing part of the path are resolved.
func (s *Service) within(p string, allowed []string) bool {
	for _, a := range allowed {
		a = filepath.Clean(a)
		if p != a && !strings.HasPrefix(p, a+string(filepath.Separator)) {
			continue
		}
		ra, err := filepath.EvalSymlinks(s.d.FS.P(a))
		if err != nil {
			// The allowed directory doesn't exist yet: nothing below it can be a symlink out.
			return errors.Is(err, os.ErrNotExist)
		}
		rp, err := resolveExisting(s.d.FS.P(p))
		if err != nil {
			return false
		}
		if rp == ra || strings.HasPrefix(rp, ra+string(filepath.Separator)) {
			return true
		}
	}
	return false
}

// resolveExisting resolves symlinks in the longest existing prefix of p and appends the rest.
func resolveExisting(p string) (string, error) {
	rest := ""
	for {
		r, err := filepath.EvalSymlinks(p)
		if err == nil {
			return filepath.Join(r, rest), nil
		}
		if !errors.Is(err, os.ErrNotExist) {
			return "", err
		}
		parent := filepath.Dir(p)
		if parent == p {
			return "", err
		}
		rest = filepath.Join(filepath.Base(p), rest)
		p = parent
	}
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
		fi, err := os.Stat(s.d.FS.P(r.Path))
		if errors.Is(err, os.ErrNotExist) {
			return r.Path, false, nil
		}
		if err != nil {
			return "", false, err
		}
		if !fi.IsDir() {
			return "", false, fmt.Errorf("%s is not a directory", r.Path)
		}
		return r.Path, true, nil
	}
}

// openRoot opens a volume's root for reading or writing its files. Sized volumes must be mounted (an unmounted
// mountpoint is an empty directory of the host's disk).
func (s *Service) openRoot(ctx context.Context, r Ref) (*os.Root, string, error) {
	if err := s.check(r); err != nil {
		return nil, "", err
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
// it, plus the containers it touched.
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
