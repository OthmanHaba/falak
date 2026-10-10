package dbhelper

import (
	"context"
	"fmt"
	"strconv"
	"strings"
)

// Stats is `falak-db stats`: the open client connections and the server's limit ({"connections": n,
// "max_connections": m}), polled by the agent for the heartbeat (the control plane alerts above 80%). Postgres counts
// pg_stat_activity's client backends; MySQL/MariaDB report Threads_connected; Redis/Valkey connected_clients and
// maxclients.
func (h *Helper) Stats(ctx context.Context) error {
	used, limit, err := h.connections(ctx)
	if err != nil {
		return err
	}
	return h.result(map[string]any{"connections": used, "max_connections": limit})
}

func (h *Helper) connections(ctx context.Context) (int64, int64, error) {
	switch {
	case h.Engine == Postgres:
		out, err := h.pgExec(ctx, "postgres", "SELECT (SELECT count(*) FROM pg_stat_activity WHERE backend_type = 'client backend') || '|' || current_setting('max_connections');")
		if err != nil {
			return 0, 0, err
		}
		return pair(out, "|")
	case h.Engine.mysqlFamily():
		out, err := h.myExec(ctx, "SELECT VARIABLE_VALUE, @@max_connections FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Threads_connected';")
		if err != nil {
			return 0, 0, err
		}
		return pair(out, "\t")
	case h.Engine.kv():
		clients, err := h.kvCmd(ctx, "INFO", "clients")
		if err != nil {
			return 0, 0, err
		}
		limit, err := h.kvCmd(ctx, "CONFIG", "GET", "maxclients")
		if err != nil {
			return 0, 0, err
		}
		used, ok := infoField(clients, "connected_clients")
		l := lines(limit)
		if !ok || len(l) < 2 {
			return 0, 0, fmt.Errorf("stats: unexpected output %q", firstLine(clients))
		}
		max, err := strconv.ParseInt(strings.TrimSpace(l[1]), 10, 64)
		if err != nil {
			return 0, 0, fmt.Errorf("stats: maxclients %q", l[1])
		}
		return used, max, nil
	}
	return 0, 0, unsupported(h.Engine, "stats")
}

func pair(out, sep string) (int64, int64, error) {
	a, b, ok := strings.Cut(strings.TrimSpace(firstLine(out)), sep)
	if !ok {
		return 0, 0, fmt.Errorf("stats: unexpected output %q", firstLine(out))
	}
	x, err1 := strconv.ParseInt(strings.TrimSpace(a), 10, 64)
	y, err2 := strconv.ParseInt(strings.TrimSpace(b), 10, 64)
	if err1 != nil || err2 != nil {
		return 0, 0, fmt.Errorf("stats: unexpected output %q", firstLine(out))
	}
	return x, y, nil
}

// infoField reads "name:value" from Redis INFO output.
func infoField(info, name string) (int64, bool) {
	for _, l := range lines(info) {
		if k, v, ok := strings.Cut(l, ":"); ok && k == name {
			n, err := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			return n, err == nil
		}
	}
	return 0, false
}
