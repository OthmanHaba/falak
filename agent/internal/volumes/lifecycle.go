package volumes

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Size limits of sized volumes (the schemas' bounds).
const (
	MinSize = 16 << 20
	MaxSize = 16 << 40
)

// CreatePayload is volume.create.
type CreatePayload struct {
	Volume    Ref               `json:"volume"`
	SizeBytes int64             `json:"size_bytes,omitempty"`
	Labels    map[string]string `json:"labels,omitempty"`
	// Adopt takes over an existing Docker volume that is not this volume's (the control plane asks explicitly).
	Adopt bool `json:"adopt,omitempty"`
}

// CreateResult is its result.
type CreateResult struct {
	Path      string `json:"path"`
	SizeBytes int64  `json:"size_bytes,omitempty"`
	Created   bool   `json:"created"`
}

// Create creates a volume (idempotent: an existing one is reported with created=false).
func (s *Service) Create(ctx context.Context, p CreatePayload, st commands.Stream) (any, error) {
	if err := s.check(p.Volume); err != nil {
		return nil, err
	}
	for k, v := range p.Labels {
		if !labelKeyRe.MatchString(k) || len(v) > 256 {
			return nil, payloadErr("invalid label %q", k)
		}
	}
	return s.create(ctx, p.Volume, p.SizeBytes, p.Labels, p.Adopt, st)
}

// idLabel ties a Docker volume to the Falak volume that created it.
const idLabel = "falak.volume.id"

// create makes a volume if it is missing. An existing Docker volume is only this volume when it carries its id
// label (a retried create); anyone else's is refused unless adopt, so a name never silently takes over another
// volume's data.
func (s *Service) create(ctx context.Context, r Ref, size int64, labels map[string]string, adopt bool, st commands.Stream) (CreateResult, error) {
	switch r.Kind {
	case KindDocker:
		if s.d.Docker == nil {
			return CreateResult{}, errors.New("docker is not available")
		}
		if v, ok, err := s.d.Docker.VolumeInspect(ctx, r.Name); err != nil {
			return CreateResult{}, err
		} else if ok {
			if v.Labels[idLabel] != r.ID && !adopt {
				return CreateResult{}, fmt.Errorf("docker volume %s already exists and is not this volume", r.Name)
			}
			return CreateResult{Path: v.Mountpoint}, nil
		}
		withID := map[string]string{idLabel: r.ID}
		for k, v := range labels {
			if k != idLabel {
				withID[k] = v
			}
		}
		v, err := s.d.Docker.VolumeCreate(ctx, r.Name, withID)
		if err != nil {
			return CreateResult{}, fmt.Errorf("create docker volume %s: %w", r.Name, err)
		}
		fmt.Fprintf(st.Stdout(), "created docker volume %s\n", r.Name)
		return CreateResult{Path: v.Mountpoint, Created: true}, nil
	case KindSized:
		return s.createSized(ctx, r.ID, size, st)
	default:
		root, existed, err := s.openPath(r, false)
		if err != nil {
			return CreateResult{}, fmt.Errorf("%s: not a usable directory: %w", r.Path, err)
		}
		if !existed {
			if root, _, err = s.openPath(r, true); err != nil {
				return CreateResult{}, fmt.Errorf("%s: not a usable directory: %w", r.Path, err)
			}
		}
		root.Close()
		return CreateResult{Path: r.Path, Created: !existed}, nil
	}
}

// unitName is the systemd mount unit for a mountpoint: systemd requires a .mount unit to be named after its
// Where= path (systemd-escape --path), e.g. var-lib-falak-volumes-<id>.mount.
func unitName(mountpoint string) string {
	var b strings.Builder
	for i, part := range strings.Split(strings.Trim(mountpoint, "/"), "/") {
		if i > 0 {
			b.WriteByte('-')
		}
		for j := 0; j < len(part); j++ {
			c := part[j]
			switch {
			case c >= 'a' && c <= 'z', c >= 'A' && c <= 'Z', c >= '0' && c <= '9', c == '_', c == '.' && j > 0:
				b.WriteByte(c)
			default:
				fmt.Fprintf(&b, `\x%02x`, c)
			}
		}
	}
	return b.String() + ".mount"
}

func (s *Service) unitPath(id string) string {
	return filepath.Join(s.d.UnitDir, unitName(s.mountpoint(id)))
}

// unit is the mount unit of a sized volume: the image is loop-mounted at boot, before Docker starts the containers
// that use it. Should the mount fail anyway, the mountpoint itself is immutable (createSized), so a container bound
// to it cannot fill the host's disk instead of the volume.
func (s *Service) unit(id string) string {
	return fmt.Sprintf(`# Managed by falak-agent (volume.create): sized volume %s.
[Unit]
Description=Falak volume %s
Before=docker.service

[Mount]
What=%s
Where=%s
Type=ext4
Options=loop,nodev,nosuid

[Install]
WantedBy=local-fs.target
`, id, id, s.image(id), s.mountpoint(id))
}

func (s *Service) run(ctx context.Context, st commands.Stream, name string, args ...string) (runner.Result, error) {
	return runner.Check(ctx, s.d.Runner, runner.Cmd{Name: name, Args: args, Stderr: st.Stderr()})
}

func (s *Service) createSized(ctx context.Context, id string, size int64, st commands.Stream) (CreateResult, error) {
	if size < MinSize || size > MaxSize {
		return CreateResult{}, payloadErr("size_bytes must be between 16 MiB and 16 TiB")
	}
	img, mp, unit := s.image(id), s.mountpoint(id), unitName(s.mountpoint(id))
	created := false
	if !s.d.FS.Exists(img) {
		if err := s.d.FS.MkdirAll(filepath.Dir(img), 0o700); err != nil {
			return CreateResult{}, err
		}
		// Written next to the image first: a half-made image is never mistaken for a volume.
		tmp := img + ".partial"
		_, _ = s.d.FS.Remove(tmp)
		if _, err := s.run(ctx, st, "fallocate", "-l", strconv.FormatInt(size, 10), s.d.FS.P(tmp)); err != nil {
			return CreateResult{}, err
		}
		if _, err := s.run(ctx, st, "mkfs.ext4", "-F", "-q", "-m", "0", "-L", "falak-"+id[len(id)-10:], s.d.FS.P(tmp)); err != nil {
			_, _ = s.d.FS.Remove(tmp)
			return CreateResult{}, err
		}
		if err := os.Rename(s.d.FS.P(tmp), s.d.FS.P(img)); err != nil {
			return CreateResult{}, err
		}
		created = true
		fmt.Fprintf(st.Stdout(), "created %d-byte ext4 image for volume %s\n", size, id)
	}
	if err := s.d.FS.MkdirAll(mp, 0o755); err != nil {
		return CreateResult{}, err
	}
	// The empty mountpoint (the host's directory, under the mount) is made immutable: nothing can write into it
	// while the volume is not mounted.
	if !s.d.Mounted(s.d.FS.P(mp)) {
		if _, err := s.run(ctx, st, "chattr", "+i", s.d.FS.P(mp)); err != nil {
			return CreateResult{}, err
		}
	}
	changed, err := s.d.FS.WriteFile(s.unitPath(id), []byte(s.unit(id)), 0o644)
	if err != nil {
		return CreateResult{}, err
	}
	if changed {
		if _, err := s.run(ctx, st, "systemctl", "daemon-reload"); err != nil {
			return CreateResult{}, err
		}
	}
	if _, err := s.run(ctx, st, "systemctl", "enable", "--now", unit); err != nil {
		return CreateResult{}, err
	}
	actual := size
	if fi, err := os.Stat(s.d.FS.P(img)); err == nil {
		actual = fi.Size()
	}
	return CreateResult{Path: mp, SizeBytes: actual, Created: created}, nil
}

// ResizePayload is volume.resize.
type ResizePayload struct {
	Volume    Ref   `json:"volume"`
	SizeBytes int64 `json:"size_bytes"`
}

// ResizeResult is its result.
type ResizeResult struct {
	SizeBytes     int64 `json:"size_bytes"`
	PreviousBytes int64 `json:"previous_bytes"`
	Grown         bool  `json:"grown"`
}

var loopRe = regexp.MustCompile(`^(/dev/loop[0-9]+):`)

// Resize grows a sized volume online: the image file first, then the loop device's size, then the filesystem.
// Shrinking is refused (ext4 cannot shrink online, and data could be lost). An image already at the size still
// gets the loop device and filesystem grown: a resize that failed half way is retried by sending it again.
func (s *Service) Resize(ctx context.Context, p ResizePayload, st commands.Stream) (any, error) {
	if err := s.check(p.Volume); err != nil {
		return nil, err
	}
	if p.Volume.Kind != KindSized {
		return nil, payloadErr("only sized volumes can be resized")
	}
	if p.SizeBytes < MinSize || p.SizeBytes > MaxSize {
		return nil, payloadErr("size_bytes must be between 16 MiB and 16 TiB")
	}
	img := s.d.FS.P(s.image(p.Volume.ID))
	fi, err := os.Stat(img)
	if err != nil {
		return nil, fmt.Errorf("volume %s: %w", p.Volume.ID, err)
	}
	prev := fi.Size()
	switch {
	case p.SizeBytes < prev:
		return nil, payloadErr("volume %s is %d bytes: shrinking to %d is refused", p.Volume.ID, prev, p.SizeBytes)
	case p.SizeBytes > prev:
		if _, err := s.run(ctx, st, "fallocate", "-l", strconv.FormatInt(p.SizeBytes, 10), img); err != nil {
			return nil, err
		}
	}
	res, err := s.run(ctx, st, "losetup", "-j", img)
	if err != nil {
		return nil, err
	}
	if m := loopRe.FindStringSubmatch(strings.TrimSpace(string(res.Stdout))); m != nil {
		if _, err := s.run(ctx, st, "losetup", "-c", m[1]); err != nil {
			return nil, err
		}
		if _, err := s.run(ctx, st, "resize2fs", m[1]); err != nil {
			return nil, err
		}
	} else {
		// Not mounted: an offline resize needs a clean check first (exit 1: errors were corrected).
		res, err := s.d.Runner.Run(ctx, runner.Cmd{Name: "e2fsck", Args: []string{"-f", "-p", img}, Stderr: st.Stderr()})
		if err == nil && res.ExitCode > 1 {
			err = &runner.ExitError{Cmd: "e2fsck -f -p " + img, Code: res.ExitCode, Stderr: string(res.Stderr)}
		}
		if err != nil {
			return nil, err
		}
		if _, err := s.run(ctx, st, "resize2fs", img); err != nil {
			return nil, err
		}
	}
	fmt.Fprintf(st.Stdout(), "volume %s grown from %d to %d bytes\n", p.Volume.ID, prev, p.SizeBytes)
	return ResizeResult{SizeBytes: p.SizeBytes, PreviousBytes: prev, Grown: p.SizeBytes > prev}, nil
}

// DeletePayload is volume.delete.
type DeletePayload struct {
	Volume Ref  `json:"volume"`
	WaitS  int  `json:"wait_s,omitempty"`
	Force  bool `json:"force,omitempty"`
}

// DeleteResult is its result.
type DeleteResult struct {
	Deleted bool `json:"deleted"`
	Existed bool `json:"existed"`
}

// Delete removes a volume and its data. Running containers that mount it block the delete (after waiting up to
// wait_s for them to go away) unless force. Bind paths are host data Falak doesn't own: they are only forgotten.
func (s *Service) Delete(ctx context.Context, p DeletePayload, st commands.Stream) (any, error) {
	r := p.Volume
	if err := s.check(r); err != nil {
		return nil, err
	}
	if p.WaitS < 0 || p.WaitS > 600 {
		return nil, payloadErr("wait_s must be 0–600")
	}
	hp, exists, err := s.hostPath(ctx, r)
	if err != nil {
		return nil, err
	}
	if r.Kind == KindSized {
		exists = exists || s.d.FS.Exists(s.unitPath(r.ID))
	}
	if !exists {
		return DeleteResult{}, nil
	}
	if r.Kind == KindBind {
		fmt.Fprintf(st.Stdout(), "bind path %s is kept on the host\n", r.Path)
		return DeleteResult{Existed: true}, nil
	}
	deadline := time.Now().Add(time.Duration(p.WaitS) * time.Second)
	for {
		users, err := s.users(ctx, r, hp)
		if err != nil {
			return nil, err
		}
		if len(users) == 0 || p.Force {
			if len(users) > 0 {
				fmt.Fprintf(st.Stderr(), "deleting volume %s although %s mount it (force)\n", r.ID, strings.Join(names(users), ", "))
			}
			break
		}
		if !time.Now().Before(deadline) {
			return nil, fmt.Errorf("volume %s is in use by %s", r.ID, strings.Join(names(users), ", "))
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(s.d.Poll):
		}
	}
	switch r.Kind {
	case KindDocker:
		existed, err := s.d.Docker.VolumeRemove(ctx, r.Name)
		if err != nil {
			return nil, fmt.Errorf("remove docker volume %s: %w", r.Name, err)
		}
		return DeleteResult{Deleted: existed, Existed: existed}, nil
	case KindSized:
		return s.deleteSized(ctx, r.ID, p.Force, st)
	default: // shared_path: Falak's own directory under the site, removed through its parent's handle (no symlink is
		// followed, even one swapped in while delete waited)
		parent, base, err := s.openParent(r)
		if err != nil {
			return nil, err
		}
		defer parent.Close()
		if fi, err := parent.Lstat(base); err == nil && fi.Mode()&fs.ModeSymlink != 0 {
			return nil, fmt.Errorf("%s %w", r.Path, errSymlink)
		}
		if err := parent.RemoveAll(base); err != nil {
			return nil, err
		}
		return DeleteResult{Deleted: true, Existed: true}, nil
	}
}

func (s *Service) deleteSized(ctx context.Context, id string, force bool, st commands.Stream) (DeleteResult, error) {
	mp, unit := s.mountpoint(id), unitName(s.mountpoint(id))
	if force && s.d.Mounted(s.d.FS.P(mp)) {
		if _, err := s.run(ctx, st, "umount", "-l", s.d.FS.P(mp)); err != nil {
			return DeleteResult{}, err
		}
	}
	if s.d.FS.Exists(s.unitPath(id)) {
		if _, err := s.run(ctx, st, "systemctl", "disable", "--now", unit); err != nil {
			return DeleteResult{}, err
		}
		if _, err := s.d.FS.Remove(s.unitPath(id)); err != nil {
			return DeleteResult{}, err
		}
		if _, err := s.run(ctx, st, "systemctl", "daemon-reload"); err != nil {
			return DeleteResult{}, err
		}
	}
	if s.d.Mounted(s.d.FS.P(mp)) {
		return DeleteResult{}, fmt.Errorf("volume %s is still mounted", id)
	}
	if _, err := s.d.FS.Remove(s.image(id)); err != nil {
		return DeleteResult{}, err
	}
	// The mountpoint is an empty, immutable directory once unmounted (never RemoveAll: it would be the volume's data).
	if s.d.FS.Exists(mp) {
		if _, err := s.run(ctx, st, "chattr", "-i", s.d.FS.P(mp)); err != nil {
			return DeleteResult{}, err
		}
	}
	if err := os.Remove(s.d.FS.P(mp)); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return DeleteResult{}, err
	}
	fmt.Fprintf(st.Stdout(), "deleted volume %s\n", id)
	return DeleteResult{Deleted: true, Existed: true}, nil
}

