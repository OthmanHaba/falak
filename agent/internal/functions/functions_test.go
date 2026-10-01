package functions

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/docker"
	"github.com/kiln/agent/internal/fngateway"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner/runnertest"
)

type bufStream struct {
	mu  sync.Mutex
	out bytes.Buffer
}

type lockedWriter struct{ s *bufStream }

func (w lockedWriter) Write(p []byte) (int, error) {
	w.s.mu.Lock()
	defer w.s.mu.Unlock()
	return w.s.out.Write(p)
}

func (s *bufStream) Stdout() io.Writer { return lockedWriter{s} }
func (s *bufStream) Stderr() io.Writer { return lockedWriter{s} }
func (s *bufStream) Progress(float64)  {}
func (s *bufStream) Emit(_, d string)  { lockedWriter{s}.Write([]byte(d)) }
func (s *bufStream) String() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.out.String()
}

type fakeDocker struct {
	mu       sync.Mutex
	images   map[string]bool
	pulls    []string
	created  []docker.CreateBody
	names    []string
	removed  []string
	exitCode int
	// seen captures what the install container would see in /app.
	seen     []string
	networks map[string]bool
	hang     bool
}

func (d *fakeDocker) ImagePull(_ context.Context, ref string, _ *docker.Auth, w io.Writer) error {
	d.mu.Lock()
	defer d.mu.Unlock()
	d.pulls = append(d.pulls, ref)
	d.images[ref] = true
	fmt.Fprintln(w, "pulled "+ref)
	return nil
}

func (d *fakeDocker) ImageInspect(_ context.Context, ref string) (string, bool, error) {
	d.mu.Lock()
	defer d.mu.Unlock()
	return "sha256:x", d.images[ref], nil
}

func (d *fakeDocker) ContainerCreate(_ context.Context, name string, body docker.CreateBody) (string, error) {
	d.mu.Lock()
	defer d.mu.Unlock()
	d.created = append(d.created, body)
	d.names = append(d.names, name)
	// The install container sees the release dir mounted at /app.
	app := strings.TrimSuffix(body.HostConfig.Binds[0], ":/app:rw")
	_ = filepath.WalkDir(app, func(p string, e os.DirEntry, err error) error {
		if err == nil && !e.IsDir() {
			rel, _ := filepath.Rel(app, p)
			d.seen = append(d.seen, rel)
		}
		return nil
	})
	// Like bun install: resolve once, then keep an existing lockfile.
	if lock := filepath.Join(app, "bun.lock"); body.Cmd[0] == "kiln-fn-install" {
		if _, err := os.Stat(lock); err != nil {
			_ = os.WriteFile(lock, []byte(fmt.Sprintf("resolved #%d", len(d.created))), 0o644)
		}
	}
	return "install-1", nil
}

func (d *fakeDocker) ContainerStart(context.Context, string) error { return nil }

func (d *fakeDocker) ContainerLogs(ctx context.Context, _ string, _ bool, _ int, w io.Writer) error {
	fmt.Fprintln(w, "bun install v1.3.0\n+ hono@4.9.0")
	if d.hang {
		<-ctx.Done()
	}
	return nil
}

func (d *fakeDocker) ContainerWait(ctx context.Context, _ string) (int, error) {
	if d.hang {
		<-ctx.Done()
		return -1, ctx.Err()
	}
	return d.exitCode, nil
}

func (d *fakeDocker) ContainerRemove(_ context.Context, id string) error {
	d.mu.Lock()
	defer d.mu.Unlock()
	d.removed = append(d.removed, id)
	return nil
}

func (d *fakeDocker) NetworkExists(_ context.Context, name string) (bool, error) {
	return d.networks[name], nil
}

func (d *fakeDocker) NetworkCreate(_ context.Context, name string, _ map[string]string) error {
	d.networks[name] = true
	return nil
}

type fakeGateway struct {
	mu      sync.Mutex
	version string
	down    bool
	applied []fngateway.Spec
	deleted []string
	current string
	err     error
}

