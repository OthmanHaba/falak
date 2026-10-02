package cron

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/obs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/transport"
)

// InsightsPoster posts NDJSON to POST /agent/v1/insights (implemented by the transport client).
type InsightsPoster interface {
	PostInsights(ctx context.Context, ndjson []byte) error
}

// Clock abstracts time for tests.
type Clock interface {
	Now() time.Time
	After(d time.Duration) <-chan time.Time
}

type realClock struct{}

func (realClock) Now() time.Time                         { return time.Now() }
func (realClock) After(d time.Duration) <-chan time.Time { return time.After(d) }

// Options configures the scheduler.
type Options struct {
	StateDir string // cron.json ("" = no persistence)
	Runner   runner.Runner
	Insights InsightsPoster // nil = heartbeats only as spans
	Sink     obs.Sink
	Clock    Clock
	Logger   *slog.Logger
	// SiteID resolves a site slug to its ULID for heartbeats (optional; defaults to the slug).
	SiteID func(slug string) string
}

// Job mirrors one cron.apply job.
type Job struct {
	Name      string            `json:"name"`
	Schedule  string            `json:"schedule"`
	Command   string            `json:"command"`
	User      string            `json:"user,omitempty"`
	Cwd       string            `json:"cwd,omitempty"`
	Env       map[string]string `json:"env,omitempty"`
	Timezone  string            `json:"timezone,omitempty"`
	Overlap   string            `json:"overlap,omitempty"`
	TimeoutS  int               `json:"timeout_s,omitempty"`
	Heartbeat *bool             `json:"heartbeat,omitempty"`
	Site      string            `json:"site,omitempty"`
}

// ApplyPayload is the cron.apply payload.
type ApplyPayload struct {
	Jobs []Job `json:"jobs"`
}

// ApplyResult is the cron.apply result.
type ApplyResult struct {
	Changed bool      `json:"changed"`
	Jobs    []NextRun `json:"jobs"`
}

// NextRun reports the next activation of a job.
type NextRun struct {
	Name    string    `json:"name"`
	NextRun time.Time `json:"next_run"`
}

// Heartbeat is one NDJSON line posted to /agent/v1/insights (cron.apply.schema.json $defs.heartbeat).
type Heartbeat struct {
	Kind        string     `json:"kind"`
	Job         string     `json:"job"`
	SiteID      string     `json:"site_id,omitempty"`
	Schedule    string     `json:"schedule,omitempty"`
	Status      string     `json:"status"`
	ExitCode    *int       `json:"exit_code,omitempty"`
	DurationMS  *int64     `json:"duration_ms,omitempty"`
	ScheduledAt time.Time  `json:"scheduled_at"`
	StartedAt   *time.Time `json:"started_at,omitempty"`
	At          time.Time  `json:"at"`
}

// Run statuses.
const (
	StatusFinished = "finished"
	StatusFailed   = "failed"
	StatusSkipped  = "skipped"
	StatusTimeout  = "timeout"
	// ExitTimeout is timeout(1)'s exit code: a command that exits with it timed out.
	ExitTimeout = 124
)

var jobNameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_.-]{0,62}$`)

type entry struct {
	job  Job
	sch  Schedule
	next time.Time
}

// Scheduler runs cron jobs.
type Scheduler struct {
	opts Options
	log  *slog.Logger

	mu      sync.Mutex
	entries map[string]*entry
	hash    string
	running map[string]int // job name → in-flight runs
	wake    chan struct{}
	wg      sync.WaitGroup
}

// New creates a scheduler; call Start to restore state and run the loop.
func New(o Options) *Scheduler {
	if o.Clock == nil {
		o.Clock = realClock{}
	}
	if o.Sink == nil {
		o.Sink = obs.Nop{}
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.Runner == nil {
		o.Runner = runner.Exec{}
	}
	return &Scheduler{opts: o, log: o.Logger.With("component", "cron"), entries: map[string]*entry{},
		running: map[string]int{}, wake: make(chan struct{}, 1)}
}

// Register adds cron.apply.
func (s *Scheduler) Register(reg *commands.Registry) {
	reg.Register("cron.apply", commands.Typed(func(ctx context.Context, p ApplyPayload, st commands.Stream) (any, error) {
		return s.Apply(p.Jobs)
	}))
}

// Start restores persisted jobs and runs the scheduling loop until ctx is done. It returns after the
// loop has been started; in-flight runs are cancelled with ctx.
func (s *Scheduler) Start(ctx context.Context) error {
	if s.opts.StateDir != "" {
		b, err := os.ReadFile(filepath.Join(s.opts.StateDir, "cron.json"))
		switch {
		case errors.Is(err, fs.ErrNotExist):
		case err != nil:
			return err
		default:
			var p ApplyPayload
			if err := json.Unmarshal(b, &p); err != nil {
				return fmt.Errorf("cron.json: %w", err)
			}
			if _, err := s.Apply(p.Jobs); err != nil {
				return err
			}
		}
	}
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		s.loop(ctx)
	}()
	return nil
}

// Wait blocks until the loop and all runs have ended (after ctx cancellation).
func (s *Scheduler) Wait() { s.wg.Wait() }

// Apply replaces the job set.
func (s *Scheduler) Apply(jobs []Job) (ApplyResult, error) {
	now := s.opts.Clock.Now()
	next := map[string]*entry{}
	for _, j := range jobs {
		if !jobNameRe.MatchString(j.Name) {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("invalid job name %q", j.Name)}
		}
		if _, dup := next[j.Name]; dup {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("duplicate job %q", j.Name)}
		}
		if strings.TrimSpace(j.Command) == "" {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: empty command", j.Name)}
		}
		switch j.Overlap {
		case "", "allow", "skip":
		default:
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: invalid overlap %q", j.Name, j.Overlap)}
		}
		loc := time.UTC
		if j.Timezone != "" {
			l, err := time.LoadLocation(j.Timezone)
			if err != nil {
				return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: %w", j.Name, err)}
			}
			loc = l
		}
		sch, err := Parse(j.Schedule, loc)
		if err != nil {
			return ApplyResult{}, &commands.PayloadError{Err: fmt.Errorf("job %s: %w", j.Name, err)}
		}
		next[j.Name] = &entry{job: j, sch: sch}
	}
	sorted := append([]Job(nil), jobs...)
	sort.Slice(sorted, func(i, k int) bool { return sorted[i].Name < sorted[k].Name })
	raw, _ := json.Marshal(ApplyPayload{Jobs: sorted})
	sum := sha256.Sum256(raw)
	h := hex.EncodeToString(sum[:])

	s.mu.Lock()
	changed := h != s.hash
	for name, e := range next {
		// Keep the pending activation of an unchanged job so a re-apply never skips/duplicates a run.
		if old, ok := s.entries[name]; ok && sameJob(old.job, e.job) {
			e.next = old.next
		} else {
			e.next = e.sch.Next(now)
		}
	}
	s.entries = next
	s.hash = h
	res := ApplyResult{Changed: changed, Jobs: []NextRun{}}
	for _, j := range sorted {
		res.Jobs = append(res.Jobs, NextRun{Name: j.Name, NextRun: next[j.Name].next.UTC()})
	}
	s.mu.Unlock()

	if changed && s.opts.StateDir != "" {
		if err := os.MkdirAll(s.opts.StateDir, 0o700); err != nil {
			return res, err
		}
		p := filepath.Join(s.opts.StateDir, "cron.json")
		if err := os.WriteFile(p+".tmp", raw, 0o600); err != nil {
			return res, err
		}
		if err := os.Rename(p+".tmp", p); err != nil {
			return res, err
		}
	}
	select {
	case s.wake <- struct{}{}:
	default:
	}
	return res, nil
}

func sameJob(a, b Job) bool {
	x, _ := json.Marshal(a)
	y, _ := json.Marshal(b)
	return bytes.Equal(x, y)
}

func (s *Scheduler) loop(ctx context.Context) {
	for {
		now := s.opts.Clock.Now()
		s.Tick(ctx, now)
		s.mu.Lock()
		var earliest time.Time
		for _, e := range s.entries {
			if !e.next.IsZero() && (earliest.IsZero() || e.next.Before(earliest)) {
				earliest = e.next
			}
		}
		s.mu.Unlock()
		wait := time.Hour
		if !earliest.IsZero() {
			wait = earliest.Sub(now)
			if wait < 0 {
				wait = 0
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-s.wake:
		case <-s.opts.Clock.After(wait):
		}
	}
}

// Tick launches every job due at or before now and advances its next activation. Exposed for tests.
func (s *Scheduler) Tick(ctx context.Context, now time.Time) {
	type due struct {
		job       Job
		scheduled time.Time
	}
	var runs []due
	s.mu.Lock()
	for _, e := range s.entries {
		if e.next.IsZero() || e.next.After(now) {
			continue
		}
		runs = append(runs, due{e.job, e.next})
		// Advance from now (not from the missed slot) so a long pause does not burst catch-up runs.
		e.next = e.sch.Next(now)
	}
	s.mu.Unlock()
	for _, r := range runs {
		s.launch(ctx, r.job, r.scheduled)
	}
}

func (s *Scheduler) launch(ctx context.Context, j Job, scheduled time.Time) {
	s.mu.Lock()
	if j.Overlap != "allow" && s.running[j.Name] > 0 {
		s.mu.Unlock()
		s.log.Info("cron run skipped (overlap)", "job", j.Name)
		s.report(ctx, j, Heartbeat{Status: StatusSkipped, ScheduledAt: scheduled, At: s.opts.Clock.Now()}, time.Time{}, "")
		return
	}
	s.running[j.Name]++
	s.mu.Unlock()
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		defer func() {
			s.mu.Lock()
			s.running[j.Name]--
			s.mu.Unlock()
		}()
		s.run(ctx, j, scheduled)
	}()
}

func (s *Scheduler) run(ctx context.Context, j Job, scheduled time.Time) {
	timeout := time.Duration(j.TimeoutS) * time.Second
	if timeout <= 0 {
		timeout = time.Hour
	}
	rctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	env := make([]string, 0, len(j.Env)+2)
	keys := make([]string, 0, len(j.Env))
	for k := range j.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		env = append(env, k+"="+j.Env[k])
	}
	env = append(env, "KILN_SCHEDULE_NAME="+j.Name)
	started := s.opts.Clock.Now()
	res, err := s.opts.Runner.Run(rctx, runner.Cmd{Name: "/bin/sh", Args: []string{"-c", j.Command}, User: j.User, Dir: j.Cwd, Env: env, ClearEnv: true})
	end := s.opts.Clock.Now()
	hb := Heartbeat{ScheduledAt: scheduled, StartedAt: &started, At: end}
	dur := end.Sub(started).Milliseconds()
	hb.DurationMS = &dur
	code := res.ExitCode
	errMsg := ""
	switch {
	case errors.Is(rctx.Err(), context.DeadlineExceeded):
		hb.Status = StatusTimeout
		code = ExitTimeout
		errMsg = "timeout after " + timeout.String()
	case err == nil && code == ExitTimeout:
		// The command reports its own timeout with timeout(1)'s exit code (kiln-agent fn-run does).
		hb.Status = StatusTimeout
		errMsg = "the command timed out (exit 124)"
	case err != nil:
		hb.Status = StatusFailed
		errMsg = err.Error()
	case code != 0:
		hb.Status = StatusFailed
		errMsg = fmt.Sprintf("exit status %d", code)
	default:
		hb.Status = StatusFinished
	}
	hb.ExitCode = &code
	s.emitOutput(j, res)
	s.log.Info("cron run", "job", j.Name, "status", hb.Status, "ms", dur)
	s.report(ctx, j, hb, started, errMsg)
}

// emitOutput relays the (bounded) job output as log records.
func (s *Scheduler) emitOutput(j Job, res runner.Result) {
	for _, st := range []struct {
		name string
		b    []byte
		sev  string
	}{{"stdout", res.Stdout, "INFO"}, {"stderr", res.Stderr, "WARN"}} {
		lines := strings.Split(strings.TrimRight(string(st.b), "\n"), "\n")
		if len(lines) > 200 {
			lines = lines[len(lines)-200:]
		}
		for _, l := range lines {
			if l == "" {
				continue
			}
			s.opts.Sink.EmitLog(obs.LogRecord{Time: s.opts.Clock.Now(), Severity: st.sev, Body: l, Site: j.Site,
				Attrs: map[string]string{"kiln.schedule.name": j.Name, "log.iostream": st.name}})
		}
	}
}

// report emits the scheduled_task span and posts the heartbeat line.
func (s *Scheduler) report(ctx context.Context, j Job, hb Heartbeat, started time.Time, errMsg string) {
	hb.Kind = "cron_heartbeat"
	hb.Job = j.Name
	hb.Schedule = j.Schedule
	if j.Site != "" {
		hb.SiteID = j.Site
		if s.opts.SiteID != nil {
			if id := s.opts.SiteID(j.Site); id != "" {
				hb.SiteID = id
			}
		}
	}
	hb.ScheduledAt = hb.ScheduledAt.UTC()
	hb.At = hb.At.UTC()
	if hb.StartedAt != nil {
		t := hb.StartedAt.UTC()
		hb.StartedAt = &t
	}
	if started.IsZero() {
		started = hb.At
	}
	attrs := map[string]any{
		"kiln.event.type":          "scheduled_task",
		"kiln.schedule.name":       j.Name,
		"kiln.schedule.expression": j.Schedule,
		"kiln.schedule.status":     spanStatus(hb.Status),
	}
	if hb.ExitCode != nil {
		attrs["process.exit.code"] = int64(*hb.ExitCode)
	}
	s.opts.Sink.EmitSpan(obs.Span{Name: j.Name, Kind: "INTERNAL", Start: started, End: hb.At, Site: j.Site,
		Attrs: attrs, Error: hb.Status == StatusFailed || hb.Status == StatusTimeout, ErrorMsg: errMsg})

	if j.Heartbeat != nil && !*j.Heartbeat || s.opts.Insights == nil {
		return
	}
	line, _ := json.Marshal(hb)
	line = append(line, '\n')
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		backoff := 500 * time.Millisecond
		for attempt := 0; attempt < 4; attempt++ {
			pctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
			err := s.opts.Insights.PostInsights(pctx, line)
			cancel()
			if err == nil || transport.IsRevoked(err) { // revoked: reported once by the client; retrying cannot help
				return
			}
			s.log.Warn("heartbeat post failed", "job", j.Name, "err", err)
			select {
			case <-time.After(backoff):
			case <-ctx.Done():
				return
			}
			backoff *= 2
		}
	}()
}

// spanStatus maps a run status to the telemetry contract's kiln.schedule.status enum.
func spanStatus(st string) string {
	switch st {
	case StatusFinished:
		return "finished"
	case StatusSkipped:
		return "skipped"
	default:
		return "failed"
	}
}
