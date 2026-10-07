package provision

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// DockerPlan is provision.apply `docker`: daemon settings Falak needs.
type DockerPlan struct {
	// LiveRestore keeps containers running while dockerd restarts or is upgraded (database containers).
	LiveRestore bool `json:"live_restore"`
}

// DaemonConfigPath is the Docker daemon's configuration file.
const DaemonConfigPath = "/etc/docker/daemon.json"

// MergeLiveRestore sets "live-restore": true in a daemon.json document, keeping every other key. changed is false when
// it is already set (the file is then left byte for byte). An invalid document is an error, never overwritten.
func MergeLiveRestore(cur []byte) (out []byte, changed bool, err error) {
	doc := map[string]any{}
	if len(bytes.TrimSpace(cur)) > 0 {
		if err := json.Unmarshal(cur, &doc); err != nil {
			return nil, false, fmt.Errorf("%s is not a JSON object (fix it by hand, then provision again): %w", DaemonConfigPath, err)
		}
	}
	if v, ok := doc["live-restore"].(bool); ok && v {
		return cur, false, nil
	}
	doc["live-restore"] = true
	b, err := json.MarshalIndent(doc, "", "  ")
	if err != nil {
		return nil, false, err
	}
	return append(b, '\n'), true, nil
}

// dockerLiveRestore merges live-restore into daemon.json and reloads dockerd (a SIGHUP applies it, no restart).
func (p *Provisioner) dockerLiveRestore(ctx context.Context, st commands.Stream) (bool, error) {
	cur, err := p.d.FS.ReadFile(DaemonConfigPath)
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return false, err
	}
	out, changed, err := MergeLiveRestore(cur)
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
