// Package inspect implements provision.inspect: a read-only report of the software already on a machine (packages
// and where they came from, Docker, databases, listening ports, SSH, firewall, swap, runtimes), so the control plane
// can decide per component whether to install, adopt, complete or block before provision.apply runs.
//
// Every detector degrades on its own: a missing tool or unreadable file leaves its part of the report empty and adds
// an entry to Report.Errors; the command itself only fails on cancellation.
package inspect

import (
	"context"
	"fmt"
	"log/slog"
	"strings"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/facts"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/system"
)

// ReportVersion is the shape version of Report (bumped on incompatible changes).
const ReportVersion = 1

// Deps are the collaborators of provision.inspect.
type Deps struct {
	Runner runner.Runner
	FS     hostfs.FS
	Logger *slog.Logger
	// OwnerUID must own the binaries the inspector executes (0, root; tests use their own uid).
	OwnerUID int
}

// Inspector runs provision.inspect.
type Inspector struct{ d Deps }

// New builds the inspector.
func New(d Deps) *Inspector {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	return &Inspector{d: d}
}

// Register adds provision.inspect.
func (in *Inspector) Register(reg *commands.Registry) {
	reg.Register("provision.inspect", commands.Typed(in.Inspect))
}

// Payload is the provision.inspect payload.
type Payload struct {
	// Packages are extra dpkg-query patterns (names or globs) to report besides the built-in list, e.g. the base
	// packages of the plan.
	Packages []string `json:"packages"`
}

// Report is the provision.inspect result (commands/provision.inspect.schema.json $defs.result).
type Report struct {
	Version            int                `json:"version"`
	Hostname           string             `json:"hostname"`
	OS                 OS                 `json:"os"`
	InContainer        bool               `json:"in_container"`
	Packages           []Package          `json:"packages"`
	Snaps              []Snap             `json:"snaps"`
	AptSources         []system.AptSource `json:"apt_sources"`
	Services           []Service          `json:"services"`
	Listeners          []Listener         `json:"listeners"`
	Containers         []Container        `json:"containers"`
	Docker             *Docker            `json:"docker"`
	SSH                SSH                `json:"ssh"`
	Firewall           Firewall           `json:"firewall"`
	Swap               []Swap             `json:"swap"`
	Node               []Binary           `json:"node"`
	PHP                []Binary           `json:"php"`
	FrankenPHP         []Binary           `json:"frankenphp"`
	UnattendedUpgrades Unattended         `json:"unattended_upgrades"`
	Fail2ban           Fail2ban           `json:"fail2ban"`
	Errors             []DetectorError    `json:"errors"`
}

// OS identifies the distribution.
type OS struct {
	ID       string `json:"id"`
	Version  string `json:"version"`
	Codename string `json:"codename"`
}

// DetectorError is a detector that could not complete.
type DetectorError struct {
	Detector string `json:"detector"`
	Error    string `json:"error"`
}

// Origins of an installed package.
const (
	OriginArchive = "archive" // the distribution's archive (Ubuntu / Debian, any mirror)
	OriginVendor  = "vendor"  // another apt repository (Docker, PGDG, a PPA, ...): Repo names it
	OriginManual  = "manual"  // installed from a .deb or a repository that is no longer configured
	OriginUnknown = "unknown" // apt has no package lists yet (or apt-cache failed): the source cannot be told
)

// Package is one installed dpkg package and where its installed version comes from.
type Package struct {
	Name    string `json:"name"`
	Version string `json:"version"`
	Origin  string `json:"origin"`
	Repo    string `json:"repo,omitempty"`  // repository URL (vendor and archive)
	Label   string `json:"label,omitempty"` // the repository's Origin field, e.g. "Docker", "apt.postgresql.org", "LP-PPA-ondrej-php"
}

// Snap is one installed snap.
type Snap struct {
	Name    string `json:"name"`
	Version string `json:"version"`
	Channel string `json:"channel,omitempty"`
}

// Service is the state of one systemd unit that exists on the machine.
type Service struct {
	Unit    string `json:"unit"`
	Active  string `json:"active"`  // ActiveState: active, inactive, failed, ...
	Enabled string `json:"enabled"` // UnitFileState: enabled, disabled, masked, static, ...
}

// Listener is one listening TCP socket.
type Listener struct {
	Port      int    `json:"port"`
	Address   string `json:"address"`
	Process   string `json:"process,omitempty"`
	PID       int    `json:"pid,omitempty"`
	Unit      string `json:"unit,omitempty"`      // the systemd unit of the process (from its cgroup)
	Container bool   `json:"container,omitempty"` // a container runtime's port proxy (docker-proxy, rootlessport, ...)
}

