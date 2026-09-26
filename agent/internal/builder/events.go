package builder

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
)

// NDJSONSink writes one event per line (event.schema.json) to w.
type NDJSONSink struct {
	mu sync.Mutex
	w  io.Writer
}

// NewNDJSONSink returns a sink writing to w.
func NewNDJSONSink(w io.Writer) *NDJSONSink { return &NDJSONSink{w: w} }

func (s *NDJSONSink) Emit(ev commands.Event) {
	b, err := json.Marshal(ev)
	if err != nil {
		return
	}
	s.mu.Lock()
	_, _ = s.w.Write(append(b, '\n'))
	s.mu.Unlock()
}

// HTTPSink batches events and POSTs them as NDJSON to the control plane (EventsPath). Delivery is
// at-least-once; the control plane dedupes on (command_id, seq), like agent command events.
type HTTPSink struct {
	URL      string // absolute events URL
	Token    string
	Client   *http.Client
	Log      *slog.Logger
	Interval time.Duration
	// OnGone is called (once) when the control plane answers 410 Gone: the build was cancelled and
	// must be aborted. Pending events are dropped.
	OnGone func()

	mu      sync.Mutex
	gone    sync.Once
	pending []commands.Event
	kick    chan struct{}
	done    chan struct{}
	stopped chan struct{}
}

// Start begins background flushing; call Close to drain.
func (s *HTTPSink) Start() *HTTPSink {
	if s.Interval <= 0 {
		s.Interval = time.Second
	}
	if s.Client == nil {
		s.Client = http.DefaultClient
	}
	if s.Log == nil {
		s.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	s.kick, s.done, s.stopped = make(chan struct{}, 1), make(chan struct{}), make(chan struct{})
	go s.loop()
	return s
}

func (s *HTTPSink) Emit(ev commands.Event) {
	s.mu.Lock()
	s.pending = append(s.pending, ev)
	n := len(s.pending)
	s.mu.Unlock()
	if ev.Kind == commands.KindFinished || n >= 200 {
		select {
		case s.kick <- struct{}{}:
		default:
		}
	}
}

func (s *HTTPSink) loop() {
	defer close(s.stopped)
	t := time.NewTicker(s.Interval)
	defer t.Stop()
	for {
		select {
		case <-s.done:
			return
		case <-t.C:
		case <-s.kick:
		}
		_ = s.flush(context.Background())
	}
}

// Close stops the loop and flushes remaining events, retrying until ctx is done.
func (s *HTTPSink) Close(ctx context.Context) error {
	close(s.done)
	<-s.stopped
	for backoff := 250 * time.Millisecond; ; backoff *= 2 {
		err := s.flush(ctx)
		if err == nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return err
		case <-time.After(min(backoff, 5*time.Second)):
		}
	}
}

func (s *HTTPSink) flush(ctx context.Context) error {
	s.mu.Lock()
	batch := s.pending
	s.mu.Unlock()
	if len(batch) == 0 {
		return nil
	}
	var buf bytes.Buffer
	for _, ev := range batch {
		b, _ := json.Marshal(ev)
		buf.Write(b)
		buf.WriteByte('\n')
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.URL, &buf)
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/x-ndjson")
	req.Header.Set("Authorization", "Bearer "+s.Token)
	resp, err := s.Client.Do(req)
	if err != nil {
		s.Log.Warn("post build events", "err", err)
		return err
	}
	io.Copy(io.Discard, resp.Body)
	resp.Body.Close()
	if resp.StatusCode == http.StatusGone {
		s.Log.Warn("build cancelled by the control plane")
		s.mu.Lock()
		s.pending = s.pending[len(batch):]
		s.mu.Unlock()
		if s.OnGone != nil {
			s.gone.Do(s.OnGone)
		}
		return nil
	}
	if resp.StatusCode/100 != 2 {
		err := fmt.Errorf("post build events: HTTP %d", resp.StatusCode)
		s.Log.Warn("post build events", "err", err)
		return err
	}
	s.mu.Lock()
	s.pending = s.pending[len(batch):]
	s.mu.Unlock()
	return nil
}

// redactor masks secrets in output before it becomes an event.
type redactor struct {
	w       io.Writer
	secrets []string
}

func (r redactor) Write(p []byte) (int, error) {
	s := string(p)
	for _, sec := range r.secrets {
		s = strings.ReplaceAll(s, sec, "********")
	}
	if _, err := io.WriteString(r.w, s); err != nil {
		return 0, err
	}
	return len(p), nil
}
