package commands

import (
	"container/list"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"runtime/debug"
	"sort"
	"sync"
	"time"
)

// EventSink receives events (implemented by the transport, which batches and retries).
type EventSink interface {
	Emit(ev Event)
}

// DefaultTimeout applies when an envelope carries no timeout.
const DefaultTimeout = 600 * time.Second

// ExitTimeout is the exit code reported when a command exceeds timeout_s (like coreutils timeout).
const ExitTimeout = 124

// Dispatcher runs envelopes concurrently, one goroutine per command.
//   - A command id that is running is ignored (redelivery during long-poll).
//   - A command id (or idempotency key) that already finished is not re-executed; its recorded
//     `started` + `finished` events are re-emitted with the original seq numbers, which the control plane
//     dedupes on (command_id, seq).
type Dispatcher struct {
	reg  *Registry
	sink EventSink
	log  *slog.Logger
	now  func() time.Time

	mu      sync.Mutex
	running map[string]context.CancelFunc
	done    *lru // command id → finished record
	byKey   *lru // idempotency key → finished record
	wg      sync.WaitGroup
	ctx     context.Context

	// Durable journal (see Persist).
	journalPath    string
	journalCap     int
	journal        []journalEntry
	journalSeq     uint64
	journalMu      sync.Mutex
	journalWritten uint64
}

type record struct {
	finished  Event
	startedAt time.Time
}

// NewDispatcher creates a dispatcher bound to ctx (cancelling ctx cancels all running commands).
func NewDispatcher(ctx context.Context, reg *Registry, sink EventSink, log *slog.Logger) *Dispatcher {
	if log == nil {
		log = slog.Default()
	}
	return &Dispatcher{
		reg: reg, sink: sink, log: log, now: time.Now, ctx: ctx,
		running: map[string]context.CancelFunc{},
		done:    newLRU(2048), byKey: newLRU(2048),
	}
}

// Submit schedules an envelope; returns false if it was deduplicated.
func (d *Dispatcher) Submit(env Envelope) bool {
	d.mu.Lock()
	if _, ok := d.running[env.ID]; ok {
		d.mu.Unlock()
		return false
	}
	rec, ok := d.done.get(env.ID)
	if !ok && env.IdempotencyKey != "" {
		if r, hit := d.byKey.get(env.IdempotencyKey); hit {
			// Same logical operation under a new command id: answer with the cached result.
			f := r.(record).finished
			f.CommandID = env.ID
			f.Seq = 1
			rec, ok = record{finished: f, startedAt: r.(record).startedAt}, true
			d.done.put(env.ID, rec)
		}
	}
	d.mu.Unlock()
	if ok {
		r := rec.(record)
		d.sink.Emit(Event{CommandID: env.ID, Seq: 0, Kind: KindStarted, At: r.startedAt})
		d.sink.Emit(r.finished)
		return false
	}
	ctx, cancel := context.WithCancel(d.ctx)
	d.mu.Lock()
	d.running[env.ID] = cancel
	d.mu.Unlock()
	d.wg.Add(1)
	go func() {
		defer d.wg.Done()
		defer cancel()
		d.run(ctx, env)
	}()
	return true
}

// Running returns ids of in-flight commands (for heartbeats).
func (d *Dispatcher) Running() []string {
	d.mu.Lock()
	defer d.mu.Unlock()
	out := make([]string, 0, len(d.running))
	for id := range d.running {
		out = append(out, id)
	}
	sort.Strings(out)
	return out
}

// Wait blocks until all running commands finished.
func (d *Dispatcher) Wait() { d.wg.Wait() }

func (d *Dispatcher) run(ctx context.Context, env Envelope) {
	st := newStream(env.ID, d.sink, d.now)
	startedAt := d.now()
	st.emit(Event{Kind: KindStarted, At: startedAt})

	timeout := time.Duration(env.TimeoutS) * time.Second
	if timeout <= 0 {
		timeout = DefaultTimeout
	}
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	var (
		result any
		err    error
	)
	ex, ok := d.reg.Get(env.Type)
	if !ok {
		err = &PayloadError{fmt.Errorf("unknown command type %q", env.Type)}
	} else {
		result, err = safeExecute(cctx, ex, env, st)
	}
	st.flush()

	fin := Event{Kind: KindFinished, At: d.now(), Result: result}
	code := 0
	if err != nil {
		code = 1
		var ee *ExitError
		switch {
		case errors.Is(cctx.Err(), context.DeadlineExceeded):
			code = ExitTimeout
			err = fmt.Errorf("timed out after %s: %w", timeout, err)
		case errors.As(err, &ee):
			code = ee.Code
		case IsPayloadError(err):
			code = 2
		}
		fin.Error = err.Error()
		d.log.Warn("command failed", "id", env.ID, "type", env.Type, "err", err)
	} else {
		d.log.Info("command finished", "id", env.ID, "type", env.Type, "ms", d.now().Sub(startedAt).Milliseconds())
	}
	fin.ExitCode = &code
	fin = st.emit(fin)

	d.mu.Lock()
	delete(d.running, env.ID)
	rec := record{finished: fin, startedAt: startedAt}
	d.done.put(env.ID, rec)
	// Only successful results are reused for idempotency keys; failures may be retried.
	if env.IdempotencyKey != "" && err == nil {
		d.byKey.put(env.IdempotencyKey, rec)
	}
	snapshot := d.journalAppendLocked(env, rec, err == nil)
	d.journalSeq++
	seq := d.journalSeq
	d.mu.Unlock()
	if snapshot != nil {
		d.writeJournal(seq, snapshot)
	}
}

func safeExecute(ctx context.Context, ex Executor, env Envelope, s Stream) (res any, err error) {
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("panic: %v\n%s", r, debug.Stack())
		}
	}()
	return ex.Execute(ctx, env, s)
}

// lru is a tiny bounded map.
type lru struct {
	cap int
	ll  *list.List
	m   map[string]*list.Element
}

type lruEntry struct {
	k string
	v any
}

func newLRU(n int) *lru { return &lru{cap: n, ll: list.New(), m: map[string]*list.Element{}} }

func (l *lru) get(k string) (any, bool) {
	if e, ok := l.m[k]; ok {
		l.ll.MoveToFront(e)
		return e.Value.(*lruEntry).v, true
	}
	return nil, false
}

func (l *lru) put(k string, v any) {
	if e, ok := l.m[k]; ok {
		e.Value.(*lruEntry).v = v
		l.ll.MoveToFront(e)
		return
	}
	l.m[k] = l.ll.PushFront(&lruEntry{k, v})
	if l.ll.Len() > l.cap {
		last := l.ll.Back()
		l.ll.Remove(last)
		delete(l.m, last.Value.(*lruEntry).k)
	}
}
