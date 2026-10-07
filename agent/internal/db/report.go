package db

import (
	"context"
	"sort"
	"strings"
)

// InstanceReport is one database container in the heartbeat (`databases`).
type InstanceReport struct {
	ID     string `json:"id"`
	State  string `json:"state"`
	Health string `json:"health"`
	// SecretsMissing: the secrets directory is gone (a reboot emptied /run), so the container cannot start until the
	// control plane sends db.instance.secrets.
	SecretsMissing bool `json:"secrets_missing"`
}

// Report lists the database containers on the server, for the heartbeat (nil when there are none or Docker is not
// reachable).
func (db *DB) Report(ctx context.Context) []InstanceReport {
	if db.d.Docker == nil {
		return nil
	}
	list, err := db.d.Docker.ContainerList(ctx, true, []string{LabelInstance})
	if err != nil {
		return nil
	}
	var out []InstanceReport
	for _, c := range list {
		id := c.Labels[LabelInstance]
		if !idRe.MatchString(id) {
			continue
		}
		out = append(out, InstanceReport{ID: id, State: reportState(c.State), Health: healthFromStatus(c.Status), SecretsMissing: db.secretsMissing(id)})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	if len(out) > 200 {
		out = out[:200]
	}
	return out
}

func reportState(s string) string {
	switch s {
	case "running", "exited", "created", "restarting", "paused", "dead":
		return s
	}
	return "missing"
}

// healthFromStatus reads the health from a container list entry's Status ("Up 3 minutes (healthy)").
func healthFromStatus(status string) string {
	switch {
	case strings.Contains(status, "(healthy)"):
		return "healthy"
	case strings.Contains(status, "(unhealthy)"):
		return "unhealthy"
	case strings.Contains(status, "(health: starting)"):
		return "starting"
	}
	return "none"
}
