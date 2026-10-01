package cron

import (
	"context"
	"encoding/json"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/obs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

func mustLoc(t *testing.T, name string) *time.Location {
	t.Helper()
	l, err := time.LoadLocation(name)
	if err != nil {
		t.Skipf("tzdata missing: %v", err)
	}
	return l
}

func TestParseErrors(t *testing.T) {
	for _, e := range []string{"", "* * * *", "60 * * * *", "* 24 * * *", "* * 0 * *", "* * * 13 *", "* * * * 8", "5-1 * * * *", "*/0 * * * *", "@often", "@every 10ms", "a * * * *", "1,,2 * * * *"} {
		if _, err := Parse(e, nil); err == nil {
			t.Errorf("expected error for %q", e)
		}
	}
}

func TestNext(t *testing.T) {
	utc := time.UTC
	base := time.Date(2026, 9, 26, 10, 7, 30, 0, utc) // Saturday
	cases := []struct {
		expr string
		from time.Time
		want string
	}{
		{"* * * * *", base, "2026-09-26T10:08:00Z"},
		{"*/5 * * * *", base, "2026-09-26T10:10:00Z"},
		{"0 * * * *", base, "2026-09-26T11:00:00Z"},
		{"@hourly", base, "2026-09-26T11:00:00Z"},
		{"@daily", base, "2026-09-27T00:00:00Z"},
		{"@midnight", base, "2026-09-27T00:00:00Z"},
		{"@weekly", base, "2026-09-27T00:00:00Z"}, // next Sunday
		{"@monthly", base, "2026-10-01T00:00:00Z"},
		{"@yearly", base, "2027-01-01T00:00:00Z"},
		{"@annually", base, "2027-01-01T00:00:00Z"},
		{"15,45 9-17 * * mon-fri", base, "2026-09-28T09:15:00Z"},
		{"0 12 * jan,jul *", base, "2027-01-01T12:00:00Z"},
		{"0 0 29 feb *", base, "2028-02-29T00:00:00Z"},
		{"0 0 31 * *", base, "2026-10-31T00:00:00Z"},
		{"30 4 1,15 * 5", base, "2026-10-01T04:30:00Z"}, // DOM/DOW OR: Fri Oct 2 vs 1st → Oct 1 (Thu) wins
		{"0 0 * * 7", base, "2026-09-27T00:00:00Z"},     // 7 = Sunday
		{"0 0 */10 * *", base, "2026-10-01T00:00:00Z"},  // 1,11,21,31
		{"5/20 * * * *", base, "2026-09-26T10:25:00Z"},  // 5,25,45
		{"@every 90s", base, "2026-09-26T10:09:00Z"},
		{"7 10 * * *", base, "2026-09-27T10:07:00Z"}, // strictly after
	}
	for _, c := range cases {
		s, err := Parse(c.expr, utc)
		if err != nil {
			t.Fatalf("%s: %v", c.expr, err)
		}
		if got := s.Next(c.from).UTC().Format(time.RFC3339); got != c.want {
			t.Errorf("%-24s got %s want %s", c.expr, got, c.want)
		}
	}
}

func TestNextTimezoneAndDST(t *testing.T) {
	ams := mustLoc(t, "Europe/Amsterdam")
	s, _ := Parse("0 9 * * *", ams)
	// 09:00 Amsterdam in summer (CEST, UTC+2) = 07:00Z.
	got := s.Next(time.Date(2026, 7, 1, 0, 0, 0, 0, time.UTC))
	if got.UTC().Format(time.RFC3339) != "2026-07-01T07:00:00Z" {
		t.Fatalf("got %s", got.UTC())
	}

	ny := mustLoc(t, "America/New_York")
	// Spring forward 2026-03-08: 02:00→03:00 local. "30 2 * * *" has no 02:30 that day → next day.
	s, _ = Parse("30 2 * * *", ny)
	got = s.Next(time.Date(2026, 3, 8, 1, 0, 0, 0, ny))
	if want := time.Date(2026, 3, 9, 2, 30, 0, 0, ny); !got.Equal(want) {
		t.Fatalf("spring: got %s want %s", got, want)
	}
	// Fall back 2026-11-01: 01:00-02:00 repeats. A fixed-hour job runs once.
	s, _ = Parse("30 1 * * *", ny)
	first := s.Next(time.Date(2026, 11, 1, 0, 0, 0, 0, ny))
	if first.UTC().Format(time.RFC3339) != "2026-11-01T05:30:00Z" { // 01:30 EDT
		t.Fatalf("fall first: %s", first.UTC())
	}
	second := s.Next(first)
	if want := time.Date(2026, 11, 2, 1, 30, 0, 0, ny); !second.Equal(want) {
		t.Fatalf("fall: ran twice? got %s", second)
	}
	// Wildcard-hour job keeps running through the repeated hour (absolute time).
	s, _ = Parse("*/30 * * * *", ny)
	n := s.Next(time.Date(2026, 11, 1, 1, 45, 0, 0, ny))        // 01:45 EDT = 05:45Z
	if n.UTC().Format(time.RFC3339) != "2026-11-01T06:00:00Z" { // 01:00 EST
		t.Fatalf("wildcard fall: %s", n.UTC())
	}
}

// fakeClock is a manually advanced clock.
type fakeClock struct {
	mu      sync.Mutex
	now     time.Time
	waiters []waiter
}

type waiter struct {
	at time.Time
	ch chan time.Time
}

func (c *fakeClock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.now }
func (c *fakeClock) After(d time.Duration) <-chan time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	ch := make(chan time.Time, 1)
	c.waiters = append(c.waiters, waiter{c.now.Add(d), ch})
	return ch
}
func (c *fakeClock) Advance(d time.Duration) {
	c.mu.Lock()
	c.now = c.now.Add(d)
	var keep []waiter
	for _, w := range c.waiters {
		if !w.at.After(c.now) {
			w.ch <- c.now
		} else {
			keep = append(keep, w)
		}
	}
	c.waiters = keep
	c.mu.Unlock()
}
func (c *fakeClock) numWaiters() int { c.mu.Lock(); defer c.mu.Unlock(); return len(c.waiters) }

