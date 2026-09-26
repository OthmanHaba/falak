package runtime

import (
	"context"
	"fmt"
	"strings"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/system"
)

// FrankenPHPPayload is runtime.frankenphp.configure.
type FrankenPHPPayload struct {
	Version string         `json:"version"`
	SHA256  string         `json:"sha256"`
	INI     map[string]any `json:"ini"`
	AsEdge  *bool          `json:"as_edge"`
}

// BinaryResult is {changed, binary}.
type BinaryResult struct {
	Changed bool   `json:"changed"`
	Binary  string `json:"binary"`
}

// Paths shared with the edge package.
const (
	FrankenPHPBinary = "/usr/local/bin/frankenphp"
	EdgeUnitPath     = "/etc/systemd/system/kiln-edge.service"
	EdgeBootstrap    = "/etc/kiln/caddy/bootstrap.json"
	frankenMarker    = "/etc/kiln/frankenphp.version"
)

func frankenArch(a string) (string, error) {
	switch a {
	case "amd64":
		return "x86_64", nil
	case "arm64":
		return "aarch64", nil
	}
	return "", fmt.Errorf("unsupported arch %s", a)
}

// FrankenPHPConfigure installs the static FrankenPHP binary, php.ini and (optionally) kiln-edge.service.
func (rt *Runtime) FrankenPHPConfigure(ctx context.Context, p FrankenPHPPayload, st commands.Stream) (any, error) {
	ver := strings.TrimPrefix(p.Version, "v")
	if !semver.MatchString(ver) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid version %q", p.Version)}
	}
	arch, err := frankenArch(rt.d.Arch)
	if err != nil {
		return nil, err
	}
	res := BinaryResult{Binary: FrankenPHPBinary}
	marker := []byte(ver + " " + p.SHA256 + "\n")
	cur, _ := rt.d.FS.ReadFile(frankenMarker)
	binOK := rt.d.FS.Exists(FrankenPHPBinary) && string(cur) == string(marker)
	if binOK && p.SHA256 != "" {
		if sum, err := system.FileSHA256(rt.d.FS.P(FrankenPHPBinary)); err != nil || sum != p.SHA256 {
			binOK = false
		}
	}
	binChanged := false
	if !binOK {
		url := fmt.Sprintf("%s/v%s/frankenphp-linux-%s", strings.TrimRight(rt.d.FrankenPHPBase, "/"), ver, arch)
		fmt.Fprintf(st.Stdout(), "downloading %s\n", url)
		if _, _, err := Download(ctx, rt.d.HTTP, url, p.SHA256, rt.d.FS.P(FrankenPHPBinary), 0o755, nil); err != nil {
			return nil, err
		}
		if _, err := rt.d.FS.WriteFile(frankenMarker, marker, 0o644); err != nil {
			return nil, err
		}
		binChanged = true
	}
	iniChanged, err := rt.d.FS.WriteFile("/etc/frankenphp/php.ini", RenderINI(p.INI), 0o644)
	if err != nil {
		return nil, err
	}
	res.Changed = binChanged || iniChanged
	if p.AsEdge == nil || *p.AsEdge {
		c, err := EnsureEdgeUnit(ctx, rt.d.Runner, rt.d.FS, st, EdgeUnit{Binary: FrankenPHPBinary, User: rt.d.EdgeUser, FrankenPHP: true}, binChanged || iniChanged)
		if err != nil {
			return nil, err
		}
		res.Changed = res.Changed || c
	}
	return res, nil
}

// EdgeUnit describes kiln-edge.service.
type EdgeUnit struct {
	Binary     string // /usr/bin/caddy or /usr/local/bin/frankenphp
	User       string // default caddy
	FrankenPHP bool
}

// RenderEdgeUnit renders the systemd unit.
func RenderEdgeUnit(u EdgeUnit) string {
	if u.User == "" {
		u.User = "caddy"
	}
	var env string
	if u.FrankenPHP {
		env = "Environment=PHPRC=/etc/frankenphp\n"
	}
	return fmt.Sprintf(`# Managed by Kiln
[Unit]
Description=Kiln edge (Caddy/FrankenPHP)
Documentation=https://caddyserver.com/docs/
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
User=%[1]s
Group=%[1]s
Environment=XDG_DATA_HOME=/var/lib/caddy
Environment=XDG_CONFIG_HOME=/var/lib/caddy/.config
%[3]sExecStart=%[2]s run --environ --config %[4]s
ExecReload=%[2]s reload --config %[4]s --force
TimeoutStopSec=5s
LimitNOFILE=1048576
PrivateTmp=true
ProtectSystem=full
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE
Restart=on-failure
RestartSec=2s

[Install]
WantedBy=multi-user.target
`, u.User, u.Binary, env, EdgeBootstrap)
}

// BootstrapConfig is the admin-only Caddy config written when no config exists yet. The edge package
// overwrites the file with the full applied config on every edge.caddy.apply.
const BootstrapConfig = `{"admin":{"listen":"localhost:2019"}}` + "\n"

// EnsureEdgeUnit converges kiln-edge.service (unit, service user, bootstrap config, running state).
// restart forces a restart (e.g. new binary) when the unit itself is unchanged.
func EnsureEdgeUnit(ctx context.Context, r runner.Runner, fs hostfs.FS, st commands.Stream, u EdgeUnit, restart bool) (bool, error) {
	if u.User == "" {
		u.User = "caddy"
	}
	run := func(name string, args ...string) error {
		_, err := runner.Check(ctx, r, runner.Cmd{Name: name, Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
		return err
	}
	changed := false
	ur, err := system.EnsureUser(ctx, r, fs, system.UserSpec{Name: u.User, System: true, Shell: "/usr/sbin/nologin", Home: "/var/lib/caddy"}, st)
	if err != nil {
		return false, err
	}
	changed = ur.Changed
	// Never overwrite: the edge package keeps the last applied full config here.
	if !fs.Exists(EdgeBootstrap) {
		if _, err := fs.WriteFile(EdgeBootstrap, []byte(BootstrapConfig), 0o644); err != nil {
			return false, err
		}
		changed = true
	}
	unitChanged, err := fs.WriteFile(EdgeUnitPath, []byte(RenderEdgeUnit(u)), 0o644)
	if err != nil {
		return false, err
	}
	if unitChanged {
		changed = true
		// The distro caddy.service (Caddyfile based) would fight for :80/:443.
		if res, err := r.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-enabled", "--quiet", "caddy.service"}}); err == nil && res.ExitCode == 0 {
			if err := run("systemctl", "disable", "--now", "caddy.service"); err != nil {
				return false, err
			}
		}
		if err := run("systemctl", "daemon-reload"); err != nil {
			return false, err
		}
		if err := run("systemctl", "enable", "kiln-edge.service"); err != nil {
			return false, err
		}
		return true, run("systemctl", "restart", "kiln-edge.service")
	}
	res, err := r.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-active", "--quiet", "kiln-edge.service"}})
	if err != nil {
		return false, err
	}
	if res.ExitCode != 0 {
		return true, run("systemctl", "enable", "--now", "kiln-edge.service")
	}
	if restart {
		return true, run("systemctl", "restart", "kiln-edge.service")
	}
	return changed, nil
}
