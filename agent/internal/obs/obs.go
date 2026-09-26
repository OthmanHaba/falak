// Package obs is the tiny internal telemetry interface used by subsystems (supervisor, cron, logs)
// to emit log records and spans into the OTLP relay without depending on it.
package obs

import "time"

// LogRecord is one log line.
type LogRecord struct {
	Time     time.Time
	Severity string // TRACE, DEBUG, INFO, WARN, ERROR, FATAL
	Body     string
	Site     string            // site slug → service.name + kiln.site.id resource attrs; "" = host/agent
	SiteID   string            // explicit kiln.site.id resource attr (wins over the slug lookup)
	Service  string            // overrides service.name when set (e.g. "kiln-agent", "caddy")
	Attrs    map[string]string // record attributes (e.g. process.name, log.file.path)
	TraceID  []byte
	SpanID   []byte
}

// Span is one finished span (e.g. a scheduled task run).
type Span struct {
	Name     string
	Kind     string // INTERNAL, SERVER, CLIENT, PRODUCER, CONSUMER
	Start    time.Time
	End      time.Time
	Site     string
	SiteID   string
	Service  string
	Attrs    map[string]any // string, int64, float64, bool
	Error    bool
	ErrorMsg string
}

// Sink receives records; implementations must be non-blocking (drop on overload).
type Sink interface {
	EmitLog(LogRecord)
	EmitSpan(Span)
}

// Nop discards everything.
type Nop struct{}

func (Nop) EmitLog(LogRecord) {}
func (Nop) EmitSpan(Span)     {}
