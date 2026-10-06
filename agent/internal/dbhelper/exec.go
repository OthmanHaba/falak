package dbhelper

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// Cmd is one tool invocation. Arguments are never passed through a shell.
type Cmd struct {
	Name   string
	Args   []string
	Env    []string // added to the helper's environment
	Stdin  io.Reader
	Stdout io.Writer
	Stderr io.Writer
}

func (c Cmd) String() string { return c.Name + " " + strings.Join(c.Args, " ") }

// Runner runs tools (exec in production, a recorder in tests).
type Runner interface {
	Run(ctx context.Context, c Cmd) error
}

// ExecRunner runs commands with os/exec.
type ExecRunner struct{}

func (ExecRunner) Run(ctx context.Context, c Cmd) error {
	cmd := exec.CommandContext(ctx, c.Name, c.Args...)
	cmd.Env = append(os.Environ(), c.Env...)
	cmd.Stdin, cmd.Stdout = c.Stdin, c.Stdout
	var tail tailBuffer
	if c.Stderr != nil {
		cmd.Stderr = io.MultiWriter(c.Stderr, &tail)
	} else {
		cmd.Stderr = &tail
	}
	if err := cmd.Run(); err != nil {
		if msg := strings.TrimSpace(tail.String()); msg != "" && c.Stderr == nil {
			return fmt.Errorf("%s: %w: %s", filepath.Base(c.Name), err, msg)
		}
		return fmt.Errorf("%s: %w", filepath.Base(c.Name), err)
	}
	return nil
}

// tailBuffer keeps the last max bytes written to it (default 4 KiB: a failing tool's message).
type tailBuffer struct {
	b   []byte
	max int
}

func (t *tailBuffer) limit() int {
	if t.max == 0 {
		return 4096
	}
	return t.max
}

func (t *tailBuffer) Write(p []byte) (int, error) {
	t.b = append(t.b, p...)
	if len(t.b) > 2*t.limit() {
		t.b = append([]byte(nil), t.b[len(t.b)-t.limit():]...)
	}
	return len(p), nil
}

func (t *tailBuffer) bytes() []byte {
	if len(t.b) > t.limit() {
		return t.b[len(t.b)-t.limit():]
	}
	return t.b
}

func (t *tailBuffer) String() string { return string(t.bytes()) }

// output runs c and returns its stdout.
func output(ctx context.Context, r Runner, c Cmd) (string, error) {
	var out bytes.Buffer
	c.Stdout = &out
	err := r.Run(ctx, c)
	return out.String(), err
}

// pipe runs a | b, returning the first error.
func pipe(ctx context.Context, r Runner, a, b Cmd) error {
	pr, pw := io.Pipe()
	a.Stdout, b.Stdin = pw, pr
	errA := make(chan error, 1)
	go func() {
		err := r.Run(ctx, a)
		pw.CloseWithError(err)
		errA <- err
	}()
	errB := r.Run(ctx, b)
	// If b stopped reading early, unblock a.
	pr.CloseWithError(io.ErrClosedPipe)
	err := <-errA
	// When b fails, a usually fails too (a broken pipe): b's error is the cause.
	if errB != nil {
		return errB
	}
	return err
}
