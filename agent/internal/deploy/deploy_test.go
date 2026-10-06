package deploy

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"io/fs"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

const (
	r1 = "01J9Z8Y7X6W5V4T3S2R1Q0P9N1"
	r2 = "01J9Z8Y7X6W5V4T3S2R1Q0P9N2"
	r3 = "01J9Z8Y7X6W5V4T3S2R1Q0P9N3"
)

type entry struct {
	name, body, link string
	typ              byte
}

func mkTarGz(t *testing.T, entries []entry) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for _, e := range entries {
		h := &tar.Header{Name: e.name, Mode: 0o644, Typeflag: e.typ, Linkname: e.link}
		if e.typ == 0 {
			h.Typeflag = tar.TypeReg
			h.Size = int64(len(e.body))
		}
		if h.Typeflag == tar.TypeDir {
			h.Mode = 0o755
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if h.Typeflag == tar.TypeReg {
			tw.Write([]byte(e.body))
		}
	}
	tw.Close()
	gz.Close()
	return buf.Bytes()
}

func sum(b []byte) string { s := sha256.Sum256(b); return hex.EncodeToString(s[:]) }

type procs struct{ names [][]string }

func (p *procs) Restart(_ context.Context, n []string) error {
	p.names = append(p.names, n)
	return nil
}

func (p *procs) RestartSite(_ context.Context, site string) ([]string, error) {
	p.names = append(p.names, []string{"site:" + site})
	return []string{site + "-worker"}, nil
}

func newDeployer(t *testing.T) (*Deployer, *runnertest.Fake, *procs, *httptest.Server, map[string][]byte) {
	artifacts := map[string][]byte{}
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer art" {
			http.Error(w, "unauthorized", 401)
			return
		}
		b, ok := artifacts[r.URL.Path]
		if !ok {
			http.NotFound(w, r)
			return
		}
		w.Write(b)
	}))
	t.Cleanup(srv.Close)
	fake := &runnertest.Fake{}
	pr := &procs{}
	d := New(Options{FS: hostfs.FS{Root: t.TempDir()}, Runner: fake, HTTP: srv.Client(), Procs: pr})
	return d, fake, pr, srv, artifacts
}

func st() commands.Stream { return commands.NewTestStream("x", &commands.Collector{}) }

func appTar(t *testing.T, version string) []byte {
	return mkTarGz(t, []entry{
		{name: "public/", typ: tar.TypeDir},
		{name: "public/index.php", body: "<?php echo '" + version + "';"},
		{name: "storage/", typ: tar.TypeDir},
		{name: "storage/app/.gitignore", body: "*"},
		{name: "artisan", body: "#!/usr/bin/env php"},
		{name: "public/storage", typ: tar.TypeSymlink, link: "../storage/app/public"},
	})
}

func fetch(t *testing.T, d *Deployer, srv *httptest.Server, arts map[string][]byte, id, version string) FetchResult {
	t.Helper()
	b := appTar(t, version)
	arts["/a/"+id+".tar.gz"] = b
	r, err := d.Fetch(context.Background(), FetchPayload{Site: "shop", ReleaseID: id,
		Artifact: Artifact{URL: srv.URL + "/a/" + id + ".tar.gz", SHA256: sum(b), SizeBytes: int64(len(b)), Headers: map[string]string{"Authorization": "Bearer art"}}}, st())
	if err != nil {
		t.Fatal(err)
	}
	return r.(FetchResult)
}