// InventoryPayload is volume.inventory.
type InventoryPayload struct {
	Volumes []Ref `json:"volumes,omitempty"`
	Docker  *bool `json:"docker,omitempty"`
}

// VolumeUsage is one volume of the inventory.
type VolumeUsage struct {
	ID             string   `json:"id"`
	Kind           string   `json:"kind"`
	Exists         bool     `json:"exists"`
	UsedBytes      *int64   `json:"used_bytes,omitempty"`
	SizeBytes      *int64   `json:"size_bytes,omitempty"`
	AvailableBytes *int64   `json:"available_bytes,omitempty"`
	Mounted        *bool    `json:"mounted,omitempty"`
	Containers     []string `json:"containers,omitempty"`
	Error          string   `json:"error,omitempty"`
}

// DockerVolume is one of the server's Docker volumes.
type DockerVolume struct {
	Name       string            `json:"name"`
	Driver     string            `json:"driver,omitempty"`
	Labels     map[string]string `json:"labels,omitempty"`
	Containers []string          `json:"containers,omitempty"`
}

// InventoryResult is its result.
type InventoryResult struct {
	Volumes    []VolumeUsage  `json:"volumes"`
	Docker     []DockerVolume `json:"docker,omitempty"`
	DurationMS int64          `json:"duration_ms"`
}

