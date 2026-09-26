package supervisor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/obs"
)

type memSink struct {
	mu   sync.Mutex
	logs []obs.LogRecord
}

func (m *memSink) EmitLog(r obs.LogRecord) { m.mu.Lock(); m.logs = append(m.logs, r); m.mu.Unlock() }
func (m *memSink) EmitSpan(obs.Span)       {}
func (m *memSink) bodies() []string {
	m.mu.Lock()
	defer m.mu.Unlock()
	var out []string
	for _, l := range m.logs {
		out = append(out, l.Body)
	}
	return out
}

func newSup(t *testing.T) (*Supervisor, *memSink, string) {
	t.Helper()
	dir := t.TempDir()
	sink := &memSink{}
	s := New(Options{StateDir: filepath.Join(dir, "state"), LogDir: filepath.Join(dir, "log"), Sink: sink})
	t.Cleanup(s.Shutdown)
	return s, sink, dir
}

func sh(script string) []string { return []string{"/bin/sh", "-c", script} }

func ip(i int) *int { return &i }

func eventually(t *testing.T, d time.Duration, cond func() bool, msg string) {
	t.Helper()
	deadline := time.Now().Add(d)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("timeout: %s", msg)
}

func TestStartAndLogs(t *testing.T) {
	s, sink, dir := newSup(t)
	res, err := s.Apply(context.Background(), []Program{{Name: "web", Command: sh("echo hello; echo oops >&2; exec sleep 30"), Numprocs: 2}})
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || len(res.Started) != 1 {
		t.Fatalf("res %+v", res)
	}
	eventually(t, 3*time.Second, func() bool {
		st := s.Status(nil)
		return len(st) == 2 && st[0].PID > 0 && st[1].PID > 0
	}, "two instances running")
	eventually(t, 3*time.Second, func() bool {
		b, _ := os.ReadFile(filepath.Join(dir, "log", "web.out.log"))
		e, _ := os.ReadFile(filepath.Join(dir, "log", "web.err.log"))
		return strings.Count(string(b), "hello") == 2 && strings.Count(string(e), "oops") == 2
	}, "log files written")
	eventually(t, time.Second, func() bool { return strings.Count(strings.Join(sink.bodies(), ","), "hello") == 2 }, "otlp logs relayed")
	sink.mu.Lock()
	rec := sink.logs[0]
	sink.mu.Unlock()
	if rec.Attrs["process.name"] != "web" {
		t.Fatalf("attrs %v", rec.Attrs)
	}
}

func TestRestartOnFailureWithBackoff(t *testing.T) {
	s, _, _ := newSup(t)
	_, err := s.Apply(context.Background(), []Program{{
		Name: "flaky", Command: sh("exit 3"), Restart: RestartOnFailure,
		Backoff: &Backoff{InitialMS: 20, MaxMS: 80}, StartSeconds: ip(5),
	}})
	if err != nil {
		t.Fatal(err)
	}
	start := time.Now()
	eventually(t, 3*time.Second, func() bool { return s.Status(nil)[0].Restarts >= 5 }, "5 restarts")
	// 20+40+80+80+80 = 300ms minimum with exponential backoff capped at 80ms.
	if el := time.Since(start); el < 250*time.Millisecond {
		t.Fatalf("restarts too fast (%s): backoff not applied", el)
	}
	st := s.Status(nil)[0]
	if st.LastExitCode == nil || *st.LastExitCode != 3 {
		t.Fatalf("last exit %+v", st)
	}
}

func TestOnFailureCleanExitStops(t *testing.T) {
	s, _, _ := newSup(t)
	s.Apply(context.Background(), []Program{{Name: "once", Command: sh("exit 0"), Restart: RestartOnFailure, Backoff: &Backoff{InitialMS: 10}}})
	eventually(t, 2*time.Second, func() bool { return s.Status(nil)[0].State == StateExited }, "exited")
	time.Sleep(50 * time.Millisecond)
	if st := s.Status(nil)[0]; st.Restarts != 0 {
		t.Fatalf("restarted: %+v", st)
	}
}

