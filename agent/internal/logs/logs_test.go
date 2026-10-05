package logs

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/obs"
)

type memSink struct {
	mu   sync.Mutex
	recs []obs.LogRecord
}

func (m *memSink) EmitLog(r obs.LogRecord) { m.mu.Lock(); m.recs = append(m.recs, r); m.mu.Unlock() }
func (m *memSink) EmitSpan(obs.Span)       {}
func (m *memSink) bodies() []string {
	m.mu.Lock()
	defer m.mu.Unlock()
	var out []string
	for _, r := range m.recs {
		out = append(out, r.Body)
	}
	return out
}

func appendFile(t *testing.T, p, s string) {
	f, err := os.OpenFile(p, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		t.Fatal(err)
	}
	f.WriteString(s)
	f.Close()
}

func TestTailRotationTruncationAndOffsets(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	logDir := fs.P("/srv/falak/sites/shop/shared/storage/logs")
	os.MkdirAll(logDir, 0o755)
	logPath := filepath.Join(logDir, "laravel.log")
	appendFile(t, logPath, "old history line\n")

	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetSources([]Source{{Path: "/srv/falak/sites/shop/shared/storage/logs/*.log", Site: "shop"}})
	tl.Poll()
	if n := len(sink.bodies()); n != 0 {
		t.Fatalf("pre-existing history shipped: %v", sink.bodies())
	}
	appendFile(t, logPath, "[2026-09-26 10:00:00] production.ERROR: boom\nhalf")
	tl.Poll()
	appendFile(t, logPath, " line\n")
	tl.Poll()
	// rename-rotation: old file gets one more line, then a new file appears at the path
	appendFile(t, logPath, "last in old\n")
	os.Rename(logPath, logPath+".1")
	appendFile(t, logPath, "first in new\n")
	tl.Poll()
	// copytruncate
	os.Truncate(logPath, 0)
	appendFile(t, logPath, "after truncate\n")
	tl.Poll()

	want := []string{"[2026-09-26 10:00:00] production.ERROR: boom", "half line", "last in old", "first in new", "after truncate"}
	if got := sink.bodies(); strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("got %q\nwant %q", got, want)
	}
	sink.mu.Lock()
	r0 := sink.recs[0]
	sink.mu.Unlock()
	if r0.Severity != "ERROR" || r0.Site != "shop" || r0.Attrs["log.file.path"] != "/srv/falak/sites/shop/shared/storage/logs/laravel.log" {
		t.Fatalf("record %+v", r0)
	}

	// Restart: offsets are persisted, so only new data is read.
	appendFile(t, logPath, "after restart\n")
	sink2 := &memSink{}
	tl2 := NewTailer(fs, "/var/lib/falak", sink2, nil)
	tl2.SetSources([]Source{{Path: "/srv/falak/sites/shop/shared/storage/logs/*.log", Site: "shop"}})
	tl2.Poll()
	if got := sink2.bodies(); len(got) != 1 || got[0] != "after restart" {
		t.Fatalf("after restart got %q", got)
	}
}

func TestTailNewFileFromStart(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	os.MkdirAll(fs.P("/var/log/app"), 0o755)
	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/falak", sink, nil)
	tl.SetSources([]Source{{Path: "/var/log/app/*.log", Format: "json", Service: "worker"}})
	tl.Poll()
	appendFile(t, fs.P("/var/log/app/w.log"), `{"message":"job done","level_name":"WARNING","datetime":"2026-09-26T10:00:00+00:00","trace_id":"5b8efff798038103d269b633813fc60c","queue":"default","attempt":2}`+"\n")
	tl.Poll()
	sink.mu.Lock()
	defer sink.mu.Unlock()
	if len(sink.recs) != 1 {
		t.Fatalf("recs %v", sink.recs)
	}
	r := sink.recs[0]
	if r.Body != "job done" || r.Severity != "WARN" || len(r.TraceID) != 16 || r.Attrs["queue"] != "default" || r.Attrs["attempt"] != "2" ||
		r.Service != "worker" || !r.Time.Equal(time.Date(2026, 9, 26, 10, 0, 0, 0, time.UTC)) {
		t.Fatalf("json record %+v", r)
	}
}

func frame(stream byte, s string) []byte {
	h := make([]byte, 8)
	h[0] = stream
	binary.BigEndian.PutUint32(h[4:], uint32(len(s)))
	return append(h, s...)
}

func TestDemux(t *testing.T) {
	var b bytes.Buffer
	b.Write(frame(1, "2026-09-26T10:00:00.000000001Z hello\n2026-09-26T10:00:01Z sp"))
	b.Write(frame(2, "2026-09-26T10:00:02Z oops\n"))
	b.Write(frame(1, "lit\n"))
	var got []string
	err := Demux(&b, func(s string, l []byte) { got = append(got, s+":"+string(l)) })
	if err == nil {
		t.Fatal("expected EOF")
	}
	want := "stdout:2026-09-26T10:00:00.000000001Z hello|stderr:2026-09-26T10:00:02Z oops|stdout:2026-09-26T10:00:01Z split"
	if strings.Join(got, "|") != want {
		t.Fatalf("got %q", got)
	}
}