// Container is one running container and its published ports.
type Container struct {
	Name  string          `json:"name"`
	Image string          `json:"image"`
	Ports []PublishedPort `json:"ports"`
}

// PublishedPort is a host port published by a container.
type PublishedPort struct {
	HostIP        string `json:"host_ip,omitempty"`
	HostPort      int    `json:"host_port"`
	ContainerPort int    `json:"container_port"`
	Protocol      string `json:"protocol"`
}

// Binary is one runtime binary found on the machine.
type Binary struct {
	Path    string `json:"path"`
	Version string `json:"version,omitempty"`
	// Source: kiln, archive, vendor, nodesource, nvm, snap, manual.
	Source  string `json:"source"`
	Package string `json:"package,omitempty"`
	Repo    string `json:"repo,omitempty"`
}

// Inspect builds the report. It never changes the machine.
func (in *Inspector) Inspect(ctx context.Context, p Payload, st commands.Stream) (any, error) {
	r := &Report{Version: ReportVersion, Packages: []Package{}, Snaps: []Snap{}, Services: []Service{}, Listeners: []Listener{},
		Containers: []Container{}, Swap: []Swap{}, Node: []Binary{}, PHP: []Binary{}, FrankenPHP: []Binary{}, Errors: []DetectorError{},
		AptSources: []system.AptSource{},
		SSH:        SSH{DropIns: []SSHDropIn{}, Effective: map[string]string{}, EffectiveSource: "files", Users: []LoginUser{}},
		Firewall:   Firewall{UFW: "absent", Firewalld: "absent", Tables: []string{}}}
	run := func(name string, fn func() error) {
		if ctx.Err() != nil {
			return
		}
		fmt.Fprintf(st.Stdout(), "==> %s\n", name)
		if err := fn(); err != nil {
			msg := truncate(redact(err.Error()), 500)
			r.Errors = append(r.Errors, DetectorError{Detector: name, Error: msg})
			fmt.Fprintf(st.Stderr(), "%s: %s\n", name, msg)
		}
	}
	steps := []struct {
		name string
		fn   func() error
	}{
		{"host", func() error { return in.host(ctx, r) }},
		{"packages", func() error { return in.packages(ctx, r, p.Packages) }},
		{"snaps", func() error { return in.snaps(ctx, r) }},
		{"services", func() error { return in.services(ctx, r) }},
		{"listeners", func() error { return in.listeners(ctx, r) }},
		{"docker", func() error { return in.docker(ctx, r) }},
		{"ssh", func() error { return in.ssh(ctx, r) }},
		{"firewall", func() error { return in.firewall(ctx, r) }},
		{"swap", func() error { return in.swap(r) }},
		{"runtimes", func() error { return in.runtimes(ctx, r) }},
		{"unattended_upgrades", func() error { return in.unattended(r) }},
		{"fail2ban", func() error { return in.fail2ban(r) }},
	}
	for i, s := range steps {
		run(s.name, s.fn)
		st.Progress(float64(i+1) / float64(len(steps)))
	}
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	return r, nil
}

func (in *Inspector) host(ctx context.Context, r *Report) error {
	if b, err := in.d.FS.ReadFile("/etc/os-release"); err == nil {
		kv := facts.ParseOSRelease(b)
		r.OS = OS{ID: kv["ID"], Version: kv["VERSION_ID"], Codename: kv["VERSION_CODENAME"]}
	}
	for _, src := range system.AptSources(in.d.FS) {
		for i := range src.URIs {
			src.URIs[i] = redact(src.URIs[i])
		}
		r.AptSources = append(r.AptSources, src)
	}
	res, err := in.run(ctx, "systemd-detect-virt", "--container", "--quiet")
	r.InContainer = err == nil && res.ExitCode == 0
	if b, err := in.d.FS.ReadFile("/etc/hostname"); err == nil {
		r.Hostname = strings.TrimSpace(string(b))
	}
	if r.Hostname == "" {
		out, err := in.output(ctx, "hostname")
		if err != nil {
			return err
		}
		r.Hostname = strings.TrimSpace(out)
	}
	return nil
}

// exists reports whether a host path exists (following symlinks).
func (in *Inspector) exists(p string) bool { return in.d.FS.Exists(p) }

func truncate(s string, n int) string {
	s = strings.TrimSpace(s)
	if len(s) > n {
		return s[:n] + "…"
	}
	return s
}

// service returns the state of a unit, or nil when it does not exist.
func (r *Report) service(unit string) *Service {
	for i := range r.Services {
		if r.Services[i].Unit == unit {
			return &r.Services[i]
		}
	}
	return nil
}

// pkg returns an installed package, or nil.
func (r *Report) pkg(name string) *Package {
	for i := range r.Packages {
		if r.Packages[i].Name == name {
			return &r.Packages[i]
		}
	}
	return nil
}