func TestReleaseLifecycle(t *testing.T) {
	d, fake, pr, srv, arts := newDeployer(t)
	root := d.o.FS.P("/srv/falak/sites/shop")

	res := fetch(t, d, srv, arts, r1, "v1")
	if !res.Changed || res.ReleaseDir != "/srv/falak/sites/shop/releases/"+r1 {
		t.Fatalf("%+v", res)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "releases", r1, "public/index.php")); !strings.Contains(string(b), "v1") {
		t.Fatal("artifact not extracted")
	}
	// Idempotent re-fetch.
	if again := fetch(t, d, srv, arts, r1, "v1"); again.Changed {
		t.Fatal("re-fetch should be a no-op")
	}

	// Prepare: storage seeded into shared/ and linked; .env written with secrets perms.
	pr1, err := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_KEY=secret\n"}, WritableDirs: []string{"bootstrap/cache"}}, st())
	if err != nil {
		t.Fatal(err)
	}
	if !pr1.(PrepareResult).Changed {
		t.Fatal("prepare should change")
	}
	link, err := os.Readlink(filepath.Join(root, "releases", r1, "storage"))
	if err != nil || link != "../../shared/storage" {
		t.Fatalf("storage link %q %v", link, err)
	}
	if _, err := os.Stat(filepath.Join(root, "shared/storage/app/.gitignore")); err != nil {
		t.Fatal("shared storage not seeded from release")
	}
	if b, _ := os.ReadFile(filepath.Join(root, "releases", r1, ".env")); string(b) != "APP_KEY=secret\n" {
		t.Fatal(".env not reachable through release symlink")
	}
	// The env file lives on the tmpfs only; shared/.env links to it.
	envFile := d.o.FS.P("/run/falak/env/shop.env")
	if fi, _ := os.Stat(envFile); fi == nil || fi.Mode().Perm() != 0o440 {
		t.Fatalf("env file %v", fi)
	}
	if link, err := os.Readlink(filepath.Join(root, "shared/.env")); err != nil || link != envFile {
		t.Fatalf("shared/.env link %q %v", link, err)
	}
	// Releases and shared/ are closed to other local users (config caches and .env hold secrets).
	for _, dir := range []string{"releases/" + r1, "shared"} {
		if fi, _ := os.Stat(filepath.Join(root, dir)); fi.Mode().Perm() != 0o750 {
			t.Fatalf("%s mode %v, want 0750", dir, fi.Mode().Perm())
		}
	}
	if again, _ := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, EnvFile: &EnvFile{Content: "APP_KEY=secret\n"}, WritableDirs: []string{"bootstrap/cache"}}, st()); again.(PrepareResult).Changed {
		t.Fatal("second prepare should be unchanged")
	}

	// Hook gets FALAK_* env and runs in the release dir.
	fake.On("/bin/bash", runner.Result{ExitCode: 0, Stdout: []byte("migrated\n")})
	c := &commands.Collector{}
	if _, err := d.Hook(context.Background(), HookPayload{Site: "shop", ReleaseID: r1, Name: "migrate", Script: "$FALAK_PHP_BINARY artisan migrate --force", User: "shop",
		Env: map[string]string{"APP_ENV": "production"}, Context: &Context{DeploymentID: "D1", Commit: "abc", PHPBinary: "/usr/bin/php8.4"}}, commands.NewTestStream("h", c)); err != nil {
		t.Fatal(err)
	}
	call := fake.Calls()[0]
	env := strings.Join(call.Env, "\n")
	for _, want := range []string{"FALAK_RELEASE_DIR=/srv/falak/sites/shop/releases/" + r1, "FALAK_COMMIT=abc", "FALAK_PHP_BINARY=/usr/bin/php8.4", "FALAK_DEPLOYMENT_ID=D1", "APP_ENV=production", "FALAK_HOOK=migrate", "FALAK_FETCH=:"} {
		if !strings.Contains(env, want) {
			t.Errorf("hook env missing %s", want)
		}
	}
	if call.User != "shop" || call.Dir != filepath.Join(root, "releases", r1) || call.Args[len(call.Args)-1] != "$FALAK_PHP_BINARY artisan migrate --force" {
		t.Fatalf("hook call %+v", call)
	}
	// Failing hook → ExitError with the script's code.
	fake.On("/bin/sh", runner.Result{ExitCode: 7})
	if _, err := d.Hook(context.Background(), HookPayload{Site: "shop", ReleaseID: r1, Name: "x", Script: "false", Shell: "/bin/sh"}, st()); err == nil {
		t.Fatal("expected failure")
	} else if ee, ok := err.(*commands.ExitError); !ok || ee.Code != 7 {
		t.Fatalf("want ExitError 7, got %v", err)
	}

	// Activate r1, then r2 with reloads; current is always a symlink (never missing).
	a1, err := d.Activate(context.Background(), ActivatePayload{Site: "shop", ReleaseID: r1}, st())
	if err != nil || !a1.(ActivateResult).Changed {
		t.Fatal(err)
	}
	fetch(t, d, srv, arts, r2, "v2")
	if _, err := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r2}, st()); err != nil {
		t.Fatal(err)
	}
	fake.Reset()
	a2, err := d.Activate(context.Background(), ActivatePayload{Site: "shop", ReleaseID: r2, Reload: []Reload{{Kind: "php_fpm", Name: "8.4"}, {Kind: "proc", Name: "shop-worker"}}}, st())
	if err != nil {
		t.Fatal(err)
	}
	if a2.(ActivateResult).PreviousReleaseID != r1 {
		t.Fatalf("%+v", a2)
	}
	if !fake.Ran("systemctl reload php8.4-fpm") || len(pr.names) != 1 || pr.names[0][0] != "shop-worker" {
		t.Fatalf("reloads not run: %v %v", fake.Lines(), pr.names)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "current/public/index.php")); !strings.Contains(string(b), "v2") {
		t.Fatal("current does not serve v2")
	}
	if l, _ := os.Readlink(filepath.Join(root, "current")); l != "releases/"+r2 {
		t.Fatalf("current link %q", l)
	}
	if a, _ := d.Activate(context.Background(), ActivatePayload{Site: "shop", ReleaseID: r2}, st()); a.(ActivateResult).Changed {
		t.Fatal("re-activate should be unchanged")
	}
	if _, err := d.Activate(context.Background(), ActivatePayload{Site: "shop", ReleaseID: r3}, st()); err == nil {
		t.Fatal("activating a missing release must fail")
	}

	// Rollback to previous (r1), then explicit forward.
	rb, err := d.Rollback(context.Background(), RollbackPayload{Site: "shop"}, st())
	if err != nil {
		t.Fatal(err)
	}
	if r := rb.(RollbackResult); r.ToReleaseID != r1 || r.FromReleaseID != r2 || !r.Changed {
		t.Fatalf("%+v", r)
	}
	if _, err := d.Rollback(context.Background(), RollbackPayload{Site: "shop"}, st()); err == nil {
		t.Fatal("no release older than r1: expected error")
	}
	if rb, _ := d.Rollback(context.Background(), RollbackPayload{Site: "shop", ReleaseID: r2}, st()); rb.(RollbackResult).ToReleaseID != r2 {
		t.Fatal("explicit rollback target ignored")
	}

	// Prune keep=1: r3 newest is kept, current (r2) protected, r1 removed.
	fetch(t, d, srv, arts, r3, "v3")
	pru, err := d.Prune(context.Background(), PrunePayload{Site: "shop", Keep: 1}, st())
	if err != nil {
		t.Fatal(err)
	}
	pres := pru.(PruneResult)
	if len(pres.Removed) != 1 || pres.Removed[0] != r1 {
		t.Fatalf("%+v", pres)
	}
	if _, err := os.Stat(filepath.Join(root, "releases", r2)); err != nil {
		t.Fatal("current release pruned!")
	}
	if p2, _ := d.Prune(context.Background(), PrunePayload{Site: "shop", Keep: 1}, st()); p2.(PruneResult).Changed {
		t.Fatal("second prune should be a no-op")
	}
}

