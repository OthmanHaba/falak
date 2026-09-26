package agent

import (
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/kiln/agent/internal/config"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
)

// UnitPath is where the systemd unit is installed.
const UnitPath = "/etc/systemd/system/kiln-agent.service"

// BinaryPath is the installed binary location.
const BinaryPath = "/usr/local/bin/kiln-agent"

// Unit renders the systemd unit. KillMode=mixed sends SIGTERM to the agent only, letting the built-in
// supervisor stop its programs gracefully (stop signals + timeouts) before systemd kills leftovers.
func Unit(binary string) string {
	return fmt.Sprintf(`[Unit]
Description=Kiln agent
Documentation=https://kiln.dev
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=%s run
EnvironmentFile=-/etc/kiln/agent.env
Restart=always
RestartSec=5
KillMode=mixed
TimeoutStopSec=90
LimitNOFILE=65536
RuntimeDirectory=kiln
RuntimeDirectoryPreserve=yes
StateDirectory=kiln
LogsDirectory=kiln
Environment=GOMEMLIMIT=24MiB

[Install]
WantedBy=multi-user.target
`, binary)
}

// InstallOptions for `kiln-agent install`.
type InstallOptions struct {
	Config  config.Config
	Source  string // path of the running binary (copied to BinaryPath)
	NoStart bool
	FS      hostfs.FS
	Runner  runner.Runner
	Out     io.Writer
}

// Install copies the binary, writes the env file + systemd unit and enables the service.
func Install(ctx context.Context, o InstallOptions) error {
	fs := o.FS
	if o.Source != "" && o.Source != fs.P(BinaryPath) {
		b, err := os.ReadFile(o.Source)
		if err != nil {
			return err
		}
		if _, err := fs.WriteFile(BinaryPath, b, 0o755); err != nil {
			return fmt.Errorf("install binary: %w", err)
		}
	}
	if err := fs.MkdirAll(o.Config.EtcDir, 0o711); err != nil {
		return err
	}
	var env strings.Builder
	add := func(k, v string) {
		if v != "" {
			fmt.Fprintf(&env, "%s=%s\n", k, v)
		}
	}
	add("KILN_PANEL_URL", o.Config.PanelURL)
	add("KILN_TOKEN", o.Config.Token)
	if o.Config.Insecure {
		add("KILN_INSECURE_ENROLL", "1")
	}
	envPath := filepath.Join(o.Config.EtcDir, "agent.env")
	if env.Len() > 0 {
		if _, err := fs.WriteFile(envPath, []byte(env.String()), 0o600); err != nil {
			return err
		}
	}
	changed, err := fs.WriteFile(UnitPath, []byte(Unit(BinaryPath)), 0o644)
	if err != nil {
		return err
	}
	for _, dir := range []string{o.Config.StateDir, o.Config.LogDir, o.Config.SitesRoot} {
		if err := fs.MkdirAll(dir, 0o755); err != nil {
			return err
		}
	}
	if changed {
		if _, err := runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return err
		}
	}
	if o.NoStart {
		_, err = runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"enable", "kiln-agent.service"}})
	} else {
		_, err = runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"enable", "--now", "kiln-agent.service"}})
		if err == nil {
			_, err = runner.Check(ctx, o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"restart", "kiln-agent.service"}})
		}
	}
	if err == nil && o.Out != nil {
		fmt.Fprintf(o.Out, "kiln-agent installed (%s)\n", UnitPath)
	}
	return err
}
