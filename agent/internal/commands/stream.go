package commands

import (
	"io"
	"sync"
	"time"
	"unicode/utf8"
)

// maxChunk bounds the data of one output event.
const maxChunk = 32 << 10

// flushEvery bounds latency of buffered output.
const flushEvery = 200 * time.Millisecond

// stream assigns per-command seq numbers and coalesces output writes into chunked events.
type stream struct {
	id   string
	sink EventSink
	now  func() time.Time

	mu    sync.Mutex
	seq   int64
	bufs  map[string][]byte
	timer *time.Timer
	out   io.Writer
	err   io.Writer
}

func newStream(id string, sink EventSink, now func() time.Time) *stream {
	s := &stream{id: id, sink: sink, now: now, bufs: map[string][]byte{}}
	s.out = streamWriter{s, "stdout"}
	s.err = streamWriter{s, "stderr"}
	return s
}

// NewTestStream returns a Stream that forwards events to sink (for executor unit tests).
func NewTestStream(id string, sink EventSink) interface {
	Stream
	Flush()
} {
	return testStream{newStream(id, sink, time.Now)}
}

type testStream struct{ *stream }

func (t testStream) Flush() { t.flush() }

func (s *stream) Stdout() io.Writer { return s.out }
func (s *stream) Stderr() io.Writer { return s.err }

func (s *stream) Progress(p float64) {
	if p < 0 {
		p = 0
	}
	if p > 1 {
		p = 1
	}
	s.flush()
	s.emit(Event{Kind: KindProgress, At: s.now(), Progress: &p})
}

func (s *stream) Emit(streamName, data string) {
	s.flush()
	s.emit(Event{Kind: KindOutput, At: s.now(), Stream: streamName, Data: data})
}

// emit assigns the next seq and forwards; returns the event as sent.
func (s *stream) emit(ev Event) Event {
	s.mu.Lock()
	ev.CommandID = s.id
	ev.Seq = s.seq
	s.seq++
	s.mu.Unlock()
	s.sink.Emit(ev)
	return ev
}

func (s *stream) write(name string, p []byte) {
	s.mu.Lock()
	s.bufs[name] = append(s.bufs[name], p...)
	full := len(s.bufs[name]) >= maxChunk
	if !full && s.timer == nil {
		s.timer = time.AfterFunc(flushEvery, s.timedFlush)
	}
	s.mu.Unlock()
	if full {
		s.timedFlush()
	}
}

// flush emits all buffered output (end of command, before progress/finished events).
func (s *stream) flush() { s.flushMode(true) }

func (s *stream) timedFlush() { s.flushMode(false) }

// flushMode emits buffered output in chunks ≤ maxChunk. Unless final, an incomplete trailing UTF-8
// sequence is kept buffered so `data` stays valid text across chunk boundaries.
func (s *stream) flushMode(final bool) {
	s.mu.Lock()
	if s.timer != nil {
		s.timer.Stop()
		s.timer = nil
	}
	type chunk struct{ name, data string }
	var chunks []chunk
	for _, name := range []string{"stdout", "stderr"} {
		b := s.bufs[name]
		var keep []byte
		if !final {
			if k := incompleteSuffix(b); k > 0 {
				keep = append([]byte(nil), b[len(b)-k:]...)
				b = b[:len(b)-k]
			}
		}
		for len(b) > 0 {
			n := len(b)
			if n > maxChunk {
				n = maxChunk
				for n > maxChunk-utf8.UTFMax && !utf8.RuneStart(b[n]) {
					n--
				}
			}
			chunks = append(chunks, chunk{name, string(b[:n])})
			b = b[n:]
		}
		s.bufs[name] = keep
		if len(keep) > 0 && s.timer == nil {
			s.timer = time.AfterFunc(flushEvery, s.timedFlush)
		}
	}
	s.mu.Unlock()
	for _, c := range chunks {
		s.emit(Event{Kind: KindOutput, At: s.now(), Stream: c.name, Data: c.data})
	}
}

// incompleteSuffix returns the length of a trailing, not-yet-complete UTF-8 sequence (0 if none).
func incompleteSuffix(b []byte) int {
	for i := 1; i <= utf8.UTFMax-1 && i <= len(b); i++ {
		c := b[len(b)-i]
		if utf8.RuneStart(c) {
			if c >= 0xC0 && !utf8.FullRune(b[len(b)-i:]) {
				return i
			}
			return 0
		}
	}
	return 0
}

type streamWriter struct {
	s    *stream
	name string
}

func (w streamWriter) Write(p []byte) (int, error) {
	w.s.write(w.name, p)
	return len(p), nil
}

// Collector is an EventSink that stores events in memory (tests and in-process consumers).
type Collector struct {
	mu     sync.Mutex
	Events []Event
}

func (c *Collector) Emit(ev Event) {
	c.mu.Lock()
	c.Events = append(c.Events, ev)
	c.mu.Unlock()
}

// Snapshot returns a copy of collected events.
func (c *Collector) Snapshot() []Event {
	c.mu.Lock()
	defer c.mu.Unlock()
	return append([]Event(nil), c.Events...)
}

// Output concatenates output data of the given stream ("" = all).
func (c *Collector) Output(stream string) string {
	var out string
	for _, e := range c.Snapshot() {
		if e.Kind == KindOutput && (stream == "" || e.Stream == stream) {
			out += e.Data
		}
	}
	return out
}
