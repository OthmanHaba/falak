// Package pty implements web-terminal sessions (terminal.*): a shell in a PTY whose output is streamed
// as `output` events (data = base64 of raw PTY bytes) on the long-running terminal.open command.
package pty

import (
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"regexp"
	"sort"
	"sync"
	"syscall"
	"time"

	cpty "github.com/creack/pty"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Options configures the session manager.
type Options struct {
	Logger *slog.Logger
	// MaxSessions bounds concurrent sessions (default 16).
	MaxSessions int
}

// Manager owns all live terminal sessions.
type Manager struct {
	opts Options
	log  *slog.Logger

	mu       sync.Mutex
	sessions map[string]*session
}

// New creates a Manager.
func New(o Options) *Manager {
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.MaxSessions <= 0 {
		o.MaxSessions = 16
	}
	return &Manager{opts: o, log: o.Logger.With("component", "pty"), sessions: map[string]*session{}}
}

// Payloads and results.
type (
	OpenPayload struct {
		SessionID    string            `json:"session_id"`
		User         string            `json:"user,omitempty"`
		Shell        string            `json:"shell,omitempty"`
		Cwd          string            `json:"cwd,omitempty"`
		Env          map[string]string `json:"env,omitempty"`
		Cols         int               `json:"cols,omitempty"`
		Rows         int               `json:"rows,omitempty"`
		IdleTimeoutS *int              `json:"idle_timeout_s,omitempty"`
	}
	OpenResult struct {
		ExitCode *int   `json:"exit_code,omitempty"`
		Reason   string `json:"reason"`
	}
	InputPayload struct {
		SessionID string `json:"session_id"`
		Data      string `json:"data"`
	}
	InputResult struct {
		Bytes int `json:"bytes"`
	}
	ResizePayload struct {
		SessionID string `json:"session_id"`
		Cols      int    `json:"cols"`
		Rows      int    `json:"rows"`
	}
	ClosePayload struct {
		SessionID string `json:"session_id"`
	}
	CloseResult struct {
		Changed bool `json:"changed"`
	}
)

// End reasons.
const (
	ReasonExited  = "exited"
	ReasonClosed  = "closed"
	ReasonIdle    = "idle"
	ReasonTimeout = "timeout"
)

var sidRe = regexp.MustCompile(`^[A-Za-z0-9_-]{8,64}$`)

type session struct {
	id      string
	f       *os.File
	cmd     *exec.Cmd
	closeCh chan struct{}
	done    chan struct{}
	once    sync.Once

	mu       sync.Mutex
	activity time.Time
}

func (s *session) touch() {
	s.mu.Lock()
	s.activity = time.Now()
	s.mu.Unlock()
}

func (s *session) idleFor() time.Duration {
	s.mu.Lock()
	defer s.mu.Unlock()
	return time.Since(s.activity)
}

func (s *session) requestClose() { s.once.Do(func() { close(s.closeCh) }) }

// Register adds terminal.open/input/resize/close.
func (m *Manager) Register(reg *commands.Registry) {
	reg.Register("terminal.open", commands.Typed(m.Open))
	reg.Register("terminal.input", commands.Typed(func(ctx context.Context, p InputPayload, _ commands.Stream) (any, error) {
		return m.Input(p)
	}))
	reg.Register("terminal.resize", commands.Typed(func(ctx context.Context, p ResizePayload, _ commands.Stream) (any, error) {
		return struct{}{}, m.Resize(p)
	}))
	reg.Register("terminal.close", commands.Typed(func(ctx context.Context, p ClosePayload, _ commands.Stream) (any, error) {
		return m.Close(ctx, p.SessionID), nil
	}))
}

// Open runs a session until the shell exits, it is closed, it idles out, or ctx ends (timeout_s).
func (m *Manager) Open(ctx context.Context, p OpenPayload, st commands.Stream) (any, error) {
	if !sidRe.MatchString(p.SessionID) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid session_id")}
	}
	if p.Shell == "" {
		p.Shell = "/bin/bash"
	}
	if p.Cols <= 0 {
		p.Cols = 80
	}
	if p.Rows <= 0 {
		p.Rows = 24
	}
	idle := 900 * time.Second
	if p.IdleTimeoutS != nil {
		idle = time.Duration(*p.IdleTimeoutS) * time.Second
	}

	cmd := exec.Command(p.Shell)
	cmd.Dir = p.Cwd
	env := []string{
		"TERM=xterm-256color", "LANG=C.UTF-8", "SHELL=" + p.Shell,
		"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
	}
	cmd.SysProcAttr = &syscall.SysProcAttr{}
	if p.User != "" {
		cred, home, err := runner.Credential(p.User)
		if err != nil {
			return nil, err
		}
		cmd.SysProcAttr.Credential = cred
		env = append(env, "HOME="+home, "USER="+p.User, "LOGNAME="+p.User)
		if cmd.Dir == "" {
			cmd.Dir = home
		}
	} else if h, err := os.UserHomeDir(); err == nil {
		env = append(env, "HOME="+h)
	}
	keys := make([]string, 0, len(p.Env))
	for k := range p.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		env = append(env, k+"="+p.Env[k])
	}
	cmd.Env = env

	m.mu.Lock()
	if _, ok := m.sessions[p.SessionID]; ok {
		m.mu.Unlock()
		return nil, fmt.Errorf("session %s already open", p.SessionID)
	}
	if len(m.sessions) >= m.opts.MaxSessions {
		m.mu.Unlock()
		return nil, fmt.Errorf("too many terminal sessions (max %d)", m.opts.MaxSessions)
	}
	f, err := cpty.StartWithSize(cmd, &cpty.Winsize{Cols: uint16(p.Cols), Rows: uint16(p.Rows)})
	if err != nil {
		m.mu.Unlock()
		return nil, fmt.Errorf("start pty: %w", err)
	}
	s := &session{id: p.SessionID, f: f, cmd: cmd, closeCh: make(chan struct{}), done: make(chan struct{}), activity: time.Now()}
	m.sessions[p.SessionID] = s
	m.mu.Unlock()
	m.log.Info("terminal opened", "session", p.SessionID, "user", p.User)

	defer func() {
		m.mu.Lock()
		delete(m.sessions, p.SessionID)
		m.mu.Unlock()
		close(s.done)
	}()

	// Reader: PTY → output events.
	readDone := make(chan struct{})
	go func() {
		defer close(readDone)
		buf := make([]byte, 16<<10)
		for {
			n, err := f.Read(buf)
			if n > 0 {
				s.touch()
				st.Emit("stdout", base64.StdEncoding.EncodeToString(buf[:n]))
			}
			if err != nil {
				return // EIO once the shell (and every holder of the tty) exits
			}
		}
	}()
	exited := make(chan error, 1)
	go func() { exited <- cmd.Wait() }()

	var tick <-chan time.Time
	if idle > 0 {
		t := time.NewTicker(minDur(idle/4, time.Second))
		defer t.Stop()
		tick = t.C
	}
	reason := ""
	var waitErr error