func (g *fakeGateway) Version(context.Context) (string, error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.down {
		return "", errors.New("dial unix: no such file")
	}
	return g.version, nil
}

func (g *fakeGateway) Apply(_ context.Context, s fngateway.Spec) (fngateway.ApplyResult, error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.err != nil {
		return fngateway.ApplyResult{}, g.err
	}
	g.applied = append(g.applied, s)
	prev := g.current
	g.current = s.Release
	return fngateway.ApplyResult{Release: s.Release, PreviousRelease: prev, BootMS: 180, Changed: true}, nil
}

func (g *fakeGateway) Delete(_ context.Context, site string) (bool, error) {
	g.deleted = append(g.deleted, site)
	return true, nil
}

func (g *fakeGateway) Run(_ context.Context, site string, r fngateway.RunRequest, w io.Writer) (int, error) {
	fmt.Fprintf(w, "ran %s/%s (%s)\n", site, r.Schedule, r.Trigger)
	if r.Schedule == "broken" {
		return 1, nil
	}
	return 0, nil
}

func (g *fakeGateway) Status(context.Context) ([]fngateway.Status, error) {
	return []fngateway.Status{{Site: "a", Release: "r1"}, {Site: "hello", Release: "r2", Running: 1}}, nil
}

type env struct {
	f  *Functions
	fs hostfs.FS
	d  *fakeDocker
	gw *fakeGateway
	r  *runnertest.Fake
	st *bufStream
}

func setup(t *testing.T) *env {
	t.Helper()
	fs := hostfs.FS{Root: t.TempDir()}
	d := &fakeDocker{images: map[string]bool{}, networks: map[string]bool{}}
	gw := &fakeGateway{version: "0.4.0"}
	r := &runnertest.Fake{}
	f := New(Deps{FS: fs, Runner: r, Docker: d, Gateway: gw, Version: "0.4.0", SocketWait: time.Second})
	return &env{f: f, fs: fs, d: d, gw: gw, r: r, st: &bufStream{}}
}

func payload(release string) ApplyPayload {
	return ApplyPayload{Site: "hello", Release: release, Image: "ghcr.io/kiln/kiln-fn-bun:0.4.0", Entrypoint: "index.ts",
		Files: []File{{Path: "index.ts", Content: "import { Hono } from 'hono'\nexport default new Hono()\n"}, {Path: "lib/util.ts", Content: "export const x = 1\n"}},
		Env:   map[string]string{"DATABASE_URL": "postgres://db"}}
}

func (e *env) calls() string {
	var s []string
	for _, c := range e.r.Calls() {
		s = append(s, c.Line)
	}
	return strings.Join(s, "\n")
}

