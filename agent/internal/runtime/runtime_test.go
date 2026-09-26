package runtime

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

func setup(t *testing.T, f *runnertest.Fake, client *http.Client) (*Runtime, string, commands.Stream) {
	root := t.TempDir()
	return New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: client, Arch: "amd64"}), root, commands.NewTestStream("c", &commands.Collector{})
}

func mk(t *testing.T, root, p string) {
	if err := os.MkdirAll(filepath.Join(root, p), 0o755); err != nil {
		t.Fatal(err)
	}
}

func TestPHPInstall(t *testing.T) {
	installed := map[string]bool{}
	f := &runnertest.Fake{}
	f.OnFunc("dpkg-query", func(c runnertest.Call) (runner.Result, error) {
		var b strings.Builder
		for _, a := range c.Args[2:] {
			if installed[a] {
				b.WriteString(a + "\tinstalled\t1\n")
			}
		}
		return runner.Result{ExitCode: 1, Stdout: []byte(b.String())}, nil
	})
	f.OnFunc("apt-get install", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			installed[a] = true
		}
		return runner.Result{}, nil
	})
	active := false
	f.OnFunc("systemctl is-active", func(runnertest.Call) (runner.Result, error) {
		if active {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl enable --now", func(runnertest.Call) (runner.Result, error) { active = true; return runner.Result{}, nil })
	alt := "Value: /usr/bin/php8.3\n"
	f.OnFunc("update-alternatives --query", func(runnertest.Call) (runner.Result, error) { return runner.Result{Stdout: []byte(alt)}, nil })
	f.OnFunc("update-alternatives --set", func(runnertest.Call) (runner.Result, error) {
		alt = "Value: /usr/bin/php8.4\n"
		return runner.Result{}, nil
	})
	f.OnFunc("add-apt-repository", func(c runnertest.Call) (runner.Result, error) { return runner.Result{}, nil })
	rt, root, st := setup(t, f, nil)
	p := PHPInstallPayload{Version: "8.4", Extensions: []string{"mbstring", "intl", "pdo_mysql", "ctype", "redis"}, CLIDefault: true}
	r, err := rt.PHPInstall(context.Background(), p, st)
	if err != nil || !r.(PHPInstallResult).Changed {
		t.Fatal(r, err)
	}
	for _, want := range []string{"add-apt-repository -y ppa:ondrej/php", "systemctl enable --now php8.4-fpm", "update-alternatives --set php /usr/bin/php8.4"} {
		if !f.Ran(want) {
			t.Fatalf("missing %q in %v", want, f.Lines())
		}
	}
	for _, pk := range []string{"php8.4-cli", "php8.4-fpm", "php8.4-mbstring", "php8.4-intl", "php8.4-mysql", "php8.4-redis"} {
		if !installed[pk] {
			t.Fatalf("%s not installed", pk)
		}
	}
	if installed["php8.4-ctype"] {
		t.Fatal("ctype is in common")
	}
	// PPA now present on disk
	mk(t, root, "/etc/apt/sources.list.d")
	os.WriteFile(filepath.Join(root, "etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources"), []byte("URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\n"), 0o644)
	f.Reset()
	r, err = rt.PHPInstall(context.Background(), p, st)
	if err != nil || r.(PHPInstallResult).Changed || f.Ran("apt-get") || f.Ran("add-apt-repository") || f.Ran("update-alternatives --set") {
		t.Fatal(r, err, f.Lines())
	}
}

func TestPHPConfigure(t *testing.T) {
	f := &runnertest.Fake{}
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm")
	mk(t, root, "/etc/php/8.4/cli")
	p := PHPConfigurePayload{Version: "8.4", INI: map[string]any{"memory_limit": "512M", "opcache.enable": true, "max_execution_time": float64(60), "date.timezone": "Europe/Amsterdam", "error_log": "a b"}}
	r, err := rt.PHPConfigure(context.Background(), p, st)
	if err != nil || !r.(FilesResult).Changed || len(r.(FilesResult).Files) != 2 {
		t.Fatal(r, err)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/php/8.4/fpm/conf.d/99-kiln.ini"))
	want := "; Managed by Kiln — do not edit\ndate.timezone = Europe/Amsterdam\nerror_log = \"a b\"\nmax_execution_time = 60\nmemory_limit = 512M\nopcache.enable = On\n"
	if string(b) != want {
		t.Fatalf("%q", b)
	}
	if !f.Ran("php-fpm8.4 -t") || !f.Ran("systemctl reload php8.4-fpm") {
		t.Fatal(f.Lines())
	}
	f.Reset()
	r, _ = rt.PHPConfigure(context.Background(), p, st)
	if r.(FilesResult).Changed || len(f.Lines()) != 0 {
		t.Fatal("not idempotent", f.Lines())
	}
	// failing config test reverts
	f.On("php-fpm8.4 -t", runner.Result{ExitCode: 1, Stderr: []byte("bad")})
	p.INI["memory_limit"] = "bogus"
	if _, err := rt.PHPConfigure(context.Background(), PHPConfigurePayload{Version: "8.4", SAPI: "fpm", INI: p.INI}, st); err == nil {
		t.Fatal("expected failure")
	}
	b, _ = os.ReadFile(filepath.Join(root, "etc/php/8.4/fpm/conf.d/99-kiln.ini"))
	if string(b) != want {
		t.Fatal("not reverted")
	}
}

type entry struct {
	name, link string
	typ        byte
	body       string
}

func tgz(entries []entry) []byte {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for _, e := range entries {
		h := &tar.Header{Name: e.name, Typeflag: e.typ, Mode: 0o755, Linkname: e.link, Size: int64(len(e.body))}
		if e.typ != tar.TypeReg {
			h.Size = 0
		}
		tw.WriteHeader(h)
		if e.typ == tar.TypeReg {
			tw.Write([]byte(e.body))
		}
	}
	tw.Close()
	gz.Close()
	return buf.Bytes()
}

func TestNodeInstall(t *testing.T) {
	name := "node-v22.11.0-linux-x64.tar.gz"
	archive := tgz([]entry{
		{name: "node-v22.11.0-linux-x64/", typ: tar.TypeDir},
		{name: "node-v22.11.0-linux-x64/bin/node", typ: tar.TypeReg, body: "ELF"},
		{name: "node-v22.11.0-linux-x64/lib/node_modules/npm/bin/npm-cli.js", typ: tar.TypeReg, body: "js"},
		{name: "node-v22.11.0-linux-x64/bin/npm", typ: tar.TypeSymlink, link: "../lib/node_modules/npm/bin/npm-cli.js"},
	})
	sum := sha256.Sum256(archive)
	hits := 0
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		switch r.URL.Path {
		case "/dist/v22.11.0/SHASUMS256.txt":
			fmt.Fprintf(w, "%s  %s\nabc  other.tar.gz\n", hex.EncodeToString(sum[:]), name)
		case "/dist/v22.11.0/" + name:
			w.Write(archive)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	rt, root, st := setup(t, &runnertest.Fake{}, srv.Client())
	p := NodePayload{Version: "22.11.0", Default: true, Mirror: srv.URL + "/dist"}
	r, err := rt.NodeInstall(context.Background(), p, st)
	if err != nil || !r.(NodeResult).Changed {
		t.Fatal(r, err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "opt/kiln/node/22.11.0/bin/node")); string(b) != "ELF" {
		t.Fatal("not extracted")
	}
	if l, _ := os.Readlink(filepath.Join(root, "usr/local/bin/node")); l != "/opt/kiln/node/22.11.0/bin/node" {
		t.Fatal(l)
	}
	if b, err := os.ReadFile(filepath.Join(root, "opt/kiln/node/22.11.0/bin/npm")); err != nil || string(b) != "js" {
		t.Fatal("npm symlink", err)
	}
	h := hits
	r, _ = rt.NodeInstall(context.Background(), p, st)
	if r.(NodeResult).Changed || hits != h {
		t.Fatal("not idempotent")
	}
	// sha mismatch
	p2 := NodePayload{Version: "22.11.0", SHA256: strings.Repeat("a", 64), Mirror: srv.URL + "/dist"}
	rt2, root2, _ := setup(t, &runnertest.Fake{}, srv.Client())
	if _, err := rt2.NodeInstall(context.Background(), p2, st); err == nil || !strings.Contains(err.Error(), "sha256 mismatch") {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root2, "opt/kiln/node/22.11.0")); err == nil {
		t.Fatal("partial install left behind")
	}
}

func TestExtractRejectsTraversal(t *testing.T) {
	cases := [][]entry{
		{{name: "x/../../evil", typ: tar.TypeReg, body: "x"}},
		{{name: "/etc/passwd", typ: tar.TypeReg, body: "x"}},
		{{name: "x/link", typ: tar.TypeSymlink, link: "../../../etc"}},
		{{name: "x/abs", typ: tar.TypeSymlink, link: "/etc/shadow"}},
	}
	for i, c := range cases {
		dst := t.TempDir()
		if err := ExtractTarGz(bytes.NewReader(tgz(c)), dst, 1); err == nil {
			t.Fatalf("case %d accepted", i)
		}
	}
}

func TestFrankenPHP(t *testing.T) {
	bin := []byte("frankenphp-binary")
	sum := sha256.Sum256(bin)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1.4.0/frankenphp-linux-x86_64" {
			http.NotFound(w, r)
			return
		}
		w.Write(bin)
	}))
	defer srv.Close()
	f := &runnertest.Fake{}
	f.On("getent passwd caddy", runner.Result{Stdout: []byte("caddy:x:998:998::/var/lib/caddy:/usr/sbin/nologin\n")})
	f.On("systemctl is-enabled --quiet caddy.service", runner.Result{ExitCode: 1})
	rt, root, st := setup(t, f, srv.Client())
	rt.d.FrankenPHPBase = srv.URL
	p := FrankenPHPPayload{Version: "v1.4.0", SHA256: hex.EncodeToString(sum[:]), INI: map[string]any{"memory_limit": "256M"}}
	r, err := rt.FrankenPHPConfigure(context.Background(), p, st)
	if err != nil || !r.(BinaryResult).Changed {
		t.Fatal(r, err)
	}
	fi, _ := os.Stat(filepath.Join(root, "usr/local/bin/frankenphp"))
	if fi == nil || fi.Mode().Perm() != 0o755 {
		t.Fatal("binary")
	}
	unit, _ := os.ReadFile(filepath.Join(root, "etc/systemd/system/kiln-edge.service"))
	if !strings.Contains(string(unit), "ExecStart=/usr/local/bin/frankenphp run --environ --config /etc/kiln/caddy/bootstrap.json") ||
		!strings.Contains(string(unit), "ExecReload=/usr/local/bin/frankenphp reload --config /etc/kiln/caddy/bootstrap.json --force") ||
		!strings.Contains(string(unit), "PHPRC=/etc/frankenphp") {
		t.Fatal(string(unit))
	}
	if b, _ := os.ReadFile(filepath.Join(root, "etc/kiln/caddy/bootstrap.json")); !strings.Contains(string(b), `"localhost:2019"`) {
		t.Fatal(string(b))
	}
	for _, w := range []string{"systemctl daemon-reload", "systemctl enable kiln-edge.service", "systemctl restart kiln-edge.service"} {
		if !f.Ran(w) {
			t.Fatal(w, f.Lines())
		}
	}
	// existing bootstrap (full config from edge) is never overwritten
	os.WriteFile(filepath.Join(root, "etc/kiln/caddy/bootstrap.json"), []byte(`{"full":true}`), 0o644)
	f.Reset()
	r, err = rt.FrankenPHPConfigure(context.Background(), p, st)
	if err != nil || r.(BinaryResult).Changed || f.Ran("systemctl restart") || f.Ran("systemctl daemon-reload") {
		t.Fatal(r, err, f.Lines())
	}
	if b, _ := os.ReadFile(filepath.Join(root, "etc/kiln/caddy/bootstrap.json")); string(b) != `{"full":true}` {
		t.Fatal("bootstrap overwritten")
	}
	// sha mismatch
	rt3, _, _ := setup(t, f, srv.Client())
	rt3.d.FrankenPHPBase = srv.URL
	p.SHA256 = strings.Repeat("b", 64)
	if _, err := rt3.FrankenPHPConfigure(context.Background(), p, st); err == nil {
		t.Fatal("mismatch accepted")
	}
}