func TestNeverPolicy(t *testing.T) {
	s, _, _ := newSup(t)
	s.Apply(context.Background(), []Program{{Name: "job", Command: sh("exit 1"), Restart: RestartNever, Backoff: &Backoff{InitialMS: 10}}})
	eventually(t, 2*time.Second, func() bool { return s.Status(nil)[0].State == StateExited }, "exited")
	time.Sleep(50 * time.Millisecond)
	st := s.Status(nil)[0]
	if st.Restarts != 0 || *st.LastExitCode != 1 {
		t.Fatalf("%+v", st)
	}
}

func TestApplyConvergence(t *testing.T) {
	s, _, _ := newSup(t)
	ctx := context.Background()
	a := Program{Name: "a", Command: sh("exec sleep 30"), StopTimeoutS: 2}
	b := Program{Name: "b", Command: sh("exec sleep 30"), StopTimeoutS: 2}
	c := Program{Name: "c", Command: sh("exec sleep 30"), StopTimeoutS: 2}
	if _, err := s.Apply(ctx, []Program{a, b, c}); err != nil {
		t.Fatal(err)
	}
	eventually(t, 2*time.Second, func() bool {
		for _, st := range s.Status(nil) {
			if st.PID == 0 {
				return false
			}
		}
		return true
	}, "running")
	pidA := s.Status([]string{"a"})[0].PID
	b2 := b
	b2.Env = map[string]string{"X": "1"}
	d := Program{Name: "d", Command: sh("exec sleep 30")}
	res, err := s.Apply(ctx, []Program{a, b2, d}) // c removed, b changed, d new, a unchanged
	if err != nil {
		t.Fatal(err)
	}
	want := ApplyResult{Changed: true, Started: []string{"d"}, Stopped: []string{"c"}, Restarted: []string{"b"}, Unchanged: []string{"a"}}
	g, _ := json.Marshal(res)
	w, _ := json.Marshal(want)
	if string(g) != string(w) {
		t.Fatalf("got %s want %s", g, w)
	}
	if s.Status([]string{"a"})[0].PID != pidA {
		t.Fatal("unchanged program was restarted")
	}
	if len(s.Status([]string{"c"})) != 0 {
		t.Fatal("c still present")
	}
	// Idempotent re-apply.
	res, _ = s.Apply(ctx, []Program{a, b2, d})
	if res.Changed || len(res.Unchanged) != 3 {
		t.Fatalf("not idempotent: %+v", res)
	}
}

func TestGracefulStopAndKillEscalation(t *testing.T) {
	s, _, dir := newSup(t)
	ctx := context.Background()
	marker := filepath.Join(dir, "got-term")
	// Traps TERM → writes marker and exits 0.
	graceful := Program{Name: "graceful", Command: sh("trap 'echo x > " + marker + "; exit 0' TERM; while :; do sleep 0.05; done"), StartSeconds: ip(0)}
	// Ignores TERM → must be SIGKILLed after stop_timeout_s.
	stubborn := Program{Name: "stubborn", Command: sh("trap '' TERM; while :; do sleep 0.05; done"), StopTimeoutS: 1, StartSeconds: ip(0)}
	s.Apply(ctx, []Program{graceful, stubborn})
	eventually(t, 2*time.Second, func() bool {
		st := s.Status(nil)
		return len(st) == 2 && st[0].PID > 0 && st[1].PID > 0
	}, "running")
	time.Sleep(100 * time.Millisecond) // let the shells install their traps
	start := time.Now()
	res, err := s.Apply(ctx, nil)
	if err != nil || len(res.Stopped) != 2 {
		t.Fatalf("%v %+v", err, res)
	}
	el := time.Since(start)
	if el < 900*time.Millisecond || el > 4*time.Second {
		t.Fatalf("stop took %s, expected ~1s kill escalation", el)
	}
	if _, err := os.Stat(marker); err != nil {
		t.Fatal("graceful program did not receive TERM")
	}
}

