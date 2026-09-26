package otlp

import (
	"crypto/rand"
	"strings"
	"time"

	"github.com/kiln/agent/internal/obs"
	"github.com/kiln/agent/internal/version"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	logspb "go.opentelemetry.io/proto/otlp/logs/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

var _ obs.Sink = (*Relay)(nil)

var agentScope = &commonpb.InstrumentationScope{Name: "kiln-agent", Version: version.Version}

// agentResource builds the resource for records produced by the agent itself.
func (r *Relay) agentResource(site, siteID, service string) *resourcepb.Resource {
	res := &resourcepb.Resource{}
	cfg := r.cfg.Load()
	if siteID != "" {
		res.Attributes = append(res.Attributes, Str("kiln.site.id", siteID))
	} else if site != "" {
		if s := cfg.siteBySlug(site); s != nil {
			res.Attributes = append(res.Attributes, Str("kiln.site.id", s.SiteID))
		}
	}
	switch {
	case service != "":
		res.Attributes = append(res.Attributes, Str("service.name", service))
	case site != "":
		res.Attributes = append(res.Attributes, Str("service.name", site))
	default:
		res.Attributes = append(res.Attributes, Str("service.name", "kiln-agent"))
	}
	return res
}

var severities = map[string]logspb.SeverityNumber{
	"TRACE": logspb.SeverityNumber_SEVERITY_NUMBER_TRACE,
	"DEBUG": logspb.SeverityNumber_SEVERITY_NUMBER_DEBUG,
	"INFO":  logspb.SeverityNumber_SEVERITY_NUMBER_INFO,
	"WARN":  logspb.SeverityNumber_SEVERITY_NUMBER_WARN,
	"ERROR": logspb.SeverityNumber_SEVERITY_NUMBER_ERROR,
	"FATAL": logspb.SeverityNumber_SEVERITY_NUMBER_FATAL,
}

// SeverityNumber maps a severity text to its OTLP number (unknown → INFO).
func SeverityNumber(s string) logspb.SeverityNumber {
	if n, ok := severities[strings.ToUpper(s)]; ok {
		return n
	}
	return logspb.SeverityNumber_SEVERITY_NUMBER_INFO
}

// EmitLog implements obs.Sink (non-blocking; dropped when the queue is full).
func (r *Relay) EmitLog(l obs.LogRecord) {
	t := l.Time
	if t.IsZero() {
		t = time.Now()
	}
	sev := strings.ToUpper(l.Severity)
	if sev == "" {
		sev = "INFO"
	}
	rec := &logspb.LogRecord{
		TimeUnixNano:         uint64(t.UnixNano()),
		ObservedTimeUnixNano: uint64(time.Now().UnixNano()),
		SeverityText:         sev,
		SeverityNumber:       SeverityNumber(sev),
		Body:                 AnyValue(l.Body),
		TraceId:              l.TraceID,
		SpanId:               l.SpanID,
	}
	for k, v := range l.Attrs {
		rec.Attributes = append(rec.Attributes, Str(k, v))
	}
	r.SubmitLogs([]*logspb.ResourceLogs{{
		Resource:  r.agentResource(l.Site, l.SiteID, l.Service),
		ScopeLogs: []*logspb.ScopeLogs{{Scope: agentScope, LogRecords: []*logspb.LogRecord{rec}}},
	}})
}

var spanKinds = map[string]tracepb.Span_SpanKind{
	"INTERNAL": tracepb.Span_SPAN_KIND_INTERNAL,
	"SERVER":   tracepb.Span_SPAN_KIND_SERVER,
	"CLIENT":   tracepb.Span_SPAN_KIND_CLIENT,
	"PRODUCER": tracepb.Span_SPAN_KIND_PRODUCER,
	"CONSUMER": tracepb.Span_SPAN_KIND_CONSUMER,
}

// EmitSpan implements obs.Sink.
func (r *Relay) EmitSpan(s obs.Span) {
	tid := make([]byte, 16)
	sid := make([]byte, 8)
	_, _ = rand.Read(tid)
	_, _ = rand.Read(sid)
	kind, ok := spanKinds[strings.ToUpper(s.Kind)]
	if !ok {
		kind = tracepb.Span_SPAN_KIND_INTERNAL
	}
	end := s.End
	if end.IsZero() {
		end = time.Now()
	}
	sp := &tracepb.Span{
		TraceId: tid, SpanId: sid, Name: s.Name, Kind: kind,
		StartTimeUnixNano: uint64(s.Start.UnixNano()), EndTimeUnixNano: uint64(end.UnixNano()),
	}
	for k, v := range s.Attrs {
		sp.Attributes = append(sp.Attributes, Any(k, v))
	}
	if s.Error {
		sp.Status = &tracepb.Status{Code: tracepb.Status_STATUS_CODE_ERROR, Message: s.ErrorMsg}
	}
	r.SubmitTraces([]*tracepb.ResourceSpans{{
		Resource:   r.agentResource(s.Site, s.SiteID, s.Service),
		ScopeSpans: []*tracepb.ScopeSpans{{Scope: agentScope, Spans: []*tracepb.Span{sp}}},
	}})
}