func TestFetchRejectsBadArtifacts(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	good := appTar(t, "v1")
	arts["/good.tar.gz"] = good
	cases := map[string]FetchPayload{
		"sha mismatch": {Site: "shop", ReleaseID: r1, Artifact: Artifact{URL: srv.URL + "/good.tar.gz", SHA256: strings.Repeat("0", 64), Headers: map[string]string{"Authorization": "Bearer art"}}},
		"plain http":   {Site: "shop", ReleaseID: r1, Artifact: Artifact{URL: "http://example.com/a.tgz", SHA256: sum(good)}},
		"bad slug":     {Site: "../etc", ReleaseID: r1, Artifact: Artifact{URL: srv.URL + "/good.tar.gz", SHA256: sum(good)}},
		"404":          {Site: "shop", ReleaseID: r1, Artifact: Artifact{URL: srv.URL + "/missing", SHA256: sum(good), Headers: map[string]string{"Authorization": "Bearer art"}}},
	}
	for name, p := range cases {
		if _, err := d.Fetch(context.Background(), p, st()); err == nil {
			t.Errorf("%s: expected error", name)
		}
	}
	if _, err := os.Stat(d.o.FS.P("/srv/falak/sites/shop/releases/" + r1)); err == nil {
		t.Fatal("failed fetch left a release dir behind")
	}
}

