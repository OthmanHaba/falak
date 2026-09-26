package pty

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
)

func decoded(col *commands.Collector, id string) string {
	var b strings.Builder
	for _, e := range col.Snapshot() {
		if e.CommandID == id && e.Kind == commands.KindOutput {
			d, _ := base64.StdEncoding.DecodeString(e.Data)
			b.Write(d)
		}
	}
	return b.String()
}

func waitOut(t *testing.T, col *commands.Collector, id, want string) {
	t.Helper()
	for i := 0; i < 500; i++ {
		if strings.Contains(decoded(col, id), want) {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("output %q never contained %q", decoded(col, id), want)
}

func finished(col *commands.Collector, id string) *commands.Event {
	for _, e := range col.Snapshot() {
		if e.CommandID == id && e.Kind == commands.KindFinished {
			return &e
		}
	}
	return nil
}

func submit(d *commands.Dispatcher, id, typ string, p any) {
	b, _ := json.Marshal(p)
	d.Submit(commands.Envelope{ID: id, Type: typ, TimeoutS: 60, IdempotencyKey: id, Payload: b})
}

func TestTerminalRoundtrip(t *testing.T) {
	m := New(Options{})
	reg := commands.NewRegistry()
	m.Register(reg)
	col := &commands.Collector{}
	d := commands.NewDispatcher(context.Background(), reg, col, nil)
	const open = "01HZZZZZZZZZZZZZZZZZZZZZZ0"
	submit(d, open, "terminal.open", OpenPayload{SessionID: "sess-0001", Shell: "/bin/sh", Cols: 100, Rows: 30, Env: map[string]string{"PS1": "$ "}})
	for i := 0; i < 200 && m.count() == 0; i++ {
		time.Sleep(5 * time.Millisecond)
	}
	submit(d, "01HZZZZZZZZZZZZZZZZZZZZZZ1", "terminal.input", InputPayload{SessionID: "sess-0001", Data: base64.StdEncoding.EncodeToString([]byte("echo hi-$((40+2))\n"))})
	waitOut(t, col, open, "hi-42")

	submit(d, "01HZZZZZZZZZZZZZZZZZZZZZZ2", "terminal.resize", ResizePayload{SessionID: "sess-0001", Cols: 132, Rows: 43})
	for i := 0; i < 200 && finished(col, "01HZZZZZZZZZZZZZZZZZZZZZZ2") == nil; i++ {
		time.Sleep(5 * time.Millisecond)
	}
	if f := finished(col, "01HZZZZZZZZZZZZZZZZZZZZZZ2"); f == nil || f.Error != "" {
		t.Fatalf("resize: %+v", f)
	}
	submit(d, "01HZZZZZZZZZZZZZZZZZZZZZZ3", "terminal.input", InputPayload{SessionID: "sess-0001", Data: base64.StdEncoding.EncodeToString([]byte("stty size\n"))})
	waitOut(t, col, open, "43 132")

	// Duplicate open is rejected while live.
	res, err := m.Open(context.Background(), OpenPayload{SessionID: "sess-0001"}, nil)
	if err == nil {
		t.Fatalf("duplicate open accepted: %v", res)
	}

	submit(d, "01HZZZZZZZZZZZZZZZZZZZZZZ4", "terminal.close", ClosePayload{SessionID: "sess-0001"})
	d.Wait()
	f := finished(col, open)
	if f == nil || f.Error != "" || f.Result.(OpenResult).Reason != ReasonClosed {
		t.Fatalf("open finished %+v", f)
	}
	if c := finished(col, "01HZZZZZZZZZZZZZZZZZZZZZZ4").Result.(CloseResult); !c.Changed {
		t.Fatal("close changed=false")
	}
	// Close of unknown session is a no-op.
	if m.Close(context.Background(), "sess-0001").Changed {
		t.Fatal("close unknown changed")
	}
	if _, err := m.Input(InputPayload{SessionID: "sess-0001", Data: "eA=="}); err == nil {
		t.Fatal("input to closed session accepted")
	}
}

func TestShellExitAndIdle(t *testing.T) {
	m := New(Options{})
	col := &commands.Collector{}
	st := commands.NewTestStream("x", col)
	go func() {
		for i := 0; i < 200 && m.count() == 0; i++ {
			time.Sleep(5 * time.Millisecond)
		}
		m.Input(InputPayload{SessionID: "sess-exit", Data: base64.StdEncoding.EncodeToString([]byte("exit 7\n"))})
	}()
	res, err := m.Open(context.Background(), OpenPayload{SessionID: "sess-exit", Shell: "/bin/sh"}, st)
	if err != nil {
		t.Fatal(err)
	}
	r := res.(OpenResult)
	if r.Reason != ReasonExited || r.ExitCode == nil || *r.ExitCode != 7 {
		t.Fatalf("%+v", r)
	}

	idle := 1
	start := time.Now()
	res, _ = m.Open(context.Background(), OpenPayload{SessionID: "sess-idle", Shell: "/bin/sh", IdleTimeoutS: &idle}, commands.NewTestStream("y", col))
	if r := res.(OpenResult); r.Reason != ReasonIdle || time.Since(start) > 5*time.Second {
		t.Fatalf("%+v after %s", r, time.Since(start))
	}

	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	res, _ = m.Open(ctx, OpenPayload{SessionID: "sess-tmo", Shell: "/bin/sh"}, commands.NewTestStream("z", col))
	if r := res.(OpenResult); r.Reason != ReasonTimeout {
		t.Fatalf("%+v", r)
	}
}

func (m *Manager) count() int { m.mu.Lock(); defer m.mu.Unlock(); return len(m.sessions) }