func TestApplyInstallsAndRegistersTheRelease(t *testing.T) {
	e := setup(t)
	res, err := e.f.Apply(context.Background(), payload("r1"), e.st)
	if err != nil {
		t.Fatalf("%v\n%s", err, e.st)
	}
	r := res.(ApplyResult)
	if !r.Installed || r.Release != "r1" || r.BootMS != 180 {
		t.Fatalf("result %+v", r)
	}
	dir := e.fs.P("/var/lib/kiln/functions/hello/releases/r1")
	for _, f := range []string{"index.ts", "lib/util.ts", markerFile} {
		if _, err := os.Stat(filepath.Join(dir, f)); err != nil {
			t.Errorf("missing %s: %v", f, err)
		}
	}
	// The install container: runtime image, kiln-fn-install, nobody, hardened, release + cache mounted.
	if len(e.d.created) != 1 {
		t.Fatalf("created %d containers", len(e.d.created))
	}
	b := e.d.created[0]
	hc := b.HostConfig
	if b.Image != "ghcr.io/kiln/kiln-fn-bun:0.4.0" || b.Cmd[0] != "kiln-fn-install" || b.User != "65534:65534" || b.WorkingDir != "/app" ||
		!hc.ReadonlyRootfs || hc.CapDrop[0] != "ALL" || hc.NetworkMode != "kiln-fn" || !strings.HasSuffix(hc.Binds[1], "/var/lib/kiln/functions/.cache/ghcr-io-kiln-kiln-fn-bun:/cache:rw") {
		t.Fatalf("install container %+v", b)
	}
	if strings.Join(e.d.seen, ",") != "index.ts,lib/util.ts" {
		t.Fatalf("install saw %v", e.d.seen)
	}
	if e.d.names[0] != "kiln-fn-install-hello-r1" || e.d.removed[len(e.d.removed)-1] != "install-1" || !e.d.networks["kiln-fn"] {
		t.Fatalf("names %v removed %v networks %v", e.d.names, e.d.removed, e.d.networks)
	}
	if len(e.d.pulls) != 1 {
		t.Fatalf("pulls %v", e.d.pulls)
	}
	if !strings.Contains(e.st.String(), "+ hono@4.9.0") {
		t.Fatalf("install output not streamed: %s", e.st)
	}
	// Registered with the gateway: the host path, defaults filled in.
	s := e.gw.applied[0]
	if s.ReleaseDir != dir || s.Env["DATABASE_URL"] != "postgres://db" || s.Scaling.MaxInstances != 5 || s.Scaling.IdleTimeoutS != 300 || s.Limits.StartTimeoutS != 30 {
		t.Fatalf("spec %+v", s)
	}
	// Unit installed on first use, and the gateway restarted onto it.
	unit, err := e.fs.ReadFile(GatewayUnitPath)
	if err != nil || !strings.Contains(string(unit), "ExecStart=/usr/local/bin/kiln-agent fn-gateway") || !strings.Contains(string(unit), "RuntimeDirectory=kiln-fn") {
		t.Fatalf("unit: %v\n%s", err, unit)
	}
	if got := e.calls(); got != "systemctl daemon-reload\nsystemctl enable kiln-fn-gateway.service\nsystemctl restart kiln-fn-gateway.service" {
		t.Fatalf("systemctl calls:\n%s", got)
	}

	// Same release again: nothing to install, no systemctl.
	e2 := &bufStream{}
	res, err = e.f.Apply(context.Background(), payload("r1"), e2)
	if err != nil || res.(ApplyResult).Installed || len(e.d.created) != 1 {
		t.Fatalf("re-apply: %+v %v created=%d", res, err, len(e.d.created))
	}
	if len(e.r.Calls()) != 3 {
		t.Fatalf("gateway touched on an unchanged apply: %s", e.calls())
	}
}

func TestInstallFailureLeavesNoRelease(t *testing.T) {
	e := setup(t)
	e.d.exitCode = 1
	_, err := e.f.Apply(context.Background(), payload("r1"), e.st)
	if err == nil || !strings.Contains(err.Error(), "installing dependencies failed (exit 1)") {
		t.Fatalf("err %v", err)
	}
	entries, _ := os.ReadDir(e.fs.P("/var/lib/kiln/functions/hello/releases"))
	if len(entries) != 0 {
		t.Fatalf("left behind: %v", entries)
	}
	if len(e.gw.applied) != 0 {
		t.Fatal("a failed install must not reach the gateway")
	}
}

func TestInstallTimeout(t *testing.T) {
	e := setup(t)
	e.d.hang = true
	p := payload("r1")
	p.InstallTimeoutS = 1
	_, err := e.f.Apply(context.Background(), p, e.st)
	if err == nil || !strings.Contains(err.Error(), "timed out after 1s") {
		t.Fatalf("err %v", err)
	}
}

func TestGatewayFailureIsReported(t *testing.T) {
	e := setup(t)
	e.gw.err = errors.New("the runtime exited with code 1\nSyntaxError: Unexpected token")
	_, err := e.f.Apply(context.Background(), payload("r1"), e.st)
	if err == nil || !strings.Contains(err.Error(), "release r1 did not start") || !strings.Contains(err.Error(), "SyntaxError") {
		t.Fatalf("err %v", err)
	}
}

