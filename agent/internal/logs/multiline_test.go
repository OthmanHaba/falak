package logs

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/hostfs"
)

func TestLaravelMultilineMergesStackTraces(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	dir := fs.P("/srv/kiln/sites/shop/shared/storage/logs")
	os.MkdirAll(dir, 0o755)
	p := filepath.Join(dir, "laravel-2026-09-28.log")
	appendFile(t, p, "")

	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/kiln", sink, nil)
	tl.SetSources([]Source{{Path: "/srv/kiln/sites/shop/shared/storage/logs/*.log", Site: "shop", Kind: "app", Multiline: "laravel"}})
	tl.Poll()

	appendFile(t, p, "[2026-09-28 10:00:00] production.ERROR: boom {\"exception\":\"[object] (RuntimeException(code: 0): boom at /app/x.php:3)\n"+
		"[stacktrace]\n#0 /app/y.php(10): f()\n#1 {main}\n\"} \n"+
		"[2026-09-28 10:00:01] production.INFO: next\n")
	tl.Poll()
	// The second record could still get continuation lines: it is only emitted after a quiet poll.
	if got := sink.bodies(); len(got) != 1 {
		t.Fatalf("want only the completed record, got %q", got)
	}
	rec := sink.recs[0]
	if !strings.HasPrefix(rec.Body, "[2026-09-28 10:00:00] production.ERROR: boom") || !strings.Contains(rec.Body, "\n#1 {main}\n") {
		t.Fatalf("stack trace not merged: %q", rec.Body)
	}
	if rec.Severity != "ERROR" || rec.Site != "shop" || rec.Kind != "app" {
		t.Fatalf("record = %+v", rec)
	}
	tl.Poll() // quiet poll flushes the pending record
	if got := sink.bodies(); len(got) != 2 || got[1] != "[2026-09-28 10:00:01] production.INFO: next" {
		t.Fatalf("got %q", got)
	}
}

func TestMultilinePendingRecordSurvivesRestart(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	dir := fs.P("/logs")
	os.MkdirAll(dir, 0o755)
	p := filepath.Join(dir, "laravel.log")
	appendFile(t, p, "")
	src := []Source{{Path: "/logs/*.log", Site: "shop", Multiline: "laravel"}}

	sink := &memSink{}
	tl := NewTailer(fs, "/state", sink, nil)
	tl.SetSources(src)
	tl.Poll()
	appendFile(t, p, "[2026-09-28 10:00:00] production.ERROR: a\n#0 trace\n")
	tl.Poll() // pending, not emitted; the saved offset excludes it

	sink2 := &memSink{}
	tl2 := NewTailer(fs, "/state", sink2, nil)
	tl2.SetSources(src)
	appendFile(t, p, "#1 {main}\n")
	tl2.Poll()
	tl2.Poll()
	if got := sink2.bodies(); len(got) != 1 || got[0] != "[2026-09-28 10:00:00] production.ERROR: a\n#0 trace\n#1 {main}" {
		t.Fatalf("got %q", got)
	}
}

func TestAccessLogsAttributedByFileName(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	os.MkdirAll(fs.P(AccessLogDir), 0o750)
	p := fs.P(AccessLogDir + "/shop.log")
	appendFile(t, p, "")

	sink := &memSink{}
	tl := NewTailer(fs, "/var/lib/kiln", sink, nil)
	tl.SetSources([]Source{AccessSource()})
	tl.Poll()
	appendFile(t, p, `{"level":"info","ts":1727517600.25,"logger":"http.log.access.kiln-access-shop","msg":"handled request",`+
		`"request":{"remote_ip":"10.0.0.2","remote_port":"5000","client_ip":"203.0.113.9","proto":"HTTP/2.0","method":"POST","host":"shop.test",`+
		`"uri":"/checkout?step=2","headers":{"User-Agent":["curl/8.5"],"Cookie":["secret"]}},"bytes_read":12,"user_id":"","duration":0.0123,`+
		`"size":512,"status":502,"resp_headers":{"Set-Cookie":["x"]}}`+"\n")
	tl.Poll()
	if len(sink.recs) != 1 {
		t.Fatalf("got %d records", len(sink.recs))
	}
	r := sink.recs[0]
	if r.Site != "shop" || r.Kind != "access" || r.Severity != "ERROR" || r.Body != "POST /checkout?step=2 502 12.3ms" {
		t.Fatalf("record = %+v", r)
	}
	want := map[string]string{
		"http.request.method": "POST", "http.response.status_code": "502", "http.response.body.size": "512",
		"http.server.duration_ms": "12.300", "url.path": "/checkout", "url.query": "step=2", "client.address": "203.0.113.9",
		"user_agent.original": "curl/8.5", "server.address": "shop.test", "log.file.name": "shop.log",
	}
	for k, v := range want {
		if r.Attrs[k] != v {
			t.Errorf("%s = %q, want %q", k, r.Attrs[k], v)
		}
	}
	for k, v := range r.Attrs {
		if strings.Contains(v, "secret") {
			t.Errorf("header leaked in %s", k)
		}
	}
	if r.Time.Unix() != 1727517600 {
		t.Errorf("time = %v", r.Time)
	}
}
