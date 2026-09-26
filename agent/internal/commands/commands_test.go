package commands

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"sync/atomic"
	"testing"
	"time"
	"unicode/utf8"
)

func finished(t *testing.T, c *Collector, id string) Event {
	t.Helper()
	for _, e := range c.Snapshot() {
		if e.CommandID == id && e.Kind == KindFinished {
			return e
		}
	}
	t.Fatalf("no finished event for %s", id)
	return Event{}
}

func run(t *testing.T, reg *Registry, envs ...Envelope) *Collector {
	t.Helper()
	c := &Collector{}
	d := NewDispatcher(context.Background(), reg, c, nil)
	for _, e := range envs {
		d.Submit(e)
		d.Wait()
	}
	return c
}

func TestDispatcherOutcomes(t *testing.T) {
	reg := NewRegistry()
	reg.Register("t.ok", Typed(func(ctx context.Context, p struct {
		Name string `json:"name"`
	}, s Stream) (any, error) {
		return map[string]string{"hello": p.Name}, nil
	}))
	reg.Register("t.slow", Func(func(ctx context.Context, _ Envelope, _ Stream) (any, error) {
		<-ctx.Done()
		return nil, ctx.Err()
	}))
	reg.Register("t.panic", Func(func(context.Context, Envelope, Stream) (any, error) { panic("boom") }))
	reg.Register("t.exit", Func(func(context.Context, Envelope, Stream) (any, error) {
		return map[string]int{"exit_code": 3}, &ExitError{Code: 3, Err: errors.New("hook failed")}
	}))
	cases := []struct {
		env      Envelope
		code     int
		errPart  string
		resultOK bool
	}{
		{Envelope{ID: "1", Type: "t.ok", Payload: json.RawMessage(`{"name":"kiln"}`)}, 0, "", true},
		{Envelope{ID: "2", Type: "t.ok", Payload: json.RawMessage(`{"nope":1}`)}, 2, "unknown field", false},
		{Envelope{ID: "3", Type: "t.slow", TimeoutS: 1}, ExitTimeout, "timed out", false},
		{Envelope{ID: "4", Type: "t.panic"}, 1, "panic: boom", false},
		{Envelope{ID: "5", Type: "t.missing"}, 2, "unknown command type", false},
		{Envelope{ID: "6", Type: "t.exit"}, 3, "hook failed", true},
	}
	for _, tc := range cases {
		c := run(t, reg, tc.env)
		f := finished(t, c, tc.env.ID)
		if f.ExitCode == nil || *f.ExitCode != tc.code {
			t.Errorf("%s: exit %v want %d", tc.env.Type, f.ExitCode, tc.code)
		}
		if tc.errPart != "" && !strings.Contains(f.Error, tc.errPart) {
			t.Errorf("%s: error %q missing %q", tc.env.Type, f.Error, tc.errPart)
		}
		if tc.resultOK != (f.Result != nil) {
			t.Errorf("%s: result %v", tc.env.Type, f.Result)
		}
	}
}

func TestDispatcherDedupe(t *testing.T) {
	var n int32
	reg := NewRegistry()
	reg.Register("t.count", Func(func(context.Context, Envelope, Stream) (any, error) {
		atomic.AddInt32(&n, 1)
		return map[string]bool{"changed": true}, nil
	}))
	c := &Collector{}
	d := NewDispatcher(context.Background(), reg, c, nil)
	d.Submit(Envelope{ID: "A", Type: "t.count", IdempotencyKey: "k"})
	d.Wait()
	if d.Submit(Envelope{ID: "A", Type: "t.count", IdempotencyKey: "k"}) {
		t.Fatal("same id must be deduplicated")
	}
	if d.Submit(Envelope{ID: "B", Type: "t.count", IdempotencyKey: "k"}) {
		t.Fatal("same idempotency key must be deduplicated")
	}
	d.Submit(Envelope{ID: "C", Type: "t.count", IdempotencyKey: "other"})
	d.Wait()
	if n != 2 {
		t.Fatalf("executed %d times, want 2", n)
	}
	// Redelivered A re-emits identical (id, seq) events so the plane can dedupe.
	var aFinished []Event
	for _, e := range c.Snapshot() {
		if e.CommandID == "A" && e.Kind == KindFinished {
			aFinished = append(aFinished, e)
		}
	}
	if len(aFinished) != 2 || aFinished[0].Seq != aFinished[1].Seq {
		t.Fatalf("re-emitted finished events differ: %+v", aFinished)
	}
	fb := finished(t, c, "B")
	if fb.Result == nil || *fb.ExitCode != 0 {
		t.Fatalf("cached result not returned for B: %+v", fb)
	}
}

func TestStreamChunksValidUTF8(t *testing.T) {
	c := &Collector{}
	s := newStream("x", c, time.Now)
	big := strings.Repeat("é", maxChunk) // 2 bytes each → forces chunking
	b := []byte(big)
	// Write in odd-sized pieces to split runes across writes.
	for len(b) > 0 {
		n := 7777
		if n > len(b) {
			n = len(b)
		}
		s.Stdout().Write(b[:n])
		b = b[n:]
	}
	s.flush()
	var got strings.Builder
	var seq int64 = -1
	for _, e := range c.Snapshot() {
		if !utf8.ValidString(e.Data) {
			t.Fatalf("chunk seq %d is not valid UTF-8", e.Seq)
		}
		if len(e.Data) > maxChunk {
			t.Fatalf("chunk too large: %d", len(e.Data))
		}
		if e.Seq != seq+1 {
			t.Fatalf("seq gap %d → %d", seq, e.Seq)
		}
		seq = e.Seq
		got.WriteString(e.Data)
	}
	if got.String() != big {
		t.Fatal("output mismatch")
	}
}
