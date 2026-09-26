package otlp

import (
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"mime"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// MaxBody bounds a single request (decompressed).
const MaxBody = 16 << 20

// Handler returns the OTLP/HTTP handler for /v1/traces, /v1/logs and /v1/metrics.
func (r *Relay) Handler() http.Handler {
	mux := http.NewServeMux()
	for _, sig := range []Signal{Traces, Logs, Metrics} {
		mux.Handle(sig.Path(), receiverHandler{r, sig})
	}
	return mux
}

type receiverHandler struct {
	r   *Relay
	sig Signal
}

func (h receiverHandler) ServeHTTP(w http.ResponseWriter, req *http.Request) {
	if req.Method != http.MethodPost {
		w.Header().Set("Allow", "POST")
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	ct, _, _ := mime.ParseMediaType(req.Header.Get("Content-Type"))
	var isJSON bool
	switch ct {
	case "application/x-protobuf", "application/protobuf":
	case "application/json":
		isJSON = true
	default:
		http.Error(w, "unsupported content type", http.StatusUnsupportedMediaType)
		return
	}
	var body io.Reader = http.MaxBytesReader(w, req.Body, MaxBody)
	if req.Header.Get("Content-Encoding") == "gzip" {
		zr, err := gzip.NewReader(body)
		if err != nil {
			http.Error(w, "bad gzip body", http.StatusBadRequest)
			return
		}
		defer zr.Close()
		body = io.LimitReader(zr, MaxBody)
	}
	data, err := io.ReadAll(body)
	if err != nil {
		http.Error(w, "read body: "+err.Error(), http.StatusBadRequest)
		return
	}
	var ok bool
	switch h.sig {
	case Traces:
		rs, err2 := decodeEither(data, isJSON, DecodeTraces, DecodeTracesJSON)
		err = err2
		if err == nil {
			ok = len(rs) == 0 || h.r.SubmitTraces(rs)
		}
	case Logs:
		rl, err2 := decodeEither(data, isJSON, DecodeLogs, DecodeLogsJSON)
		err = err2
		if err == nil {
			ok = len(rl) == 0 || h.r.SubmitLogs(rl)
		}
	case Metrics:
		rm, err2 := decodeEither(data, isJSON, DecodeMetrics, DecodeMetricsJSON)
		err = err2
		if err == nil {
			ok = len(rm) == 0 || h.r.SubmitMetrics(rm)
		}
	}
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	if !ok {
		w.Header().Set("Retry-After", "1")
		http.Error(w, "queue full", http.StatusServiceUnavailable)
		return
	}
	// Empty Export*ServiceResponse.
	if isJSON {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte("{}"))
		return
	}
	w.Header().Set("Content-Type", "application/x-protobuf")
	w.WriteHeader(http.StatusOK)
}

func decodeEither[T any](data []byte, isJSON bool, pb, js func([]byte) ([]T, error)) ([]T, error) {
	if isJSON {
		return js(data)
	}
	return pb(data)
}

// Listeners returned by Serve.
type Listeners struct {
	Unix string // socket path ("" when disabled)
	TCP  string // resolved host:port ("" when disabled)
}

// Serve listens on a unix socket and/or TCP address and serves the receiver until ctx is done.
// The unix socket is made world-writable (0666) so every site user's PHP process can write to it.
func (r *Relay) Serve(ctx context.Context, unixPath, tcpAddr string) (Listeners, error) {
	var out Listeners
	var lns []net.Listener
	closeAll := func() {
		for _, l := range lns {
			l.Close()
		}
	}
	if unixPath != "" {
		if err := os.MkdirAll(filepath.Dir(unixPath), 0o755); err != nil {
			return out, err
		}
		if fi, err := os.Lstat(unixPath); err == nil && fi.Mode()&os.ModeSocket != 0 {
			_ = os.Remove(unixPath)
		}
		l, err := net.Listen("unix", unixPath)
		if err != nil {
			return out, fmt.Errorf("otlp: listen unix %s: %w", unixPath, err)
		}
		_ = os.Chmod(unixPath, 0o666)
		lns = append(lns, l)
		out.Unix = unixPath
	}
	if tcpAddr != "" {
		l, err := net.Listen("tcp", tcpAddr)
		if err != nil {
			closeAll()
			return out, fmt.Errorf("otlp: listen tcp %s: %w", tcpAddr, err)
		}
		lns = append(lns, l)
		out.TCP = l.Addr().String()
	}
	srv := &http.Server{Handler: r.Handler(), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 30 * time.Second, IdleTimeout: 120 * time.Second}
	for _, l := range lns {
		go func(l net.Listener) {
			if err := srv.Serve(l); err != nil && !errors.Is(err, http.ErrServerClosed) {
				r.log.Warn("otlp receiver stopped", "addr", l.Addr().String(), "err", err)
			}
		}(l)
	}
	go func() {
		<-ctx.Done()
		sctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_ = srv.Shutdown(sctx)
		if unixPath != "" {
			_ = os.Remove(unixPath)
		}
	}()
	return out, nil
}