func TestFPMPool(t *testing.T) {
	f := &runnertest.Fake{}
	rt, root, st := setup(t, f, nil)
	mk(t, root, "/etc/php/8.4/fpm/pool.d")
	mr := 0
	p := FPMPoolPayload{PHPVersion: "8.4", Pool: "shop", User: "shop", MaxRequests: &mr, PHPAdminValues: map[string]string{"open_basedir": "/srv/kiln/sites/shop"}, Env: map[string]string{"APP_ENV": "production"}}
	r, err := rt.FPMPool(context.Background(), p, st)
	if err != nil || !r.(ListenResult).Changed || r.(ListenResult).Listen != "/run/php/kiln-shop-8.4.sock" {
		t.Fatal(r, err)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/php/8.4/fpm/pool.d/kiln-shop.conf"))
	for _, w := range []string{"[kiln-shop]", "listen = /run/php/kiln-shop-8.4.sock", "listen.group = caddy", "pm.max_requests = 0", "php_admin_value[open_basedir] = /srv/kiln/sites/shop", `env[APP_ENV] = "production"`} {
		if !strings.Contains(string(b), w) {
			t.Fatalf("missing %q in\n%s", w, b)
		}
	}
	if strings.Join(f.Lines(), "|") != "php-fpm8.4 -t|systemctl reload php8.4-fpm" {
		t.Fatal(f.Lines())
	}
	f.Reset()
	r, _ = rt.FPMPool(context.Background(), p, st)
	if r.(ListenResult).Changed || len(f.Lines()) > 0 {
		t.Fatal("not idempotent")
	}
	p.State = "absent"
	r, _ = rt.FPMPool(context.Background(), p, st)
	r2, _ := rt.FPMPool(context.Background(), p, st)
	if !r.(ListenResult).Changed || r2.(ListenResult).Changed {
		t.Fatal("absent")
	}
}