loop:
	for {
		select {
		case waitErr = <-exited:
			reason = ReasonExited
			break loop
		case <-s.closeCh:
			reason = ReasonClosed
			break loop
		case <-ctx.Done():
			reason = ReasonTimeout
			break loop
		case <-tick:
			if s.idleFor() >= idle {
				reason = ReasonIdle
				break loop
			}
		}
	}
	if reason != ReasonExited {
		// SIGHUP the session like a closed terminal would, then escalate.
		pid := cmd.Process.Pid
		_ = syscall.Kill(-pid, syscall.SIGHUP)
		_ = syscall.Kill(pid, syscall.SIGHUP)
		select {
		case waitErr = <-exited:
		case <-time.After(3 * time.Second):
			_ = syscall.Kill(-pid, syscall.SIGKILL)
			_ = syscall.Kill(pid, syscall.SIGKILL)
			waitErr = <-exited
		}
	}
	// Drain remaining output briefly, then close the master.
	select {
	case <-readDone:
	case <-time.After(500 * time.Millisecond):
	}
	f.Close()
	<-readDone
	res := OpenResult{Reason: reason}
	if cmd.ProcessState != nil {
		code := cmd.ProcessState.ExitCode()
		if ws, ok := cmd.ProcessState.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
			code = 128 + int(ws.Signal())
		}
		res.ExitCode = &code
	}
	var ee *exec.ExitError
	if waitErr != nil && !errors.As(waitErr, &ee) {
		m.log.Warn("terminal wait", "err", waitErr)
	}
	m.log.Info("terminal closed", "session", p.SessionID, "reason", reason)
	return res, nil
}

func (m *Manager) get(id string) (*session, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	s, ok := m.sessions[id]
	if !ok {
		return nil, fmt.Errorf("unknown terminal session %q", id)
	}
	return s, nil
}

// Input writes base64-decoded bytes to the session.
func (m *Manager) Input(p InputPayload) (InputResult, error) {
	data, err := base64.StdEncoding.DecodeString(p.Data)
	if err != nil {
		return InputResult{}, &commands.PayloadError{Err: fmt.Errorf("data: %w", err)}
	}
	s, err := m.get(p.SessionID)
	if err != nil {
		return InputResult{}, err
	}
	s.touch()
	n, err := s.f.Write(data)
	return InputResult{Bytes: n}, err
}

// Resize changes the window size.
func (m *Manager) Resize(p ResizePayload) error {
	if p.Cols < 1 || p.Rows < 1 || p.Cols > 1000 || p.Rows > 1000 {
		return &commands.PayloadError{Err: fmt.Errorf("invalid size %dx%d", p.Cols, p.Rows)}
	}
	s, err := m.get(p.SessionID)
	if err != nil {
		return err
	}
	s.touch()
	return cpty.Setsize(s.f, &cpty.Winsize{Cols: uint16(p.Cols), Rows: uint16(p.Rows)})
}

// Close ends a session and waits (bounded) for it to finish; unknown sessions are a no-op.
func (m *Manager) Close(ctx context.Context, id string) CloseResult {
	m.mu.Lock()
	s, ok := m.sessions[id]
	m.mu.Unlock()
	if !ok {
		return CloseResult{Changed: false}
	}
	s.requestClose()
	select {
	case <-s.done:
	case <-ctx.Done():
	case <-time.After(5 * time.Second):
	}
	return CloseResult{Changed: true}
}

// CloseAll ends every session (agent shutdown).
func (m *Manager) CloseAll() {
	m.mu.Lock()
	all := make([]*session, 0, len(m.sessions))
	for _, s := range m.sessions {
		all = append(all, s)
	}
	m.mu.Unlock()
	for _, s := range all {
		s.requestClose()
	}
	for _, s := range all {
		<-s.done
	}
}

func minDur(a, b time.Duration) time.Duration {
	if a < b {
		return a
	}
	return b
}
