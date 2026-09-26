package commands

import "time"

// EventStream is a Stream for producers outside the Dispatcher (e.g. kiln-builder) that need the same
// seq numbering, output coalescing/chunking and started/finished framing as agent commands.
type EventStream struct{ *stream }

// NewEventStream returns an EventStream emitting events for id into sink. now defaults to time.Now.
func NewEventStream(id string, sink EventSink, now func() time.Time) *EventStream {
	if now == nil {
		now = time.Now
	}
	return &EventStream{newStream(id, sink, now)}
}

// Started emits the `started` event (call once, first).
func (e *EventStream) Started() { e.emit(Event{Kind: KindStarted, At: e.now()}) }

// Flush emits buffered output immediately.
func (e *EventStream) Flush() { e.flush() }

// Finished flushes output and emits the `finished` event.
func (e *EventStream) Finished(exitCode int, result any, errMsg string) {
	e.flush()
	code := exitCode
	e.emit(Event{Kind: KindFinished, At: e.now(), ExitCode: &code, Result: result, Error: errMsg})
}
