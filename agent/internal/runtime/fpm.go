package runtime

import (
	"context"
	"fmt"
	"sort"
	"strings"

	"github.com/kiln/agent/internal/commands"
)

// FPMPoolPayload is runtime.fpm.pool.
type FPMPoolPayload struct {
	PHPVersion      string            `json:"php_version"`
	Pool            string            `json:"pool"`
	User            string            `json:"user"`
	Group           string            `json:"group"`
	Listen          string            `json:"listen"`
	PM              string            `json:"pm"`
	MaxChildren     int               `json:"max_children"`
	StartServers    int               `json:"start_servers"`
	MinSpareServers int               `json:"min_spare_servers"`
	MaxSpareServers int               `json:"max_spare_servers"`
	MaxRequests     *int              `json:"max_requests"`
	PHPAdminValues  map[string]string `json:"php_admin_values"`
	Env             map[string]string `json:"env"`
	State           string            `json:"state"`
}

// ListenResult is {changed, listen}.
type ListenResult struct {
	Changed bool   `json:"changed"`
	Listen  string `json:"listen,omitempty"`
}

func def(v, d int) int {
	if v == 0 {
		return d
	}
	return v
}

// RenderPool renders a pool file; listenGroup is the edge user's group so Caddy can connect.
func RenderPool(p FPMPoolPayload, listenGroup string) string {
	group := p.Group
	if group == "" {
		group = p.User
	}
	pm := p.PM
	if pm == "" {
		pm = "dynamic"
	}
	maxReq := 500
	if p.MaxRequests != nil {
		maxReq = *p.MaxRequests
	}
	var b strings.Builder
	fmt.Fprintf(&b, "; Managed by Kiln — do not edit\n[kiln-%s]\nuser = %s\ngroup = %s\n", p.Pool, p.User, group)
	fmt.Fprintf(&b, "listen = %s\nlisten.owner = %s\nlisten.group = %s\nlisten.mode = 0660\n", p.Listen, p.User, listenGroup)
	fmt.Fprintf(&b, "pm = %s\npm.max_children = %d\n", pm, def(p.MaxChildren, 5))
	if pm == "dynamic" {
		fmt.Fprintf(&b, "pm.start_servers = %d\npm.min_spare_servers = %d\npm.max_spare_servers = %d\n",
			def(p.StartServers, 2), def(p.MinSpareServers, 1), def(p.MaxSpareServers, 3))
	}
	if pm == "ondemand" {
		b.WriteString("pm.process_idle_timeout = 10s\n")
	}
	fmt.Fprintf(&b, "pm.max_requests = %d\ncatch_workers_output = yes\ndecorate_workers_output = no\n", maxReq)
	for _, k := range sortedKeys(p.PHPAdminValues) {
		fmt.Fprintf(&b, "php_admin_value[%s] = %s\n", k, p.PHPAdminValues[k])
	}
	for _, k := range sortedKeys(p.Env) {
		fmt.Fprintf(&b, "env[%s] = \"%s\"\n", k, strings.ReplaceAll(p.Env[k], `"`, `\"`))
	}
	return b.String()
}

func sortedKeys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// FPMPool converges a per-site PHP-FPM pool.
func (rt *Runtime) FPMPool(ctx context.Context, p FPMPoolPayload, st commands.Stream) (any, error) {
	if p.Pool == "" || p.PHPVersion == "" || (p.User == "" && p.State != "absent") {
		return nil, &commands.PayloadError{Err: fmt.Errorf("php_version, pool and user are required")}
	}
	if p.Listen == "" {
		p.Listen = fmt.Sprintf("/run/php/kiln-%s-%s.sock", p.Pool, p.PHPVersion)
	}
	file := fmt.Sprintf("/etc/php/%s/fpm/pool.d/kiln-%s.conf", p.PHPVersion, p.Pool)
	svc := "php" + p.PHPVersion + "-fpm"
	if p.State == "absent" {
		removed, err := rt.d.FS.Remove(file)
		if err != nil || !removed {
			return ListenResult{}, err
		}
		return ListenResult{Changed: true}, rt.run(ctx, st, "systemctl", "reload", svc)
	}
	if !rt.d.FS.Exists(fmt.Sprintf("/etc/php/%s/fpm", p.PHPVersion)) {
		return nil, fmt.Errorf("php%s-fpm is not installed", p.PHPVersion)
	}
	old, oldErr := rt.d.FS.ReadFile(file)
	changed, err := rt.d.FS.WriteFile(file, []byte(RenderPool(p, rt.d.EdgeUser)), 0o644)
	if err != nil {
		return nil, err
	}
	res := ListenResult{Changed: changed, Listen: p.Listen}
	if !changed {
		return res, nil
	}
	if err := rt.run(ctx, st, "php-fpm"+p.PHPVersion, "-t"); err != nil {
		restore(rt.d.FS, file, old, oldErr)
		return nil, fmt.Errorf("php-fpm config test failed, reverted: %w", err)
	}
	return res, rt.run(ctx, st, "systemctl", "reload", svc)
}