func TestPersistenceRestore(t *testing.T) {
	dir := t.TempDir()
	opts := Options{StateDir: filepath.Join(dir, "state"), LogDir: filepath.Join(dir, "log")}
	s1 := New(opts)
	s1.Apply(context.Background(), []Program{{Name: "keep", Command: sh("exec sleep 30"), Site: "shop"}})
	s1.Shutdown()

	s2 := New(opts)
	defer s2.Shutdown()
	if err := s2.Start(context.Background()); err != nil {
		t.Fatal(err)
	}
	eventually(t, 2*time.Second, func() bool {
		st := s2.Status(nil)
		return len(st) == 1 && st[0].Name == "keep" && st[0].PID > 0
	}, "restored program running")
	res, _ := s2.Apply(context.Background(), []Program{{Name: "keep", Command: sh("exec sleep 30"), Site: "shop"}})
	if res.Changed {
		t.Fatalf("restored state hash mismatch: %+v", res)
	}
}

func TestRestartAndExecutors(t *testing.T) {
	s, _, _ := newSup(t)
	reg := commands.NewRegistry()
	s.Register(reg)
	col := &commands.Collector{}
	ctx := context.Background()
	d := commands.NewDispatcher(ctx, reg, col, nil)
	d.Submit(commands.Envelope{ID: "01HZZZZZZZZZZZZZZZZZZZZZZ1", Type: "proc.apply", TimeoutS: 30, IdempotencyKey: "k1",
		Payload: json.RawMessage(`{"programs":[{"name":"w","command":["/bin/sh","-c","exec sleep 30"],"site":"shop","stop_timeout_s":2},{"name":"o","command":["/bin/sh","-c","exec sleep 30"],"site":"other"}]}`)})
	d.Wait()
	eventually(t, 2*time.Second, func() bool { return s.Status([]string{"w"})[0].PID > 0 }, "running")
	pid := s.Status([]string{"w"})[0].PID
	d.Submit(commands.Envelope{ID: "01HZZZZZZZZZZZZZZZZZZZZZZ2", Type: "proc.restart", TimeoutS: 30, IdempotencyKey: "k2", Payload: json.RawMessage(`{"site":"shop"}`)})
	d.Wait()
	eventually(t, 2*time.Second, func() bool { p := s.Status([]string{"w"})[0].PID; return p > 0 && p != pid }, "new pid")
	if err := syscall.Kill(pid, 0); err == nil {
		t.Fatal("old process still alive")
	}
	d.Submit(commands.Envelope{ID: "01HZZZZZZZZZZZZZZZZZZZZZZ3", Type: "proc.status", TimeoutS: 30, IdempotencyKey: "k3", Payload: json.RawMessage(`{}`)})
	d.Wait()
	var restartRes, statusRes any
	for _, e := range col.Snapshot() {
		if e.Kind == commands.KindFinished {
			if e.Error != "" {
				t.Fatalf("%s failed: %s", e.CommandID, e.Error)
			}
			switch e.CommandID {
			case "01HZZZZZZZZZZZZZZZZZZZZZZ2":
				restartRes = e.Result
			case "01HZZZZZZZZZZZZZZZZZZZZZZ3":
				statusRes = e.Result
			}
		}
	}
	if r := restartRes.(RestartResult); len(r.Restarted) != 1 || r.Restarted[0] != "w" {
		t.Fatalf("restart result %+v", r)
	}
	if st := statusRes.(StatusResult); len(st.Processes) != 2 {
		t.Fatalf("status %+v", st)
	}
}

func TestLogRotation(t *testing.T) {
	dir := t.TempDir()
	r, err := openRot(filepath.Join(dir, "x.log"), 10)
	if err != nil {
		t.Fatal(err)
	}
	r.Write([]byte("12345678\n"))
	r.Write([]byte("abcdefgh\n"))
	r.Close()
	cur, _ := os.ReadFile(filepath.Join(dir, "x.log"))
	old, _ := os.ReadFile(filepath.Join(dir, "x.log.1"))
	if string(cur) != "abcdefgh\n" || string(old) != "12345678\n" {
		t.Fatalf("cur=%q old=%q", cur, old)
	}
}

func TestValidation(t *testing.T) {
	s, _, _ := newSup(t)
	_, err := s.Apply(context.Background(), []Program{{Name: "Bad Name", Command: sh("true")}})
	if !commands.IsPayloadError(err) {
		t.Fatalf("expected payload error, got %v", err)
	}
}