func TestGatewayStartedWhenDownAndRestartedWhenOutdated(t *testing.T) {
	e := setup(t)
	if _, err := e.fs.WriteFile(GatewayUnitPath, []byte(RenderGatewayUnit("/usr/local/bin/kiln-agent")), 0o644); err != nil {
		t.Fatal(err)
	}
	e.gw.version = "0.3.9"
	if err := e.f.ensureGateway(context.Background(), e.st); err != nil {
		t.Fatal(err)
	}
	if got := e.calls(); got != "systemctl restart kiln-fn-gateway.service" {
		t.Fatalf("outdated gateway: %s", got)
	}

	e = setup(t)
	_, _ = e.fs.WriteFile(GatewayUnitPath, []byte(RenderGatewayUnit("/usr/local/bin/kiln-agent")), 0o644)
	e.gw.down = true
	go func() { time.Sleep(50 * time.Millisecond); e.gw.mu.Lock(); e.gw.down = false; e.gw.mu.Unlock() }()
	if err := e.f.ensureGateway(context.Background(), e.st); err != nil {
		t.Fatal(err)
	}
	if got := e.calls(); got != "systemctl start kiln-fn-gateway.service" {
		t.Fatalf("stopped gateway: %s", got)
	}
}

func TestValidation(t *testing.T) {
	e := setup(t)
	for name, mut := range map[string]func(*ApplyPayload){
		"parent dir":    func(p *ApplyPayload) { p.Files[1].Path = "../x.ts" },
		"absolute":      func(p *ApplyPayload) { p.Files[1].Path = "/etc/passwd" },
		"dot file":      func(p *ApplyPayload) { p.Files[1].Path = ".kiln-release.json" },
		"inner dotdot":  func(p *ApplyPayload) { p.Files[1].Path = "lib/../../x" },
		"node_modules":  func(p *ApplyPayload) { p.Files[1].Path = "node_modules/hono/index.js" },
		"no entrypoint": func(p *ApplyPayload) { p.Entrypoint = "main.ts" },
		"duplicate":     func(p *ApplyPayload) { p.Files[1].Path = "index.ts" },
		"too big":       func(p *ApplyPayload) { p.Files[1].Content = strings.Repeat("x", maxBytes) },
		"bad release":   func(p *ApplyPayload) { p.Release = "R1" },
		"bad pull":      func(p *ApplyPayload) { p.Pull = "sometimes" },
		"pycache":       func(p *ApplyPayload) { p.Files[1].Path = "lib/__pycache__/x.pyc" },
		"too deep":      func(p *ApplyPayload) { p.Files[1].Path = "a/b/c/d/e/f/g/h/i.ts" },
		"file is a folder": func(p *ApplyPayload) {
			p.Files = append(p.Files, File{Path: "lib", Content: "x"})
		},
	} {
		p := payload("r1")
		mut(&p)
		_, err := e.f.Apply(context.Background(), p, e.st)
		var pe *commands.PayloadError
		if !errors.As(err, &pe) {
			t.Errorf("%s: want payload error, got %v", name, err)
		}
	}
}

func TestPruneKeepsNewestCurrentAndPrevious(t *testing.T) {
	e := setup(t)
	root := e.fs.P("/var/lib/kiln/functions/hello/releases")
	now := time.Now()
	for i := range 9 {
		d := filepath.Join(root, fmt.Sprintf("r%d", i))
		_ = os.MkdirAll(d, 0o755)
		_ = os.Chtimes(d, now.Add(time.Duration(i)*time.Minute), now.Add(time.Duration(i)*time.Minute))
	}
	stale := filepath.Join(root, ".tmp-r9-abcd")
	_ = os.MkdirAll(stale, 0o755)
	_ = os.Chtimes(stale, now.Add(-2*time.Hour), now.Add(-2*time.Hour))
	// Current r8 (newest), previous r0 (oldest, e.g. after a rollback), keep 3.
	e.f.prune("hello", 3, "r8", "r0")
	entries, _ := os.ReadDir(root)
	var names []string
	for _, en := range entries {
		names = append(names, en.Name())
	}
	if strings.Join(names, ",") != "r0,r6,r7,r8" {
		t.Fatalf("kept %v", names)
	}
}

