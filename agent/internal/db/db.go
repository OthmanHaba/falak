// Package db implements the db.* commands. Every managed database is a container (`falak-db-<instance>`) of a Falak
// database image; the agent drives it through the Docker Engine API and `docker exec <ctr> falak-db <op>`
// (docs/DB_IMAGES.md). Passwords reach the containers as files on the tmpfs (/run/falak/secrets/falak-db-<id>), never
// in an environment value, an argument or a label.
package db

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Docker is the subset of the Engine API the db commands use (*docker.Client; a fake in tests).
type Docker interface {
	ImagePull(ctx context.Context, ref string, auth *docker.Auth, w io.Writer) error
	ImageRepoDigests(ctx context.Context, ref string) ([]string, bool, error)
	ImageInspect(ctx context.Context, ref string) (id string, exists bool, err error)
	ContainerInspect(ctx context.Context, nameOrID string) (*docker.Container, bool, error)
	ContainerList(ctx context.Context, all bool, labels []string) ([]docker.ContainerSummary, error)
	ContainerCreate(ctx context.Context, name string, body docker.CreateBody) (string, error)
	ContainerStart(ctx context.Context, id string) error
	ContainerStop(ctx context.Context, id string, timeout time.Duration) (bool, error)
	ContainerRestart(ctx context.Context, id string, timeout time.Duration) error
	ContainerRemove(ctx context.Context, id string) error
	ContainerLogs(ctx context.Context, id string, follow bool, tail int, w io.Writer) error
	NetworkExists(ctx context.Context, name string) (bool, error)
	NetworkCreate(ctx context.Context, name string, labels map[string]string) error
	NetworkConnect(ctx context.Context, network, container string, aliases []string) error
	NetworkDisconnect(ctx context.Context, network, container string) error
}

// Deps are the collaborators of db executors.
type Deps struct {
	Runner runner.Runner // `docker exec` / `docker run` (streams)
	Docker Docker
	FS     hostfs.FS
	Logger *slog.Logger
	HTTP   *http.Client
	// VolumesRoot holds sized volumes: <VolumesRoot>/<volume id> is the mountpoint (default /var/lib/falak/volumes).
	VolumesRoot string
	// SecretsDir holds the containers' secret files on the tmpfs (default /run/falak/secrets).
	SecretsDir string
	// EtcDir holds the instances' TLS files under db/<id>/tls (default /etc/falak).
	EtcDir  string
	TempDir string // real path for backup staging; default os.TempDir()
	// Mounted reports whether a host path is a mountpoint (default: /proc/self/mountinfo).
	Mounted func(path string) bool
	// Waits (tests shorten them).
	VolumeWait time.Duration // for the data volume to be mounted (default 120 s)
	HealthWait time.Duration // for the container to be healthy (default 300 s)
	Poll       time.Duration // default 2 s
}

// DB holds the executors.
type DB struct{ d Deps }

// New builds db executors.
func New(d Deps) *DB {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = http.DefaultClient
	}
	if d.VolumesRoot == "" {
		d.VolumesRoot = "/var/lib/falak/volumes"
	}
	if d.SecretsDir == "" {
		d.SecretsDir = docker.DefaultSecretsDir
	}
	if d.EtcDir == "" {
		d.EtcDir = "/etc/falak"
	}
	if d.TempDir == "" {
		d.TempDir = os.TempDir()
	}
	if d.Mounted == nil {
		d.Mounted = mounted
	}
	if d.VolumeWait == 0 {
		d.VolumeWait = 120 * time.Second
	}
	if d.HealthWait == 0 {
		d.HealthWait = 300 * time.Second
	}
	if d.Poll == 0 {
		d.Poll = 2 * time.Second
	}
	return &DB{d: d}
}

// Register adds db.* executors.
func (db *DB) Register(reg *commands.Registry) {
	reg.Register("db.instance.create", commands.Typed(db.InstanceCreate))
	reg.Register("db.instance.update", commands.Typed(db.InstanceUpdate))
	reg.Register("db.instance.restart", commands.Typed(db.InstanceRestart))
	reg.Register("db.instance.stop", commands.Typed(db.InstanceStop))
	reg.Register("db.instance.delete", commands.Typed(db.InstanceDelete))
	reg.Register("db.instance.password", commands.Typed(db.InstancePassword))
	reg.Register("db.instance.secrets", commands.Typed(db.InstanceSecrets))
	reg.Register("db.instance.upgrade", commands.Typed(db.InstanceUpgrade))
	reg.Register("db.create", commands.Typed(db.Create))
	reg.Register("db.drop", commands.Typed(db.Drop))
	reg.Register("db.user.apply", commands.Typed(db.UserApply))
	reg.Register("db.backup", commands.Typed(db.Backup))
	reg.Register("db.restore", commands.Typed(db.Restore))
}

// Labels of database containers.
const (
	LabelInstance = "falak.db.instance"
	LabelEngine   = "falak.db.engine"
)

var (
	idRe      = regexp.MustCompile(`^[0-9a-z]{26}$`)
	identRe   = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,62}$`)
	envNetRe  = regexp.MustCompile(`^falak-env-[0-9a-z]{26}$`)
	aliasRe   = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{0,62}$`)
	versionRe = regexp.MustCompile(`^[0-9]+(\.[0-9]+)?$`)
	digestRe  = regexp.MustCompile(`^sha256:[0-9a-f]{64}$`)
)

func payloadErr(format string, a ...any) error {
	return &commands.PayloadError{Err: fmt.Errorf(format, a...)}
}

// Container is an instance's container name (also its default DNS alias).
func Container(id string) string { return "falak-db-" + id }

func checkID(field, id string) error {
	if !idRe.MatchString(id) {
		return payloadErr("invalid %s %q", field, id)
	}
	return nil
}

func checkEngine(engine string) error {
	switch engine {
	case "postgres", "mysql", "mariadb", "redis", "valkey":
		return nil
	}
	return payloadErr("unknown engine %q", engine)
}

func isKeyValue(engine string) bool { return engine == "redis" || engine == "valkey" }

func checkIdent(kind, s string) error {
	if !identRe.MatchString(s) {
		return payloadErr("invalid %s %q", kind, s)
	}
	return nil
}

// mounted reports whether path is a mountpoint (/proc/self/mountinfo field 5).
func mounted(path string) bool {
	b, err := os.ReadFile("/proc/self/mountinfo")
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) > 4 && strings.NewReplacer(`\040`, " ", `\011`, "\t", `\012`, "\n", `\134`, `\`).Replace(f[4]) == path {
			return true
		}
	}
	return false
}

// sleep waits d or until ctx ends.
func sleep(ctx context.Context, d time.Duration) error {
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-time.After(d):
		return nil
	}
}
