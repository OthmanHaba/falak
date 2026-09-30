package fngateway

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/rand"
	"io"
	"log/slog"
	"mime"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/kiln/agent/internal/otlp"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
)

// Telemetry of functions. Every function gets its own OTLP/HTTP socket (<state>/<site>/otlp/otlp.sock, mounted
// read-only at /run/kiln-otlp in its containers). The gateway stamps the function's identity on everything that
// arrives there, so a function can only report as itself, and relays it to the agent's receiver, which feeds
// Insights (the Observability tab) and the observability pipeline. Requests the gateway answers itself (start
// failures, timeouts, a full queue) are reported by the gateway.

const (
	// OTLPMount is where a container sees its function's telemetry socket.
	OTLPMount = "/run/kiln-otlp"
	// OTLPSocketEnv names the socket for the runtime.
	OTLPSocketEnv = "KILN_OTLP_SOCKET"
	// ColdStartHeader marks a request that waited for an instance to start ("1").
	ColdStartHeader = "X-Kiln-Cold-Start"

	otlpSocketName = "otlp.sock"
	maxOTLPBody    = 4 << 20
)

// otlpDir is the function's telemetry directory: next to its releases.
func otlpDir(releaseDir string) string {
	return filepath.Join(filepath.Dir(filepath.Dir(releaseDir)), "otlp")
}

type relayListener struct {
	dir string
	ln  net.Listener
	srv *http.Server
}

type telemetry struct {
	agent    *http.Client // the agent's OTLP receiver (unix socket)
	identity func(site string) ([]*commonpb.KeyValue, bool)
	log      *slog.Logger

	mu     sync.Mutex
	relays map[string]*relayListener
}

func newTelemetry(agentSocket string, identity func(string) ([]*commonpb.KeyValue, bool), log *slog.Logger) *telemetry {
	return &telemetry{
		agent: &http.Client{
			Timeout: 5 * time.Second,
			Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				var d net.Dialer
				return d.DialContext(ctx, "unix", agentSocket)
			}},
		},
		identity: identity,
		log:      log,
		relays:   map[string]*relayListener{},
	}
}

// ensure opens the function's telemetry socket (idempotent).
func (t *telemetry) ensure(site, dir string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if r := t.relays[site]; r != nil && r.dir == dir {
		return
	} else if r != nil {
		r.close()
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.log.Warn("function telemetry dir", "site", site, "err", err)
		return
	}
	sock := filepath.Join(dir, otlpSocketName)
	_ = os.Remove(sock)
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.log.Warn("function telemetry socket", "site", site, "err", err)
		return
	}
	// Runtimes run as nobody.
	_ = os.Chmod(sock, 0o666)
	srv := &http.Server{Handler: t.handler(site), ReadHeaderTimeout: 10 * time.Second}
	go func() { _ = srv.Serve(ln) }()
	t.relays[site] = &relayListener{dir: dir, ln: ln, srv: srv}
}

func (t *telemetry) remove(site string) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if r := t.relays[site]; r != nil {
		r.close()
		delete(t.relays, site)
	}
}

func (t *telemetry) closeAll() {
	t.mu.Lock()
	defer t.mu.Unlock()
	for site, r := range t.relays {
		r.close()
		delete(t.relays, site)
	}
}

func (r *relayListener) close() {
	_ = r.srv.Close()
	_ = os.Remove(filepath.Join(r.dir, otlpSocketName))
}

// handler relays POST /v1/{traces,logs,metrics} of one function to the agent.
func (t *telemetry) handler(site string) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		sig := otlp.Signal(strings.TrimPrefix(req.URL.Path, "/v1/"))
		if req.Method != http.MethodPost || (sig != otlp.Traces && sig != otlp.Logs && sig != otlp.Metrics) {
			http.Error(w, "not found", http.StatusNotFound)
			return
		}
		ct, _, _ := mime.ParseMediaType(req.Header.Get("Content-Type"))
		isJSON := ct == "application/json"
		if !isJSON && ct != "application/x-protobuf" && ct != "application/protobuf" {
			http.Error(w, "unsupported content type", http.StatusUnsupportedMediaType)
			return
		}
		var body io.Reader = http.MaxBytesReader(w, req.Body, maxOTLPBody)
		if req.Header.Get("Content-Encoding") == "gzip" {
			zr, err := gzip.NewReader(body)
			if err != nil {
				http.Error(w, "bad gzip body", http.StatusBadRequest)
				return
			}
			defer zr.Close()
			body = io.LimitReader(zr, maxOTLPBody)
		}
		data, err := io.ReadAll(body)
		if err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		id, ok := t.identity(site)
		if !ok {
			http.Error(w, "unknown function", http.StatusNotFound)
			return
		}
		out, err := restamp(sig, data, isJSON, id)
		if err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		status := t.forward(req.Context(), sig, out)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		if status == http.StatusOK {
			_, _ = w.Write([]byte("{}"))
		}
	})
}

