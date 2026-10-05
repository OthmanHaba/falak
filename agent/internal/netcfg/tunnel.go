package netcfg

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// net.tunnel.apply — Cloudflare Tunnel: a pinned, checksum-verified cloudflared running the tunnel whose token the
// control plane passes. The token reaches cloudflared as a systemd credential; the service runs as a dynamic user.
const (
	CloudflaredBinary = "/usr/local/bin/cloudflared"
	TunnelUnitPath    = "/etc/systemd/system/falak-cloudflared.service"
	TunnelTokenPath   = "/etc/falak/cloudflared.token"
	tunnelMarker      = "/etc/falak/cloudflared.version"
	tunnelUnit        = "falak-cloudflared.service"
)

var (
	cloudflaredVersion = regexp.MustCompile(`^\d{4}\.\d{1,2}\.\d{1,3}$`)
	sha256Hex          = regexp.MustCompile(`^[0-9a-f]{64}$`)
)

// TunnelPayload is net.tunnel.apply.
type TunnelPayload struct {
	State   string `json:"state"` // present | absent
	Version string `json:"version,omitempty"`
	URL     string `json:"url,omitempty"`    // cloudflared-linux-<arch> of that release
	SHA256  string `json:"sha256,omitempty"` // of the binary at URL
	Token   string `json:"token,omitempty"`  // the tunnel's run token
}

// TunnelResult is {changed, active, version}.
type TunnelResult struct {
	Changed bool   `json:"changed"`
	Active  bool   `json:"active"`
	Version string `json:"version,omitempty"`
}

// RenderTunnelUnit is falak-cloudflared.service.
func RenderTunnelUnit() string {
	return `[Unit]
Description=Falak: Cloudflare Tunnel (cloudflared)
After=network-online.target
Wants=network-online.target

[Service]
DynamicUser=yes
LoadCredential=token:` + TunnelTokenPath + `
ExecStart=` + CloudflaredBinary + ` --no-autoupdate tunnel run --token-file ${CREDENTIALS_DIRECTORY}/token
Restart=always
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
`
}

// TunnelApply installs / updates / removes cloudflared for the server's tunnel.
func (n *Net) TunnelApply(ctx context.Context, p TunnelPayload, st commands.Stream) (any, error) {
	run := func(args ...string) error {
		res, err := n.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
		if err == nil && res.ExitCode != 0 {
			err = fmt.Errorf("systemctl %s: exit %d", strings.Join(args, " "), res.ExitCode)
		}
		return err
	}

	switch p.State {
	case "absent":
		changed := false
		if n.d.FS.Exists(TunnelUnitPath) {
			_ = run("disable", "--now", tunnelUnit)
			changed = true
		}
		for _, f := range []string{TunnelUnitPath, TunnelTokenPath, tunnelMarker, CloudflaredBinary} {
			if removed, err := n.d.FS.Remove(f); err != nil {
				return nil, err
			} else if removed {
				changed = true
			}
		}
		if changed {
			if err := run("daemon-reload"); err != nil {
				return nil, err
			}
		}
		return TunnelResult{Changed: changed}, nil
	case "present":
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("state must be present or absent")}
	}

	if !cloudflaredVersion.MatchString(p.Version) || !sha256Hex.MatchString(p.SHA256) || !strings.HasPrefix(p.URL, "https://") || p.Token == "" {
		return nil, &commands.PayloadError{Err: errors.New("version, https url, sha256 and token are required")}
	}

	marker := []byte(p.Version + " " + p.SHA256 + "\n")
	cur, _ := n.d.FS.ReadFile(tunnelMarker)
	binOK := n.d.FS.Exists(CloudflaredBinary) && string(cur) == string(marker)
	if binOK {
		if sum, err := system.FileSHA256(n.d.FS.P(CloudflaredBinary)); err != nil || sum != p.SHA256 {
			binOK = false
		}
	}
	changed := false
	if !binOK {
		fmt.Fprintf(st.Stdout(), "downloading cloudflared %s\n", p.Version)
		if _, _, err := system.Download(ctx, n.d.HTTP, p.URL, p.SHA256, n.d.FS.P(CloudflaredBinary), 0o755, nil); err != nil {
			return nil, err
		}
		if _, err := n.d.FS.WriteFile(tunnelMarker, marker, 0o644); err != nil {
			return nil, err
		}
		changed = true
	}
	if err := n.d.FS.MkdirAll("/etc/falak", 0o755); err != nil {
		return nil, err
	}
	tokenChanged, err := n.d.FS.WriteFile(TunnelTokenPath, []byte(strings.TrimSpace(p.Token)), 0o600)
	if err != nil {
		return nil, err
	}
	unitChanged, err := n.d.FS.WriteFile(TunnelUnitPath, []byte(RenderTunnelUnit()), 0o644)
	if err != nil {
		return nil, err
	}
	changed = changed || tokenChanged || unitChanged

	if unitChanged {
		if err := run("daemon-reload"); err != nil {
			return nil, err
		}
	}
	if err := run("enable", tunnelUnit); err != nil {
		return nil, err
	}
	if changed {
		if err := run("restart", tunnelUnit); err != nil {
			return nil, err
		}
	} else if err := run("start", tunnelUnit); err != nil {
		return nil, err
	}
	res, _ := n.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-active", "--quiet", tunnelUnit}})
	fmt.Fprintf(st.Stdout(), "cloudflared %s running the tunnel\n", p.Version)

	return TunnelResult{Changed: changed, Active: res.ExitCode == 0, Version: p.Version}, nil
}
