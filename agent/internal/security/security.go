// Package security implements the server baseline report: security.audit (read-only checks of SSH, updates, the
// firewall, fail2ban, Docker, files, kernel settings, accounts and time sync; bounded in time, no network) and
// security.fix / security.undo (an allowlist of hardening fixes compiled into the agent: the control plane only names a
// fix, it never sends commands). Every fix backs up what it touches under /var/lib/falak/security-backups/<id>/,
// validates the result (sshd -t, apt-config, fail2ban-client -t, dockerd --validate, sysctl -p) and rolls back on
// failure; a backup can be undone for 7 days, then it is pruned.
package security

import (
	"context"
	"fmt"
	"log/slog"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Deps are the collaborators of the security executors.
type Deps struct {
	Runner    runner.Runner
	FS        hostfs.FS
	Logger    *slog.Logger
	SitesRoot string // /srv/falak/sites
	RunDir    string // /run/falak (sites' env files and containers' secret files live on its tmpfs)
	StateDir  string // /var/lib/falak (backups go to security-backups/)
	Now       func() time.Time
}

// Security holds the executors.
type Security struct {
	d  Deps
	mu sync.Mutex // one fix or undo at a time
}

// New builds the security executors.
func New(d Deps) *Security {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.Now == nil {
		d.Now = time.Now
	}
	if d.SitesRoot == "" {
		d.SitesRoot = "/srv/falak/sites"
	}
	if d.RunDir == "" {
		d.RunDir = "/run/falak"
	}
	if d.StateDir == "" {
		d.StateDir = "/var/lib/falak"
	}
	return &Security{d: d}
}

// Register adds security.* executors.
func (s *Security) Register(reg *commands.Registry) {
	reg.Register("security.audit", commands.Typed(s.Audit))
	reg.Register("security.fix", commands.Typed(s.Fix))
	reg.Register("security.undo", commands.Typed(s.Undo))
}

// Check statuses.
const (
	Pass = "pass"
	Warn = "warn"
	Fail = "fail"
	Info = "info"
)

// Severities.
const (
	Critical = "critical"
	High     = "high"
	Medium   = "medium"
	Low      = "low"
	SevInfo  = "info"
)

// Check is one finding of the report.
type Check struct {
	ID       string `json:"id"`
	Title    string `json:"title"`
	Area     string `json:"area"`
	Status   string `json:"status"`
	Severity string `json:"severity"`
	// Evidence is a short, secret-free explanation (setting values, counts, a few names or paths).
	Evidence   string `json:"evidence"`
	FixID      string `json:"fix_id,omitempty"`
	Disruptive bool   `json:"disruptive"`
}

// AuditPayload is security.audit. Everything in it is what the control plane knows the server should look like.
type AuditPayload struct {
	// ManagedKeys are the public keys Falak installs, per unix user; keys found in authorized_keys files that are not
	// here are reported. nil: keys are not compared.
	ManagedKeys map[string][]string `json:"managed_keys"`
	// ExpectedPorts are the ports the server's firewall accepts ("tcp/443", "udp/51820", "tcp/8000-8100", "any/53");
	// public listeners outside them are reported. nil: listeners are not compared.
	ExpectedPorts []string `json:"expected_ports"`
	// KnownUsers are the unix users Falak manages; other users with a login shell are listed (info).
	KnownUsers []string `json:"known_users"`
	SSHPort    int      `json:"ssh_port"`
}

// AuditResult is its result.
type AuditResult struct {
	Checks     []Check `json:"checks"`
	DurationMS int64   `json:"duration_ms"`
}

// AuditBudget bounds a whole audit; each external command gets a shorter timeout of its own.
const AuditBudget = 55 * time.Second

// Audit runs every check. It never changes the machine (it prunes expired fix backups, which are Falak's own).
func (s *Security) Audit(ctx context.Context, p AuditPayload, st commands.Stream) (any, error) {
	start := s.d.Now()
	ctx, cancel := context.WithTimeout(ctx, AuditBudget)
	defer cancel()
	s.prune()
	groups := []struct {
		name string
		fn   func(context.Context, AuditPayload) []Check
	}{
		{"ssh", s.sshChecks},
		{"updates", s.updateChecks},
		{"firewall", s.firewallChecks},
		{"intrusion", s.fail2banChecks},
		{"docker", s.dockerChecks},
		{"files", s.fileChecks},
		{"kernel", s.kernelChecks},
		{"accounts", s.accountChecks},
		{"time", s.timeChecks},
	}
	var checks []Check
	for i, g := range groups {
		if ctx.Err() != nil {
			checks = append(checks, Check{ID: "audit.incomplete", Title: "The audit ran out of time", Area: "audit", Status: Warn, Severity: SevInfo,
				Evidence: fmt.Sprintf("stopped before the %s checks (budget %s)", g.name, AuditBudget)})
			break
		}
		fmt.Fprintf(st.Stdout(), "==> %s\n", g.name)
		checks = append(checks, g.fn(ctx, p)...)
		st.Progress(float64(i+1) / float64(len(groups)))
	}
	for i := range checks {
		checks[i].Evidence = truncate(checks[i].Evidence, 500)
		if f, ok := fixFor(checks[i].FixID); ok {
			checks[i].Disruptive = f.Disruptive
		}
	}
	return AuditResult{Checks: checks, DurationMS: s.d.Now().Sub(start).Milliseconds()}, nil
}

// cmdTimeout is the default bound of one command an audit runs.
const cmdTimeout = 10 * time.Second

// run runs a command with its own timeout (the C locale, so output parses the same everywhere).
func (s *Security) run(ctx context.Context, d time.Duration, name string, args ...string) (runner.Result, error) {
	c, cancel := context.WithTimeout(ctx, d)
	defer cancel()
	return s.d.Runner.Run(c, runner.Cmd{Name: name, Args: args, Env: []string{"LC_ALL=C"}})
}

// output runs a command and returns its stdout when it exits 0.
func (s *Security) output(ctx context.Context, name string, args ...string) (string, bool) {
	res, err := s.run(ctx, cmdTimeout, name, args...)
	if err != nil || res.ExitCode != 0 {
		return "", false
	}
	return string(res.Stdout), true
}

// read reads a small host file.
func (s *Security) read(p string) (string, bool) {
	b, err := s.d.FS.ReadFile(p)
	if err != nil {
		return "", false
	}
	return string(b), true
}

func truncate(s string, n int) string {
	s = strings.TrimSpace(s)
	if len(s) > n {
		return s[:n] + "…"
	}
	return s
}

// list renders up to n items ("a, b, c and 4 more").
func list(items []string, n int) string {
	items = append([]string(nil), items...)
	sort.Strings(items)
	if len(items) <= n {
		return strings.Join(items, ", ")
	}
	return fmt.Sprintf("%s and %d more", strings.Join(items[:n], ", "), len(items)-n)
}

func plural(n int, one, many string) string {
	if n == 1 {
		return fmt.Sprintf("%d %s", n, one)
	}
	return fmt.Sprintf("%d %s", n, many)
}

// within reports whether p (clean, absolute) is root or below it.
func within(root, p string) bool {
	root, p = filepath.Clean(root), filepath.Clean(p)
	return p == root || strings.HasPrefix(p, root+"/")
}