// UsageTimeout caps how long one volume's du-style walk may take.
var UsageTimeout = 20 * time.Second

// Inventory reports the usage of the given volumes (statfs for sized volumes, a du-style walk that never follows
// symlinks for the others) and, unless docker=false, every Docker volume on the server.
func (s *Service) Inventory(ctx context.Context, p InventoryPayload, st commands.Stream) (any, error) {
	start := time.Now()
	if len(p.Volumes) > 1000 {
		return nil, payloadErr("at most 1000 volumes")
	}
	for _, r := range p.Volumes {
		if err := s.check(r); err != nil {
			return nil, err
		}
	}
	var running []containerMounts
	if s.d.Docker != nil {
		list, err := s.d.Docker.ContainerList(ctx, false, nil)
		if err != nil {
			return nil, fmt.Errorf("list containers: %w", err)
		}
		for _, c := range list {
			name := c.ID
			if len(c.Names) > 0 {
				name = strings.TrimPrefix(c.Names[0], "/")
			}
			running = append(running, containerMounts{name: name, mounts: c.Mounts})
		}
	}
	res := InventoryResult{Volumes: []VolumeUsage{}}
	for _, r := range p.Volumes {
		res.Volumes = append(res.Volumes, s.usage(ctx, r, running))
	}
	if (p.Docker == nil || *p.Docker) && s.d.Docker != nil {
		vols, err := s.d.Docker.VolumeList(ctx)
		if err != nil {
			return nil, fmt.Errorf("list docker volumes: %w", err)
		}
		for _, v := range vols {
			dv := DockerVolume{Name: v.Name, Driver: v.Driver, Labels: v.Labels}
			for _, c := range running {
				for _, m := range c.mounts {
					if m.Name == v.Name {
						dv.Containers = append(dv.Containers, c.name)
						break
					}
				}
			}
			res.Docker = append(res.Docker, dv)
		}
	}
	res.DurationMS = time.Since(start).Milliseconds()
	return res, nil
}

