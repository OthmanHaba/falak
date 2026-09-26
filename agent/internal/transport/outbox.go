package transport

import (
	"context"
	"log/slog"
	"sort"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
)

// EventPoster is the subset of Client used by the Outbox.
type EventPoster interface {
	PostEvents(ctx context.Context, commandID string, evs []commands.Event) error
}

// Outbox is the commands.EventSink used in production. Events are queued per command and flushed in
// NDJSON batches (every FlushInterval, or immediately when a command finishes). Failed posts are retried
// with backoff; events are only dropped when the queue exceeds MaxPending, and then only `output`
// events (started/progress/finished are always kept). Delivery is at-least-once; the control plane
// dedupes on (command_id, seq).
type Outbox struct {
	poster        EventPoster
	log           *slog.Logger
	FlushInterval time.Duration
	MaxBatch      int
	MaxPending    int

	mu      sync.Mutex
	queues  map[string][]commands.Event
	pending int
	dropped int
	kick    chan struct{}
}

// NewOutbox creates an outbox; call Run to start flushing.
func NewOutbox(p EventPoster, log *slog.Logger) *Outbox {
	if log == nil {
		log = slog.Default()
	}
	return &Outbox{
		poster: p, log: log, FlushInterval: 250 * time.Millisecond, MaxBatch: 500, MaxPending: 20000,
		queues: map[string][]commands.Event{}, kick: make(chan struct{}, 1),
	}
}

// Emit implements commands.EventSink. It never blocks on the network.
func (o *Outbox) Emit(ev commands.Event) {
	o.mu.Lock()
	o.queues[ev.CommandID] = append(o.queues[ev.CommandID], ev)
	o.pending++
	if o.pending > o.MaxPending {
		o.dropOutputLocked()
	}
	o.mu.Unlock()
	if ev.Kind == commands.KindFinished {
		select {
		case o.kick <- struct{}{}:
		default:
		}
	}
}

func (o *Outbox) dropOutputLocked() {
	for id, q := range o.queues {
		kept := q[:0]
		for _, e := range q {
			if e.Kind == commands.KindOutput && o.pending > o.MaxPending*9/10 {
				o.pending--
				o.dropped++
				continue
			}
			kept = append(kept, e)
		}
		o.queues[id] = kept
	}
	o.log.Warn("event outbox overflow; dropped output events", "dropped_total", o.dropped)
}

// Pending returns the number of queued events.
func (o *Outbox) Pending() int {
	o.mu.Lock()
	defer o.mu.Unlock()
	return o.pending
}

// Run flushes until ctx is done, then makes a best-effort final flush.
func (o *Outbox) Run(ctx context.Context) {
	bo := Backoff{Min: 500 * time.Millisecond, Max: 30 * time.Second}
	t := time.NewTicker(o.FlushInterval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			fctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			o.Flush(fctx)
			cancel()
			return
		case <-t.C:
		case <-o.kick:
		}
		if err := o.Flush(ctx); err != nil {
			d := bo.Next()
			o.log.Warn("posting events failed; retrying", "err", err, "in", d)
			if !sleep(ctx, d) {
				continue
			}
		} else {
			bo.Reset()
		}
	}
}

// Flush posts all queued events once; returns the first error (remaining events stay queued).
func (o *Outbox) Flush(ctx context.Context) error {
	o.mu.Lock()
	ids := make([]string, 0, len(o.queues))
	for id, q := range o.queues {
		if len(q) > 0 {
			ids = append(ids, id)
		}
	}
	o.mu.Unlock()
	sort.Strings(ids)
	var firstErr error
	for _, id := range ids {
		for {
			o.mu.Lock()
			q := o.queues[id]
			n := len(q)
			if n > o.MaxBatch {
				n = o.MaxBatch
			}
			batch := append([]commands.Event(nil), q[:n]...)
			o.mu.Unlock()
			if len(batch) == 0 {
				break
			}
			if err := o.poster.PostEvents(ctx, id, batch); err != nil {
				if !Retryable(err) {
					// 4xx (e.g. unknown/cancelled command): drop this command's events.
					o.log.Warn("events rejected; dropping", "command", id, "err", err)
					o.mu.Lock()
					o.pending -= len(o.queues[id])
					delete(o.queues, id)
					o.mu.Unlock()
					break
				}
				if firstErr == nil {
					firstErr = err
				}
				break
			}
			o.mu.Lock()
			// Remove exactly the posted prefix (new events may have been appended meanwhile).
			cur := o.queues[id]
			if len(cur) >= len(batch) {
				o.queues[id] = cur[len(batch):]
			} else {
				o.queues[id] = nil
			}
			o.pending -= len(batch)
			if len(o.queues[id]) == 0 {
				delete(o.queues, id)
			}
			o.mu.Unlock()
		}
	}
	return firstErr
}