type fakeInsights struct {
	mu    sync.Mutex
	lines []Heartbeat
}

func (f *fakeInsights) PostInsights(ctx context.Context, b []byte) error {
	for _, l := range strings.Split(strings.TrimSpace(string(b)), "\n") {
		var hb Heartbeat
		if err := json.Unmarshal([]byte(l), &hb); err != nil {
			return err
		}
		f.mu.Lock()
		f.lines = append(f.lines, hb)
		f.mu.Unlock()
	}
	return nil
}
func (f *fakeInsights) get() []Heartbeat {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]Heartbeat(nil), f.lines...)
}

type spanSink struct {
	mu    sync.Mutex
	spans []obs.Span
}

func (s *spanSink) EmitLog(obs.LogRecord) {}
func (s *spanSink) EmitSpan(sp obs.Span)  { s.mu.Lock(); s.spans = append(s.spans, sp); s.mu.Unlock() }

func waitFor(t *testing.T, cond func() bool, msg string) {
	t.Helper()
	for i := 0; i < 300; i++ {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("timeout: " + msg)
}

func TestSchedulerLoopWithFakeClock(t *testing.T) {
	clk := &fakeClock{now: time.Date(2026, 9, 26, 10, 0, 30, 0, time.UTC)}
	fr := &runnertest.Fake{}
	fr.On("/bin/sh -c exit 2", runner.Result{ExitCode: 2, Stderr: []byte("boom\n")})
	ins := &fakeInsights{}
	spans := &spanSink{}
	s := New(Options{StateDir: t.TempDir(), Runner: fr, Insights: ins, Sink: spans, Clock: clk,
		SiteID: func(slug string) string { return "01J00000000000000000000SITE" }})
	ctx, cancel := context.WithCancel(context.Background())
	defer func() { cancel(); s.Wait() }()
	if err := s.Start(ctx); err != nil {
		t.Fatal(err)
	}
	res, err := s.Apply([]Job{
		{Name: "every-minute", Schedule: "* * * * *", Command: "php artisan schedule:run", User: "", Cwd: "/srv", Site: "shop", Env: map[string]string{"A": "1"}},
		{Name: "failing", Schedule: "*/2 * * * *", Command: "exit 2"},
	})
	if err != nil || !res.Changed || res.Jobs[0].Name != "every-minute" || !res.Jobs[0].NextRun.Equal(time.Date(2026, 9, 26, 10, 1, 0, 0, time.UTC)) {
		t.Fatalf("%v %+v", err, res)
	}
	waitFor(t, func() bool { return clk.numWaiters() > 0 }, "loop waiting")
	clk.Advance(30 * time.Second) // 10:01:00 → every-minute runs
	waitFor(t, func() bool { return len(ins.get()) == 1 }, "first heartbeat")
	waitFor(t, func() bool { return clk.numWaiters() > 0 }, "loop waiting again")
	clk.Advance(time.Minute) // 10:02 → both
	waitFor(t, func() bool { return len(ins.get()) == 3 }, "three heartbeats")

	hbs := ins.get()
	var ok, failed int
	for _, hb := range hbs {
		if hb.Kind != "cron_heartbeat" {
			t.Fatalf("kind %q", hb.Kind)
		}
		switch hb.Job {
		case "every-minute":
			ok++
			if hb.Status != StatusFinished || hb.SiteID != "01J00000000000000000000SITE" || *hb.ExitCode != 0 {
				t.Fatalf("%+v", hb)
			}
		case "failing":
			failed++
			if hb.Status != StatusFailed || *hb.ExitCode != 2 || !hb.ScheduledAt.Equal(time.Date(2026, 9, 26, 10, 2, 0, 0, time.UTC)) {
				t.Fatalf("%+v", hb)
			}
		}
	}
	if ok != 2 || failed != 1 {
		t.Fatalf("ok=%d failed=%d", ok, failed)
	}
	calls := fr.Calls()
	if calls[0].Line != "/bin/sh -c php artisan schedule:run" || calls[0].Dir != "/srv" || calls[0].Env[0] != "A=1" {
		t.Fatalf("call %+v", calls[0])
	}
	spans.mu.Lock()
	sp := spans.spans[0]
	spans.mu.Unlock()
	if sp.Attrs["kiln.event.type"] != "scheduled_task" || sp.Attrs["kiln.schedule.expression"] == nil {
		t.Fatalf("span %+v", sp)
	}

	// Re-apply identical set: unchanged, next runs preserved.
	res2, _ := s.Apply([]Job{
		{Name: "every-minute", Schedule: "* * * * *", Command: "php artisan schedule:run", Cwd: "/srv", Site: "shop", Env: map[string]string{"A": "1"}},
		{Name: "failing", Schedule: "*/2 * * * *", Command: "exit 2"},
	})
	if res2.Changed {
		t.Fatal("re-apply reported changed")
	}

	// Persistence: new scheduler restores the job set.
	s2 := New(Options{StateDir: s.opts.StateDir, Runner: fr, Clock: clk})
	ctx2, cancel2 := context.WithCancel(context.Background())
	if err := s2.Start(ctx2); err != nil {
		t.Fatal(err)
	}
	s2.mu.Lock()
	n := len(s2.entries)
	s2.mu.Unlock()
	cancel2()
	s2.Wait()
	if n != 2 {
		t.Fatalf("restored %d jobs", n)
	}
}

func TestOverlapSkipAndTimeout(t *testing.T) {
	clk := &fakeClock{now: time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)}
	release := make(chan struct{})
	fr := &runnertest.Fake{}
	fr.OnFunc("/bin/sh -c slow", func(runnertest.Call) (runner.Result, error) {
		<-release
		return runner.Result{}, nil
	})
	ins := &fakeInsights{}
	s := New(Options{Runner: fr, Insights: ins, Clock: clk})
	if _, err := s.Apply([]Job{{Name: "slow", Schedule: "* * * * *", Command: "slow"}}); err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	s.Tick(ctx, time.Date(2026, 1, 1, 0, 1, 0, 0, time.UTC))
	waitFor(t, func() bool { return len(fr.Calls()) == 1 }, "first run started")
	s.Tick(ctx, time.Date(2026, 1, 1, 0, 2, 0, 0, time.UTC)) // still running → skipped
	waitFor(t, func() bool { return len(ins.get()) == 1 }, "skip heartbeat")
	if hb := ins.get()[0]; hb.Status != StatusSkipped {
		t.Fatalf("%+v", hb)
	}
	close(release)
	waitFor(t, func() bool { return len(ins.get()) == 2 }, "finish heartbeat")
	if len(fr.Calls()) != 1 {
		t.Fatal("overlapping run executed")
	}

	// Timeout with the real runner.
	s3 := New(Options{Runner: runner.Exec{}, Insights: ins})
	s3.Apply([]Job{{Name: "hang", Schedule: "* * * * *", Command: "sleep 5", TimeoutS: 1}})
	start := time.Now()
	s3.Tick(ctx, time.Now().Add(2*time.Minute))
	waitFor(t, func() bool { return len(ins.get()) == 3 }, "timeout heartbeat")
	if hb := ins.get()[2]; hb.Status != StatusTimeout || time.Since(start) > 4*time.Second {
		t.Fatalf("%+v", hb)
	}
	s3.Wait()
}