func TestRemoveAndStatus(t *testing.T) {
	e := setup(t)
	if res, err := e.f.Status(context.Background(), StatusPayload{}, e.st); err != nil || len(res.(StatusResult).Functions) != 0 {
		t.Fatalf("no gateway yet: %+v %v", res, err)
	}
	if _, err := e.f.Apply(context.Background(), payload("r1"), e.st); err != nil {
		t.Fatal(err)
	}
	res, err := e.f.Status(context.Background(), StatusPayload{Site: "hello"}, e.st)
	if err != nil || len(res.(StatusResult).Functions) != 1 || res.(StatusResult).Functions[0].Running != 1 {
		t.Fatalf("status %+v %v", res, err)
	}
	out, err := e.f.Remove(context.Background(), RemovePayload{Site: "hello"}, e.st)
	if err != nil || !out.(map[string]bool)["removed"] || e.gw.deleted[0] != "hello" {
		t.Fatalf("remove %+v %v %v", out, err, e.gw.deleted)
	}
	if e.fs.Exists("/var/lib/kiln/functions/hello") {
		t.Fatal("function dir not removed")
	}
}

func TestSameCodeReinstallsWithItsSavedLockfile(t *testing.T) {
	e := setup(t)
	if _, err := e.f.Apply(context.Background(), payload("r1"), e.st); err != nil {
		t.Fatal(err)
	}
	// A later release of the same code (redeploy, scaling change, rollback to a pruned release) with a new image.
	p := payload("r2")
	p.Image = "ghcr.io/kiln/kiln-fn-bun:0.4.1"
	st := &bufStream{}
	if _, err := e.f.Apply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	lock, _ := os.ReadFile(e.fs.P("/var/lib/kiln/functions/hello/releases/r2/bun.lock"))
	if string(lock) != "resolved #1" || !strings.Contains(st.String(), "reusing the dependency versions") {
		t.Fatalf("lock %q\n%s", lock, st)
	}

	// Changed code resolves again.
	p = payload("r3")
	p.Files[0].Content += "// v2\n"
	if _, err := e.f.Apply(context.Background(), p, &bufStream{}); err != nil {
		t.Fatal(err)
	}
	if lock, _ := os.ReadFile(e.fs.P("/var/lib/kiln/functions/hello/releases/r3/bun.lock")); string(lock) != "resolved #3" {
		t.Fatalf("changed code reused a lock: %q", lock)
	}
}

func TestRunNowStreamsTheRun(t *testing.T) {
	e := setup(t)
	res, err := e.f.Run(context.Background(), RunPayload{Site: "hello", Schedule: "nightly"}, e.st)
	if err != nil || res.(RunResult).ExitCode != 0 || !strings.Contains(e.st.String(), "ran hello/nightly (manual)") {
		t.Fatalf("%+v %v %s", res, err, e.st)
	}
	var exit *commands.ExitError
	if _, err := e.f.Run(context.Background(), RunPayload{Site: "hello", Schedule: "broken"}, &bufStream{}); !errors.As(err, &exit) || exit.Code != 1 {
		t.Fatalf("failing run: %v", err)
	}
}

// After an agent upgrade the gateway still runs the old binary: the start-up refresh restarts it. Servers that never
// ran a function (no unit) are left alone.
func TestRefreshGatewayAtStartup(t *testing.T) {
	e := setup(t)
	if err := e.f.RefreshGateway(context.Background()); err != nil {
		t.Fatal(err)
	}
	if got := e.calls(); got != "" {
		t.Fatalf("no functions on this server, yet: %s", got)
	}

	_, _ = e.fs.WriteFile(GatewayUnitPath, []byte(RenderGatewayUnit("/usr/local/bin/kiln-agent")), 0o644)
	e.gw.version = "0.3.9"
	if err := e.f.RefreshGateway(context.Background()); err != nil {
		t.Fatal(err)
	}
	if got := e.calls(); got != "systemctl restart kiln-fn-gateway.service" {
		t.Fatalf("outdated gateway: %s", got)
	}
}
