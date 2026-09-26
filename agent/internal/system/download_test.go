package system

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func fastRetries(t *testing.T) {
	t.Helper()
	attempts, backoff := DownloadAttempts, DownloadBackoff
	DownloadAttempts, DownloadBackoff = 4, time.Millisecond
	t.Cleanup(func() { DownloadAttempts, DownloadBackoff = attempts, backoff })
}

func TestDownloadRetriesTransientFailures(t *testing.T) {
	fastRetries(t)
	body := []byte("frankenphp binary")
	var calls atomic.Int32
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch calls.Add(1) {
		case 1:
			w.WriteHeader(http.StatusBadGateway)
		case 2: // truncated body: promise more bytes than we send, then drop the connection
			w.Header().Set("Content-Length", "1000")
			w.Write(body[:5])
			hj, _ := w.(http.Hijacker)
			conn, _, _ := hj.Hijack()
			conn.Close()
		default:
			w.Write(body)
		}
	}))
	defer srv.Close()

	sum := sha256.Sum256(body)
	dst := filepath.Join(t.TempDir(), "bin")
	if _, _, err := Download(context.Background(), srv.Client(), srv.URL+"/f", hex.EncodeToString(sum[:]), dst, 0o755, nil); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(dst); string(got) != string(body) || calls.Load() != 3 {
		t.Fatalf("calls=%d body=%q", calls.Load(), got)
	}
}

func TestDownloadDoesNotRetryPermanentFailures(t *testing.T) {
	fastRetries(t)
	for name, handler := range map[string]http.HandlerFunc{
		"not found":         func(w http.ResponseWriter, r *http.Request) { http.NotFound(w, r) },
		"checksum mismatch": func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("tampered")) },
	} {
		t.Run(name, func(t *testing.T) {
			var calls atomic.Int32
			srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { calls.Add(1); handler(w, r) }))
			defer srv.Close()
			dst := filepath.Join(t.TempDir(), "bin")
			_, _, err := Download(context.Background(), srv.Client(), srv.URL+"/f", strings.Repeat("0", 64), dst, 0o644, nil)
			if err == nil || calls.Load() != 1 {
				t.Fatalf("err=%v calls=%d", err, calls.Load())
			}
			if _, statErr := os.Stat(dst); !os.IsNotExist(statErr) {
				t.Fatal("nothing may be written on failure")
			}
		})
	}
}

func TestDownloadGivesUpAfterMaxAttemptsAndHonoursCancel(t *testing.T) {
	fastRetries(t)
	var calls atomic.Int32
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer srv.Close()

	if _, _, err := Download(context.Background(), srv.Client(), srv.URL+"/f", "", filepath.Join(t.TempDir(), "x"), 0o644, nil); err == nil || calls.Load() != 4 {
		t.Fatalf("err=%v calls=%d", err, calls.Load())
	}

	DownloadBackoff = time.Hour
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	start := time.Now()
	if _, _, err := Download(ctx, srv.Client(), srv.URL+"/f", "", filepath.Join(t.TempDir(), "y"), 0o644, nil); err == nil || time.Since(start) > 5*time.Second {
		t.Fatalf("cancel not honoured: err=%v after %s", err, time.Since(start))
	}
}
