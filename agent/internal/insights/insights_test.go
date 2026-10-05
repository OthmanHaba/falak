package insights

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/otlp"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

type fakePoster struct {
	mu    sync.Mutex
	lines []map[string]any
	fail  bool
}

func (f *fakePoster) PostInsights(_ context.Context, b []byte) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.fail {
		return errors.New("down")
	}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		var m map[string]any
		if err := json.Unmarshal(sc.Bytes(), &m); err != nil {
			panic(err)
		}
		f.lines = append(f.lines, m)
	}
	return nil
}

func (f *fakePoster) byKind(k string) []map[string]any {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []map[string]any
	for _, l := range f.lines {
		if l["kind"] == k {
			out = append(out, l)
		}
	}
	return out
}

var base = time.Date(2026, 9, 26, 10, 5, 0, 0, time.UTC)

func span(name string, startOff, durMs int, attrs ...*commonpb.KeyValue) *tracepb.Span {
	st := base.Add(time.Duration(startOff) * time.Second)
	return &tracepb.Span{
		TraceId: bytes.Repeat([]byte{1}, 16), SpanId: []byte{1, 2, 3, 4, 5, 6, 7, 8}, Name: name,
		StartTimeUnixNano: uint64(st.UnixNano()), EndTimeUnixNano: uint64(st.Add(time.Duration(durMs) * time.Millisecond).UnixNano()),
		Attributes: attrs,
	}
}

func batch(spans ...*tracepb.Span) []*tracepb.ResourceSpans {
	return []*tracepb.ResourceSpans{{
		Resource:   &resourcepb.Resource{Attributes: []*commonpb.KeyValue{otlp.Str("falak.site.id", "01SITE")}},
		ScopeSpans: []*tracepb.ScopeSpans{{Spans: spans}},
	}}
}

func TestExceptionLine(t *testing.T) {
	p := &fakePoster{}
	now := base
	tee := New(p, func() time.Time { return now }, nil)
	root := span("GET /orders/{id}", 1, 120, otlp.Str("falak.event.type", "request"), otlp.Str("http.route", "/orders/{id}"),
		otlp.Str("http.request.method", "GET"), otlp.Str("enduser.id", "42"))
	child := span("select", 1, 5, otlp.Str("falak.event.type", "query"), otlp.Str("db.query.text", "select * from t where id = 5"))
	child.Status = &tracepb.Status{Code: tracepb.Status_STATUS_CODE_ERROR}
	child.Events = []*tracepb.Span_Event{{Name: "exception", TimeUnixNano: uint64(base.Add(time.Second).UnixNano()), Attributes: []*commonpb.KeyValue{
		otlp.Str("exception.type", "PDOException"), otlp.Str("exception.message", "gone away"), otlp.Str("exception.stacktrace", "#0 main"),
	}}}
	tee.ObserveSpans(batch(root, child))
	if err := tee.Flush(context.Background()); err != nil {
		t.Fatal(err)
	}
	ex := p.byKind("exception")
	if len(ex) != 1 {
		t.Fatalf("exceptions: %v", p.lines)
	}
	want := map[string]any{"trace_id": "01010101010101010101010101010101", "span_id": "0102030405060708", "site_id": "01SITE",
		"type": "PDOException", "message": "gone away", "stacktrace": "#0 main", "handled": false, "user_id": "42",
		"event_type": "query", "route_or_name": "select * from t where id = ?", "at": "2026-09-26T10:05:01Z"}
	for k, v := range want {
		if ex[0][k] != v {
			t.Errorf("%s = %#v want %#v", k, ex[0][k], v)
		}
	}
	// Aggregates of the current minute must not be flushed yet.
	if n := len(p.byKind("aggregate")); n != 0 {
		t.Fatalf("premature aggregates: %d", n)
	}
}

