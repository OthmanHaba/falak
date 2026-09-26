// Package runnertest provides a scriptable fake Runner.
package runnertest

import (
	"context"
	"io"
	"strings"
	"sync"

	"github.com/kiln/agent/internal/runner"
)

// Call is one recorded invocation.
type Call struct {
	runner.Cmd
	Line  string // "name arg1 arg2"
	Stdin string // fully read stdin, if any
}

type rule struct {
	prefix string
	fn     func(Call) (runner.Result, error)
}

// Fake records every call. Responses are chosen by the first rule whose prefix matches the command
// line; unmatched calls succeed with exit 0 and empty output.
type Fake struct {
	mu    sync.Mutex
	calls []Call
	rules []rule
}

// On registers a static response for command lines starting with prefix.
func (f *Fake) On(prefix string, res runner.Result) *Fake {
	return f.OnFunc(prefix, func(Call) (runner.Result, error) { return res, nil })
}

// OnFunc registers a dynamic response.
func (f *Fake) OnFunc(prefix string, fn func(Call) (runner.Result, error)) *Fake {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.rules = append(f.rules, rule{prefix, fn})
	return f
}

func (f *Fake) Run(ctx context.Context, c runner.Cmd) (runner.Result, error) {
	call := Call{Cmd: c, Line: c.String()}
	if c.Stdin != nil {
		b, _ := io.ReadAll(c.Stdin)
		call.Stdin = string(b)
	}
	f.mu.Lock()
	f.calls = append(f.calls, call)
	var fn func(Call) (runner.Result, error)
	for _, r := range f.rules {
		if strings.HasPrefix(call.Line, r.prefix) {
			fn = r.fn
			break
		}
	}
	f.mu.Unlock()
	if err := ctx.Err(); err != nil {
		return runner.Result{ExitCode: -1}, err
	}
	if fn == nil {
		return runner.Result{}, nil
	}
	res, err := fn(call)
	if c.Stdout != nil && len(res.Stdout) > 0 {
		_, _ = c.Stdout.Write(res.Stdout)
	}
	if c.Stderr != nil && len(res.Stderr) > 0 {
		_, _ = c.Stderr.Write(res.Stderr)
	}
	return res, err
}

// Calls returns a copy of all recorded calls.
func (f *Fake) Calls() []Call {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]Call(nil), f.calls...)
}

// Lines returns the recorded command lines.
func (f *Fake) Lines() []string {
	var out []string
	for _, c := range f.Calls() {
		out = append(out, c.Line)
	}
	return out
}

// Ran reports whether any call's line starts with prefix.
func (f *Fake) Ran(prefix string) bool {
	for _, l := range f.Lines() {
		if strings.HasPrefix(l, prefix) {
			return true
		}
	}
	return false
}

// Reset clears recorded calls (rules are kept).
func (f *Fake) Reset() {
	f.mu.Lock()
	f.calls = nil
	f.mu.Unlock()
}