type containerMounts struct {
	name   string
	mounts []docker.MountPoint
}

func (s *Service) usage(ctx context.Context, r Ref, running []containerMounts) VolumeUsage {
	u := VolumeUsage{ID: r.ID, Kind: r.Kind}
	hp, ok, err := s.hostPath(ctx, r)
	if err != nil {
		u.Error = err.Error()
		return u
	}
	u.Exists = ok
	if !ok {
		return u
	}
	for _, c := range running {
		for _, m := range c.mounts {
			if (r.Kind == KindDocker && m.Name == r.Name) || (r.Kind != KindDocker && (m.Source == hp || strings.HasPrefix(m.Source, hp+"/"))) {
				u.Containers = append(u.Containers, c.name)
				break
			}
		}
	}
	if r.Kind == KindSized {
		m := s.d.Mounted(s.d.FS.P(hp))
		u.Mounted = &m
		if !m {
			u.Error = "not mounted"
			return u
		}
		us, err := s.d.StatFS(s.d.FS.P(hp))
		if err != nil {
			u.Error = err.Error()
			return u
		}
		size, avail, used := int64(us.Size), int64(us.Available), int64(us.Used)
		u.SizeBytes, u.AvailableBytes, u.UsedBytes = &size, &avail, &used
		return u
	}
	root, _, err := s.openRoot(ctx, r)
	if err != nil {
		u.Error = err.Error()
		return u
	}
	defer root.Close()
	used, err := du(ctx, root, UsageTimeout)
	u.UsedBytes = &used
	if err != nil {
		u.Error = err.Error()
	}
	return u
}

// du sums the sizes of the regular files of a volume (or one of its directories) without following symlinks, for
// at most limit.
func du(ctx context.Context, root *os.Root, limit time.Duration) (int64, error) {
	deadline := time.Now().Add(limit)
	var total int64
	n := 0
	err := fs.WalkDir(root.FS(), ".", func(name string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil // unreadable entries are skipped
		}
		if n++; n%1024 == 0 {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			if time.Now().After(deadline) {
				return errUsageTimeout
			}
		}
		if d.Type().IsRegular() {
			if fi, err := root.Lstat(name); err == nil { // through the root: DirEntry.Info isn't, on Linux
				total += fi.Size()
			}
		}
		return nil
	})
	return total, err
}

var errUsageTimeout = errors.New("usage timed out")