func TestDockerFollower(t *testing.T) {
	sock := filepath.Join(os.TempDir(), fmt.Sprintf("falak-dock-%d.sock", time.Now().UnixNano()))
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer os.Remove(sock)
	var gotFilter string
	mux := http.NewServeMux()
	mux.HandleFunc("/containers/json", func(w http.ResponseWriter, r *http.Request) {
		gotFilter = r.URL.Query().Get("filters")
		json.NewEncoder(w).Encode([]map[string]any{{"Id": "abc123def456789", "Names": []string{"/shop-blue"}, "Labels": map[string]string{"falak.site": "shop"}}})
	})
	mux.HandleFunc("/containers/abc123def456789/json", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"Config":{"Tty":false}}`))
	})
	mux.HandleFunc("/containers/abc123def456789/logs", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("follow") != "1" || r.URL.Query().Get("timestamps") != "1" {
			t.Errorf("bad query %s", r.URL.RawQuery)
		}
		w.Write(frame(1, "2026-09-26T10:00:00Z GET / 200\n"))
		w.Write(frame(2, "2026-09-26T10:00:01Z PHP Fatal error ERROR: x\n"))
		w.(http.Flusher).Flush()
		<-r.Context().Done()
	})
	srv := &http.Server{Handler: mux}
	go srv.Serve(l)
	defer srv.Close()

	sink := &memSink{}
	d := NewDocker(sock, sink, nil)
	d.Configure(true, map[string]string{"falak.managed": "true"})
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	d.Sync(ctx)
	deadline := time.Now().Add(3 * time.Second)
	for len(sink.bodies()) < 2 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if !strings.Contains(gotFilter, "falak.managed=true") {
		t.Fatalf("label filter not sent: %q", gotFilter)
	}
	sink.mu.Lock()
	defer sink.mu.Unlock()
	if len(sink.recs) != 2 {
		t.Fatalf("recs: %+v", sink.recs)
	}
	r := sink.recs[1]
	if r.Body != "PHP Fatal error ERROR: x" || r.Attrs["log.iostream"] != "stderr" || r.Site != "shop" || r.Severity != "ERROR" ||
		r.Attrs["container.name"] != "shop-blue" || !r.Time.Equal(time.Date(2026, 9, 26, 10, 0, 1, 0, time.UTC)) {
		t.Fatalf("record %+v", r)
	}
}

func TestDockerFollowerComposeService(t *testing.T) {
	sock := filepath.Join(os.TempDir(), fmt.Sprintf("falak-dockc-%d.sock", time.Now().UnixNano()))
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	defer os.Remove(sock)
	mux := http.NewServeMux()
	mux.HandleFunc("/containers/json", func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode([]map[string]any{{"Id": "c0mp05e", "Names": []string{"/shop-redis-1"}, "Created": time.Now().Add(-20 * time.Second).Unix(),
			"Labels": map[string]string{"falak.site": "shop", "falak.service": "redis", "falak.release": "01J9ZQ4N8V2M6R0T3W5Y7B9D1F"}}})
	})
	mux.HandleFunc("/containers/c0mp05e/json", func(w http.ResponseWriter, r *http.Request) { w.Write([]byte(`{"Config":{"Tty":false}}`)) })
	var logsQuery string
	mux.HandleFunc("/containers/c0mp05e/logs", func(w http.ResponseWriter, r *http.Request) {
		logsQuery = r.URL.RawQuery
		w.Write(frame(1, "2026-09-26T10:00:00Z Ready to accept connections\n"))
		w.(http.Flusher).Flush()
		<-r.Context().Done()
	})
	srv := &http.Server{Handler: mux}
	go srv.Serve(l)
	defer srv.Close()
	sink := &memSink{}
	d := NewDocker(sock, sink, nil)
	d.Configure(true, nil)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	d.Sync(ctx)
	deadline := time.Now().Add(3 * time.Second)
	for len(sink.bodies()) < 1 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	sink.mu.Lock()
	defer sink.mu.Unlock()
	if len(sink.recs) != 1 {
		t.Fatalf("recs %+v", sink.recs)
	}
	r := sink.recs[0]
	if r.Site != "shop" || r.Service != "" || r.Attrs["falak.compose.service"] != "redis" || r.Attrs["falak.release.id"] != "01J9ZQ4N8V2M6R0T3W5Y7B9D1F" {
		t.Fatalf("record %+v", r)
	}
	// A container created moments ago is read from its start (its startup lines predate the attach).
	if !strings.Contains(logsQuery, "since=") || strings.Contains(logsQuery, "tail=0") {
		t.Fatalf("fresh container attached without its startup logs: %s", logsQuery)
	}
}