func TestExtractRejectsTraversal(t *testing.T) {
	cases := map[string][]entry{
		"dotdot":   {{name: "../evil", body: "x"}},
		"absolute": {{name: "/etc/passwd", body: "x"}},
		"through symlink": {
			{name: "link", typ: tar.TypeSymlink, link: "/tmp"},
			{name: "link/pwned", body: "x"},
		},
		"hardlink escape": {{name: "h", typ: tar.TypeLink, link: "../../etc/passwd"}},
	}
	for name, ents := range cases {
		t.Run(name, func(t *testing.T) {
			b := mkTarGz(t, ents)
			gz, _ := gzip.NewReader(bytes.NewReader(b))
			dst := t.TempDir()
			if err := Extract(gz, dst); err == nil {
				t.Fatal("expected rejection")
			}
		})
	}
}

func TestPrepareMakesSharedStorageGroupWritableAllTheWayDown(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	root := d.o.FS.P("/srv/falak/sites/shop")
	fetch(t, d, srv, arts, r1, "v1")
	// Builder tarballs normalise modes to 0755/0644: nested storage dirs start out not group-writable.
	nested := filepath.Join(root, "releases", r1, "storage", "framework", "views")
	if err := os.MkdirAll(nested, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(nested, "compiled.php"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	res, err := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, WritableDirs: []string{"storage", "bootstrap/cache"}}, st())
	if err != nil || !res.(PrepareResult).Changed {
		t.Fatalf("prepare: %v %+v", err, res)
	}
	for _, p := range []string{"shared/storage", "shared/storage/framework", "shared/storage/framework/views", "releases/" + r1 + "/bootstrap/cache"} {
		fi, err := os.Stat(filepath.Join(root, p))
		if err != nil || fi.Mode()&(fs.ModePerm|fs.ModeSetgid) != 0o775|fs.ModeSetgid {
			t.Fatalf("%s: mode %v (%v)", p, fi.Mode(), err)
		}
	}
	if fi, _ := os.Stat(filepath.Join(root, "shared/storage/framework/views/compiled.php")); fi.Mode().Perm() != 0o664 {
		t.Fatalf("file mode %v", fi.Mode())
	}
	if again, _ := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, WritableDirs: []string{"storage", "bootstrap/cache"}}, st()); again.(PrepareResult).Changed {
		t.Fatal("second prepare must be a no-op")
	}
}

func TestPrepareRefusesWritableDirsThatEscapeTheSite(t *testing.T) {
	d, _, _, srv, arts := newDeployer(t)
	fetch(t, d, srv, arts, r1, "v1")
	outside := t.TempDir()
	link := filepath.Join(d.o.FS.P("/srv/falak/sites/shop"), "releases", r1, "escape")
	if err := os.Symlink(outside, link); err != nil {
		t.Fatal(err)
	}
	if _, err := d.Prepare(context.Background(), PreparePayload{Site: "shop", ReleaseID: r1, WritableDirs: []string{"escape"}}, st()); err == nil || !strings.Contains(err.Error(), "outside the site") {
		t.Fatalf("expected refusal, got %v", err)
	}
	if fi, _ := os.Stat(outside); fi.Mode()&fs.ModeSetgid != 0 {
		t.Fatal("must not touch files outside the site")
	}
}

func TestGroupSharedDefaultACLEncoding(t *testing.T) {
	// user::rwx group::rwx other::r-x as getfattr -e hex prints it.
	want := "02000000" + "01000700ffffffff" + "04000700ffffffff" + "20000500ffffffff"
	if got := hex.EncodeToString(groupSharedDefaultACL()); got != want {
		t.Fatalf("acl = %s, want %s", got, want)
	}
	// user::rwx user:caddy(998):r-x group::r-x mask::r-x other::---
	want = "02000000" + "01000700ffffffff" + "02000500e6030000" + "04000500ffffffff" + "10000500ffffffff" + "20000000ffffffff"
	if got := hex.EncodeToString(edgeAccessACL(0o750, 998)); got != want {
		t.Fatalf("access acl = %s, want %s", got, want)
	}
}
