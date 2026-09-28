// Package system implements the system.* commands and exports host helpers (apt, users, downloads)
// reused by runtime/ and provision/.
package system

import (
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/facts"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
)

// Deps are the collaborators of the system executors.
type Deps struct {
	Runner       runner.Runner
	FS           hostfs.FS
	Logger       *slog.Logger
	HTTP         *http.Client // for system.upgrade_agent; default http.DefaultClient
	AgentVersion string
	BinaryPath   string // installed agent binary; default os.Executable()
	// RunningSHA256 reports the checksum of the running executable (version.BinarySHA256).
	RunningSHA256 func() string
	Restart       func() error // restarts the agent service after an upgrade
	RestartDelay  time.Duration
}

// System holds the executors.
type System struct{ d Deps }

// New builds the system executors.
func New(d Deps) *System {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = http.DefaultClient
	}
	if d.BinaryPath == "" {
		d.BinaryPath, _ = os.Executable()
	}
	if d.RestartDelay == 0 {
		d.RestartDelay = 3 * time.Second
	}
	return &System{d: d}
}

// Register adds system.* executors.
func (s *System) Register(reg *commands.Registry) {
	reg.Register("system.facts", commands.Typed(func(ctx context.Context, _ FactsPayload, _ commands.Stream) (any, error) {
		return facts.Collect(ctx, s.d.Runner, s.d.FS, s.d.AgentVersion)
	}))
	reg.Register("system.exec", commands.Typed(s.Exec))
	reg.Register("system.write_file", commands.Typed(s.WriteFile))
	reg.Register("system.package.install", commands.Typed(s.PackageInstall))
	reg.Register("system.user.create", commands.Typed(func(ctx context.Context, p UserSpec, st commands.Stream) (any, error) {
		return EnsureUser(ctx, s.d.Runner, s.d.FS, p, st)
	}))
	reg.Register("system.ssh_key.sync", commands.Typed(s.SSHKeySync))
	reg.Register("system.upgrade_agent", commands.Typed(s.UpgradeAgent))
}

// FactsPayload is system.facts (no fields).
type FactsPayload struct{}

// ---- system.exec ----

// ExecPayload is system.exec.
type ExecPayload struct {
	Script string            `json:"script"`
	Shell  string            `json:"shell"`
	User   string            `json:"user"`
	Cwd    string            `json:"cwd"`
	Env    map[string]string `json:"env"`
	Stdin  *string           `json:"stdin"`
}

// ExecResult is its result.
type ExecResult struct {
	ExitCode   int   `json:"exit_code"`
	DurationMS int64 `json:"duration_ms"`
}

// Exec runs a script.
func (s *System) Exec(ctx context.Context, p ExecPayload, st commands.Stream) (any, error) {
	if p.Script == "" {
		return nil, &commands.PayloadError{Err: errors.New("script is required")}
	}
	shell := p.Shell
	if shell == "" {
		shell = "/bin/bash"
	}
	c := runner.Cmd{Name: shell, Args: []string{"-c", p.Script}, Dir: p.Cwd, User: p.User, Env: EnvList(p.Env),
		Stdout: st.Stdout(), Stderr: st.Stderr()}
	if p.Stdin != nil {
		c.Stdin = strings.NewReader(*p.Stdin)
	}
	start := time.Now()
	res, err := s.d.Runner.Run(ctx, c)
	out := ExecResult{ExitCode: res.ExitCode, DurationMS: time.Since(start).Milliseconds()}
	if err != nil {
		return out, err
	}
	if res.ExitCode != 0 {
		return out, &commands.ExitError{Code: res.ExitCode, Err: fmt.Errorf("script exited with status %d", res.ExitCode)}
	}
	return out, nil
}

// EnvList renders a map as sorted KEY=VALUE entries.
func EnvList(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sortStrings(keys)
	out := make([]string, 0, len(m))
	for _, k := range keys {
		out = append(out, k+"="+m[k])
	}
	return out
}

// ---- system.write_file ----

// WriteFilePayload is system.write_file.
type WriteFilePayload struct {
	Path       string `json:"path"`
	Content    string `json:"content"`
	Encoding   string `json:"encoding"`
	Mode       string `json:"mode"`
	Owner      string `json:"owner"`
	Group      string `json:"group"`
	CreateDirs *bool  `json:"create_dirs"`
	State      string `json:"state"`
}

// ChangedResult is the common {changed} result.
type ChangedResult struct {
	Changed bool `json:"changed"`
}

// WriteFileResult is its result.
type WriteFileResult struct {
	Changed bool   `json:"changed"`
	SHA256  string `json:"sha256,omitempty"`
}

// WriteFile writes a file atomically.
func (s *System) WriteFile(ctx context.Context, p WriteFilePayload, _ commands.Stream) (any, error) {
	if !strings.HasPrefix(p.Path, "/") {
		return nil, &commands.PayloadError{Err: errors.New("path must be absolute")}
	}
	if p.State == "absent" {
		removed, err := s.d.FS.Remove(p.Path)
		return WriteFileResult{Changed: removed}, err
	}
	data := []byte(p.Content)
	if p.Encoding == "base64" {
		b, err := base64.StdEncoding.DecodeString(p.Content)
		if err != nil {
			return nil, &commands.PayloadError{Err: fmt.Errorf("content: %w", err)}
		}
		data = b
	}
	mode, err := hostfs.ParseMode(p.Mode, 0o644)
	if err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	if p.CreateDirs != nil && !*p.CreateDirs {
		if !s.d.FS.Exists(dirOf(p.Path)) {
			return nil, fmt.Errorf("directory %s does not exist", dirOf(p.Path))
		}
	}
	changed, err := s.d.FS.WriteFile(p.Path, data, mode)
	if err != nil {
		return nil, err
	}
	if p.Owner != "" {
		if err := s.d.FS.Chown(p.Path, p.Owner, p.Group); err != nil {
			return nil, err
		}
	}
	return WriteFileResult{Changed: changed, SHA256: hostfs.SHA256(data)}, nil
}

func dirOf(p string) string {
	i := strings.LastIndex(p, "/")
	if i <= 0 {
		return "/"
	}
	return p[:i]
}

func sortStrings(s []string) {
	for i := 1; i < len(s); i++ {
		for j := i; j > 0 && s[j] < s[j-1]; j-- {
			s[j], s[j-1] = s[j-1], s[j]
		}
	}
}
