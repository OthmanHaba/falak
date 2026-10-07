package provision

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// DockerPlan is provision.apply `docker`: daemon settings Falak needs.
type DockerPlan struct {
	// LiveRestore keeps containers running while dockerd restarts or is upgraded (database containers).
	LiveRestore bool `json:"live_restore"`
	// MinVersion: Docker Engine this recent or newer, from Docker's apt repository (docker-ce) unless the server already
	// has it (dockerEngine).
	MinVersion string `json:"min_version,omitempty"`
}

// DaemonConfigPath is the Docker daemon's configuration file.
const DaemonConfigPath = "/etc/docker/daemon.json"

// MergeLiveRestore sets "live-restore": true in a daemon.json document, keeping every other key. changed is false when
// it is already set (the file is then left byte for byte). An explicit "live-restore": false is the administrator's
// choice: it is kept, with a warning. An invalid document is an error, never overwritten.
func MergeLiveRestore(cur []byte) (out []byte, changed bool, warning string, err error) {
	doc := map[string]any{}
	if len(bytes.TrimSpace(cur)) > 0 {
		if err := json.Unmarshal(cur, &doc); err != nil {
			return nil, false, "", fmt.Errorf("%s is not a JSON object (fix it by hand, then provision again): %w", DaemonConfigPath, err)
		}
	}
	switch v, ok := doc["live-restore"].(bool); {
	case ok && v:
		return cur, false, "", nil
	case ok && !v:
		return cur, false, fmt.Sprintf("%s sets live-restore to false: left as it is, so restarting or upgrading Docker restarts the database containers", DaemonConfigPath), nil
	}
	doc["live-restore"] = true
	b, err := json.MarshalIndent(doc, "", "  ")
	if err != nil {
		return nil, false, "", err
	}
	return append(b, '\n'), true, "", nil
}

// dockerLiveRestore merges live-restore into daemon.json and reloads dockerd (a SIGHUP applies it, no restart). A
// dockerd started with --live-restore already has it, and the same option in daemon.json would stop it from starting.
func (p *Provisioner) dockerLiveRestore(ctx context.Context, st commands.Stream) (bool, error) {
	if res, err := p.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"show", "docker", "--property=ExecStart"}}); err == nil &&
		strings.Contains(string(res.Stdout), "--live-restore") {
		fmt.Fprintln(st.Stdout(), "docker: live-restore set on dockerd's command line")
		return false, nil
	}
	cur, err := p.d.FS.ReadFile(DaemonConfigPath)
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return false, err
	}
	out, changed, warning, err := MergeLiveRestore(cur)
	if warning != "" {
		fmt.Fprintln(st.Stderr(), "warning: "+warning)
	}
	if err != nil || !changed {
		return false, err
	}
	if err := p.d.FS.MkdirAll("/etc/docker", 0o755); err != nil {
		return false, err
	}
	if _, err := p.d.FS.WriteFile(DaemonConfigPath, out, 0o644); err != nil {
		return false, err
	}
	fmt.Fprintln(st.Stdout(), "docker: live-restore on")
	if _, err := runner.Check(ctx, p.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"reload", "docker"}, Stdout: st.Stdout(), Stderr: st.Stderr()}); err != nil {
		return true, err
	}
	return true, nil
}
