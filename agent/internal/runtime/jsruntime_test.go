package runtime

import (
	"archive/zip"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func zipWith(t *testing.T, name, body string) []byte {
	t.Helper()
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, err := zw.Create(name)
	if err != nil {
		t.Fatal(err)
	}
	w.Write([]byte(body))
	zw.Close()
	return buf.Bytes()
}

func hexSum(b []byte) string { s := sha256.Sum256(b); return hex.EncodeToString(s[:]) }

func TestBunAndDenoInstallVerifiedBinaries(t *testing.T) {
	bunZip := zipWith(t, "bun-linux-aarch64/bun", "#!bun")
	denoZip := zipWith(t, "deno", "#!deno")
	files := map[string][]byte{
		"/bun/bun-v1.2.21/bun-linux-aarch64.zip":                    bunZip,
		"/bun/bun-v1.2.21/SHASUMS256.txt":                           []byte(hexSum(bunZip) + "  bun-linux-aarch64.zip\n" + strings.Repeat("0", 64) + "  bun-linux-x64.zip\n"),
		"/deno/v2.5.1/deno-aarch64-unknown-linux-gnu.zip":           denoZip,
		"/deno/v2.5.1/deno-aarch64-unknown-linux-gnu.zip.sha256sum": []byte(hexSum(denoZip) + "  deno-aarch64-unknown-linux-gnu.zip\n"),
	}
	var hits int
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		if b, ok := files[r.URL.Path]; ok {
			w.Write(b)
			return
		}
		http.NotFound(w, r)
	}))
	defer srv.Close()
	fs := hostfs.FS{Root: t.TempDir()}
	rt := New(Deps{Runner: &runnertest.Fake{}, FS: fs, HTTP: srv.Client(), Arch: "arm64"})
	st := commands.NewTestStream("c", &commands.Collector{})

	r, err := rt.BunInstall(context.Background(), JSRuntimePayload{Version: "1.2.21", Default: true, Mirror: srv.URL + "/bun"}, st)
	if err != nil || !r.(NodeResult).Changed {
		t.Fatalf("bun: %v %+v", err, r)
	}
	if b, _ := fs.ReadFile("/opt/falak/bun/1.2.21/bin/bun"); string(b) != "#!bun" {
		t.Fatalf("bun binary %q", b)
	}
	if fi, _ := os.Stat(fs.P("/opt/falak/bun/1.2.21/bin/bun")); fi.Mode().Perm() != 0o755 {
		t.Fatalf("bun mode %v", fi.Mode())
	}
	if l, _ := os.Readlink(fs.P("/usr/local/bin/bun")); !strings.HasSuffix(l, "opt/falak/bun/1.2.21/bin/bun") {
		t.Fatalf("bun symlink %q", l)
	}
	before := hits
	if r, _ := rt.BunInstall(context.Background(), JSRuntimePayload{Version: "1.2.21", Default: true, Mirror: srv.URL + "/bun"}, st); r.(NodeResult).Changed || hits != before {
		t.Fatal("reinstall must be a no-op without downloads")
	}

	if _, err := rt.DenoInstall(context.Background(), JSRuntimePayload{Version: "2.5.1", Mirror: srv.URL + "/deno"}, st); err != nil {
		t.Fatal(err)
	}
	if b, _ := fs.ReadFile("/opt/falak/deno/2.5.1/bin/deno"); string(b) != "#!deno" {
		t.Fatalf("deno binary %q", b)
	}
	if _, err := os.Lstat(fs.P("/usr/local/bin/deno")); !os.IsNotExist(err) {
		t.Fatal("default=false must not symlink")
	}
}

func TestJSRuntimeRejectsTamperedArchive(t *testing.T) {
	good := zipWith(t, "bun-linux-x64/bun", "#!bun")
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, ".zip") {
			w.Write(zipWith(t, "bun-linux-x64/bun", "evil"))
			return
		}
		w.Write([]byte(hexSum(good) + "  bun-linux-x64.zip\n"))
	}))
	defer srv.Close()
	fs := hostfs.FS{Root: t.TempDir()}
	rt := New(Deps{Runner: &runnertest.Fake{}, FS: fs, HTTP: srv.Client(), Arch: "amd64"})
	if _, err := rt.BunInstall(context.Background(), JSRuntimePayload{Version: "1.2.21", Mirror: srv.URL}, commands.NewTestStream("c", &commands.Collector{})); err == nil || !strings.Contains(err.Error(), "sha256 mismatch") {
		t.Fatalf("expected checksum failure, got %v", err)
	}
	if fs.Exists("/opt/falak/bun/1.2.21/bin/bun") {
		t.Fatal("nothing may be installed from a tampered archive")
	}
}
