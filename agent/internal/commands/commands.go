// Package commands contains the command registry, the Executor interface and the dispatcher that runs
// envelopes received from the control plane and turns them into protocol events.
package commands

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"sort"
	"sync"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/redact"
)

// Envelope mirrors contracts/agent-protocol/envelope.schema.json.
type Envelope struct {
	ID             string          `json:"id"`
	Type           string          `json:"type"`
	TimeoutS       int             `json:"timeout_s"`
	IdempotencyKey string          `json:"idempotency_key"`
	Payload        json.RawMessage `json:"payload"`
}

// Event kinds.
const (
	KindStarted  = "started"
	KindOutput   = "output"
	KindProgress = "progress"
	KindFinished = "finished"
)

// Event mirrors contracts/agent-protocol/event.schema.json.
type Event struct {
	CommandID string    `json:"command_id"`
	Seq       int64     `json:"seq"`
	Kind      string    `json:"kind"`
	At        time.Time `json:"at"`
	Stream    string    `json:"stream,omitempty"`
	Data      string    `json:"data,omitempty"`
	Progress  *float64  `json:"progress,omitempty"`
	ExitCode  *int      `json:"exit_code,omitempty"`
	Result    any       `json:"result,omitempty"`
	Error     string    `json:"error,omitempty"`
}

// Stream is handed to executors for live output.
type Stream interface {
	Stdout() io.Writer
	Stderr() io.Writer
	Progress(p float64)
	// Emit sends raw output data verbatim (used by terminal sessions that base64 their PTY bytes).
	Emit(stream, data string)
}

// Executor runs one command type. Implementations must be idempotent: re-running the same payload
// converges to the same state and reports changed=false when nothing had to be done.
type Executor interface {
	Execute(ctx context.Context, env Envelope, s Stream) (result any, err error)
}

// Func adapts a function to Executor.
type Func func(ctx context.Context, env Envelope, s Stream) (any, error)

func (f Func) Execute(ctx context.Context, env Envelope, s Stream) (any, error) {
	return f(ctx, env, s)
}

// Typed wraps a function taking a decoded payload. The returned executor also implements
// PayloadChecker so contract tests can prove every schema example decodes into the Go type.
func Typed[P any](fn func(ctx context.Context, p P, s Stream) (any, error)) Executor {
	return typed[P]{fn}
}

type typed[P any] struct {
	fn func(ctx context.Context, p P, s Stream) (any, error)
}

func (t typed[P]) Execute(ctx context.Context, env Envelope, s Stream) (any, error) {
	p, err := Decode[P](env.Payload)
	if err != nil {
		return nil, err
	}
	if sp, ok := any(p).(SecretPayload); ok {
		redact.Add(ctx, sp.Secrets()...)
	}
	return t.fn(ctx, p, s)
}

// SecretPayload is implemented by payloads that carry secrets (usually the values of the variables they list in
// `mask`). Typed adds them to the command's redact set before the executor runs, so its output, error and result
// never show them.
type SecretPayload interface {
	Secrets() []string
}

func (t typed[P]) CheckPayload(raw json.RawMessage) error {
	_, err := Decode[P](raw)
	return err
}

// PayloadChecker is implemented by executors that can validate a payload without executing.
type PayloadChecker interface {
	CheckPayload(raw json.RawMessage) error
}

// ExitError lets an executor report a specific exit code (e.g. a failed hook script).
type ExitError struct {
	Code int
	Err  error
}

func (e *ExitError) Error() string { return e.Err.Error() }
func (e *ExitError) Unwrap() error { return e.Err }

// PayloadError marks invalid payloads.
type PayloadError struct{ Err error }

func (e *PayloadError) Error() string { return "invalid payload: " + e.Err.Error() }
func (e *PayloadError) Unwrap() error { return e.Err }

// Decode strictly decodes a payload (unknown fields rejected, as in the schemas).
func Decode[P any](raw json.RawMessage) (P, error) {
	var p P
	if len(bytes.TrimSpace(raw)) == 0 || string(bytes.TrimSpace(raw)) == "null" {
		raw = []byte("{}")
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return p, &PayloadError{err}
	}
	return p, nil
}

// Registry maps command types to executors.
type Registry struct {
	mu sync.RWMutex
	m  map[string]Executor
}

// NewRegistry returns an empty registry.
func NewRegistry() *Registry { return &Registry{m: map[string]Executor{}} }

// Register adds an executor; registering a type twice panics (programming error).
func (r *Registry) Register(typ string, e Executor) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if _, dup := r.m[typ]; dup {
		panic("commands: duplicate registration of " + typ)
	}
	r.m[typ] = e
}

// Get looks up an executor.
func (r *Registry) Get(typ string) (Executor, bool) {
	r.mu.RLock()
	defer r.mu.RUnlock()
	e, ok := r.m[typ]
	return e, ok
}

// Types lists registered types, sorted.
func (r *Registry) Types() []string {
	r.mu.RLock()
	defer r.mu.RUnlock()
	out := make([]string, 0, len(r.m))
	for t := range r.m {
		out = append(out, t)
	}
	sort.Strings(out)
	return out
}

// Errorf is a convenience for executors.
func Errorf(format string, a ...any) error { return fmt.Errorf(format, a...) }

// IsPayloadError reports whether err stems from payload decoding/validation.
func IsPayloadError(err error) bool {
	var pe *PayloadError
	return errors.As(err, &pe)
}