func TestApplyValidationAndExecutor(t *testing.T) {
	s := New(Options{Runner: &runnertest.Fake{}})
	if _, err := s.Apply([]Job{{Name: "x", Schedule: "* * *", Command: "true"}}); !commands.IsPayloadError(err) {
		t.Fatalf("got %v", err)
	}
	if _, err := s.Apply([]Job{{Name: "x", Schedule: "* * * * *", Command: "true", Timezone: "Mars/Olympus"}}); err == nil {
		t.Fatal("bad tz accepted")
	}
	reg := commands.NewRegistry()
	s.Register(reg)
	col := &commands.Collector{}
	d := commands.NewDispatcher(context.Background(), reg, col, nil)
	d.Submit(commands.Envelope{ID: "01HZZZZZZZZZZZZZZZZZZZZZZ1", Type: "cron.apply", TimeoutS: 10, IdempotencyKey: "k",
		Payload: json.RawMessage(`{"jobs":[{"name":"sched","schedule":"@every 30s","command":"true","timezone":"UTC"}]}`)})
	d.Wait()
	ev := col.Snapshot()
	fin := ev[len(ev)-1]
	if fin.Error != "" || !fin.Result.(ApplyResult).Changed {
		t.Fatalf("%+v", fin)
	}
}

func TestExitCode124IsATimeout(t *testing.T) {
	fr := &runnertest.Fake{}
	fr.OnFunc("/bin/sh -c fn-run", func(runnertest.Call) (runner.Result, error) { return runner.Result{ExitCode: ExitTimeout}, nil })
	ins := &fakeInsights{}
	s := New(Options{Runner: fr, Insights: ins, Clock: &fakeClock{now: time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)}})
	if _, err := s.Apply([]Job{{Name: "fn", Schedule: "* * * * *", Command: "fn-run"}}); err != nil {
		t.Fatal(err)
	}
	s.Tick(context.Background(), time.Date(2026, 1, 1, 0, 1, 0, 0, time.UTC))
	waitFor(t, func() bool { return len(ins.get()) == 1 }, "heartbeat")
	if hb := ins.get()[0]; hb.Status != StatusTimeout || *hb.ExitCode != ExitTimeout {
		t.Fatalf("%+v", hb)
	}
	s.Wait()
}