func TestAggregatesPerMinute(t *testing.T) {
	p := &fakePoster{}
	now := base
	tee := New(p, func() time.Time { return now }, nil)
	var spans []*tracepb.Span
	for i := 1; i <= 100; i++ {
		attrs := []*commonpb.KeyValue{otlp.Str("falak.event.type", "request"), otlp.Str("http.route", "/api/items"),
			otlp.Str("http.request.method", "GET"), otlp.Any("http.response.status_code", int64(200))}
		if i%10 == 0 {
			attrs[3] = otlp.Any("http.response.status_code", int64(503))
		}
		spans = append(spans, span("GET /api/items", 0, i, attrs...))
	}
	spans = append(spans, span("App\\Jobs\\Ship", 2, 50, otlp.Str("falak.event.type", "job"), otlp.Str("falak.job.class", "App\\Jobs\\Ship"), otlp.Str("falak.job.status", "failed")))
	// Next minute: must stay open.
	spans = append(spans, span("GET /api/items", 61, 1, otlp.Str("falak.event.type", "request"), otlp.Str("http.route", "/api/items")))
	tee.ObserveSpans(batch(spans...))

	now = base.Add(time.Minute + Grace - time.Second) // inside grace → nothing
	_ = tee.Flush(context.Background())
	if n := len(p.byKind("aggregate")); n != 0 {
		t.Fatalf("flushed during grace: %d", n)
	}
	now = base.Add(time.Minute + Grace)
	_ = tee.Flush(context.Background())
	aggs := p.byKind("aggregate")
	if len(aggs) != 2 {
		t.Fatalf("aggregates: %v", aggs)
	}
	var req, job map[string]any
	for _, a := range aggs {
		switch a["event_type"] {
		case "request":
			req = a
		case "job":
			job = a
		}
	}
	checks := map[string]any{"name": "GET /api/items", "count": 100.0, "p50_ms": 50.0, "p95_ms": 95.0, "max_ms": 100.0, "errors": 10.0,
		"minute": "2026-09-26T10:05:00Z", "site_id": "01SITE"}
	for k, v := range checks {
		if req[k] != v {
			t.Errorf("request %s = %#v want %#v", k, req[k], v)
		}
	}
	if job["name"] != "App\\Jobs\\Ship" || job["errors"] != 1.0 {
		t.Errorf("job aggregate %v", job)
	}
	now = base.Add(2*time.Minute + Grace)
	_ = tee.Flush(context.Background())
	if n := len(p.byKind("aggregate")); n != 3 {
		t.Fatalf("second minute not flushed: %d", n)
	}
}

func TestFlushRetriesKeepLines(t *testing.T) {
	p := &fakePoster{fail: true}
	tee := New(p, func() time.Time { return base }, nil)
	sp := span("x", 0, 1)
	sp.Events = []*tracepb.Span_Event{{Name: "exception"}}
	tee.ObserveSpans(batch(sp))
	if err := tee.Flush(context.Background()); err == nil {
		t.Fatal("expected error")
	}
	if n, _, _ := tee.Stats(); n != 1 {
		t.Fatalf("pending %d", n)
	}
	p.fail = false
	if err := tee.Flush(context.Background()); err != nil || len(p.byKind("exception")) != 1 {
		t.Fatalf("retry failed: %v", err)
	}
	// Exceptions on spans without falak.event.type are still reported; handled=true when status not ERROR.
	if p.byKind("exception")[0]["handled"] != true {
		t.Fatal("handled default")
	}
}

func TestDisabled(t *testing.T) {
	p := &fakePoster{}
	tee := New(p, func() time.Time { return base }, nil)
	tee.SetEnabled(false)
	sp := span("x", 0, 1)
	sp.Events = []*tracepb.Span_Event{{Name: "exception"}}
	tee.ObserveSpans(batch(sp))
	_ = tee.Flush(context.Background())
	if len(p.lines) != 0 {
		t.Fatal("disabled tee posted")
	}
}

func TestQueryShape(t *testing.T) {
	for in, want := range map[string]string{
		"select * from users where id = 5 and name = 'bob'": "select * from users where id = ? and name = ?",
		"select * from t where id in (1, 2, 3)":             "select * from t where id in (?)",
		"insert into t (a,b) values (1,'x'), (2,'y')":       "insert into t (a,b) values (?)",
		"select   *\n from t2":                              "select * from t2",
	} {
		if got := QueryShape(in); got != want {
			t.Errorf("%q → %q want %q", in, got, want)
		}
	}
}