// restamp decodes a batch, replaces the identity on every resource and encodes it as protobuf.
func restamp(sig otlp.Signal, data []byte, isJSON bool, id []*commonpb.KeyValue) ([]byte, error) {
	switch sig {
	case otlp.Traces:
		rs, err := decode(data, isJSON, otlp.DecodeTraces, otlp.DecodeTracesJSON)
		if err != nil {
			return nil, err
		}
		for _, r := range rs {
			r.Resource = stamp(r.Resource, id)
		}
		return otlp.EncodeTraces(rs)
	case otlp.Logs:
		rl, err := decode(data, isJSON, otlp.DecodeLogs, otlp.DecodeLogsJSON)
		if err != nil {
			return nil, err
		}
		for _, r := range rl {
			r.Resource = stamp(r.Resource, id)
		}
		return otlp.EncodeLogs(rl)
	default:
		rm, err := decode(data, isJSON, otlp.DecodeMetrics, otlp.DecodeMetricsJSON)
		if err != nil {
			return nil, err
		}
		for _, r := range rm {
			r.Resource = stamp(r.Resource, id)
		}
		return otlp.EncodeMetrics(rm)
	}
}

func decode[T any](data []byte, isJSON bool, pb, js func([]byte) ([]T, error)) ([]T, error) {
	if isJSON {
		return js(data)
	}
	return pb(data)
}

// stamp drops what a function claims about itself (service.name and every kiln.* attribute) and sets the
// gateway's view; the agent adds the server, organization and host.
func stamp(res *resourcepb.Resource, id []*commonpb.KeyValue) *resourcepb.Resource {
	if res == nil {
		res = &resourcepb.Resource{}
	}
	kept := res.Attributes[:0]
	for _, kv := range res.Attributes {
		if kv.GetKey() == "service.name" || strings.HasPrefix(kv.GetKey(), "kiln.") {
			continue
		}
		kept = append(kept, kv)
	}
	res.Attributes = append(kept, id...)
	return res
}

func (t *telemetry) forward(ctx context.Context, sig otlp.Signal, body []byte) int {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, "http://agent"+sig.Path(), bytes.NewReader(body))
	if err != nil {
		return http.StatusInternalServerError
	}
	req.Header.Set("Content-Type", "application/x-protobuf")
	resp, err := t.agent.Do(req)
	if err != nil {
		t.log.Debug("function telemetry: agent receiver unavailable", "err", err)
		return http.StatusServiceUnavailable
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)
	if resp.StatusCode >= 300 {
		return resp.StatusCode
	}
	return http.StatusOK
}

// requestSpan reports a request the gateway answered itself (the runtime never saw it) as a `request` event.
func (t *telemetry) requestSpan(site string, r *http.Request, status int, start time.Time, cause string) {
	id, ok := t.identity(site)
	if !ok {
		return
	}
	now := time.Now()
	sp := &tracepb.Span{
		TraceId:           randBytes(16),
		SpanId:            randBytes(8),
		Name:              r.Method + " " + gatewayRoute,
		Kind:              tracepb.Span_SPAN_KIND_SERVER,
		StartTimeUnixNano: uint64(start.UnixNano()),
		EndTimeUnixNano:   uint64(now.UnixNano()),
		Attributes: []*commonpb.KeyValue{
			otlp.Str("kiln.event.type", "request"),
			otlp.Str("http.request.method", r.Method),
			otlp.Str("http.route", gatewayRoute),
			otlp.Str("url.path", r.URL.Path),
			otlp.Any("http.response.status_code", int64(status)),
			otlp.Str("kiln.function.gateway_error", cause),
		},
	}
	if status >= 500 {
		sp.Status = &tracepb.Status{Code: tracepb.Status_STATUS_CODE_ERROR, Message: cause}
	}
	rs := []*tracepb.ResourceSpans{{
		Resource:   stamp(nil, id),
		ScopeSpans: []*tracepb.ScopeSpans{{Scope: &commonpb.InstrumentationScope{Name: "kiln-fn-gateway"}, Spans: []*tracepb.Span{sp}}},
	}}
	body, err := otlp.EncodeTraces(rs)
	if err != nil {
		return
	}
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		t.forward(ctx, otlp.Traces, body)
	}()
}

// gatewayRoute groups the requests the gateway answered itself in the route list.
const gatewayRoute = "(function unavailable)"

func randBytes(n int) []byte {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return b
}
