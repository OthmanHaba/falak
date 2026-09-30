package fngateway

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/kiln/agent/internal/docker"
)

// fakeEngine runs each "container" as an httptest server while it is started.
type fakeEngine struct {
	mu         sync.Mutex
	cs         map[string]*fakeContainer
	seq        int
	creates    int
	starts     int
	startDelay time.Duration
	exitOnBoot bool // the runtime exits right away
	neverReady bool // the runtime never listens
	// block, when set, holds every request until it is closed.
	block chan struct{}
	peak  int32
	live  int32
}

type fakeContainer struct {
	id, name string
	labels   map[string]string
	env      []string
	srv      *httptest.Server
	running  bool
}

func newFake() *fakeEngine { return &fakeEngine{cs: map[string]*fakeContainer{}} }

func (e *fakeEngine) Create(_ context.Context, name string, body docker.CreateBody) (string, error) {
	e.mu.Lock()
	defer e.mu.Unlock()
	for _, c := range e.cs {
		if c.name == name {
			return "", &docker.APIError{Status: 409, Message: "name in use"}
		}
	}
	e.seq++
	e.creates++
	id := fmt.Sprintf("c%d", e.seq)
	e.cs[id] = &fakeContainer{id: id, name: name, labels: body.Labels, env: body.Env}
	return id, nil
}

func (e *fakeEngine) Start(_ context.Context, id string) error {
	time.Sleep(e.startDelay)
	e.mu.Lock()
	defer e.mu.Unlock()
	c := e.cs[id]
	if c == nil {
		return &docker.APIError{Status: 404, Message: "no such container"}
	}
	e.starts++
	if c.running {
		return nil
	}
	c.running = !e.exitOnBoot
	if c.running && !e.neverReady {
		rel, slot := c.labels[LabelRelease], c.labels[LabelSlot]
		c.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			n := atomic.AddInt32(&e.live, 1)
			defer atomic.AddInt32(&e.live, -1)
			for {
				p := atomic.LoadInt32(&e.peak)
				if n <= p || atomic.CompareAndSwapInt32(&e.peak, p, n) {
					break
				}
			}
			e.mu.Lock()
			b := e.block
			e.mu.Unlock()
			if b != nil {
				<-b
			}
			fmt.Fprintf(w, "release=%s slot=%s header=%q host=%s xff=%s", rel, slot, r.Header.Get(Header), r.Host, r.Header.Get("X-Forwarded-For"))
		}))
	}
	return nil
}

func (e *fakeEngine) Stop(_ context.Context, id string, _ time.Duration) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	if c := e.cs[id]; c != nil {
		e.stopLocked(c)
	}
	return nil
}

func (e *fakeEngine) stopLocked(c *fakeContainer) {
	if c.srv != nil {
		c.srv.CloseClientConnections()
		c.srv.Close()
		c.srv = nil
	}
	c.running = false
}

func (e *fakeEngine) Remove(_ context.Context, id string) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	for key, c := range e.cs {
		if c.id == id || c.name == id {
			e.stopLocked(c)
			delete(e.cs, key)
		}
	}
	return nil
}

func (e *fakeEngine) List(_ context.Context, labels []string) ([]docker.ContainerSummary, error) {
	e.mu.Lock()
	defer e.mu.Unlock()
	var out []docker.ContainerSummary
outer:
	for _, c := range e.cs {
		for _, l := range labels {
			k, v, _ := strings.Cut(l, "=")
			if c.labels[k] != v {
				continue outer
			}
		}
		st := "exited"
		if c.running {
			st = "running"
		}
		out = append(out, docker.ContainerSummary{ID: c.id, Names: []string{"/" + c.name}, State: st, Labels: c.labels})
	}
	return out, nil
}

func (e *fakeEngine) State(_ context.Context, id string) (ContainerState, error) {
	e.mu.Lock()
	defer e.mu.Unlock()
	c := e.cs[id]
	if c == nil {
		return ContainerState{}, nil
	}
	st := ContainerState{Exists: true, Running: c.running}
	if !c.running && e.exitOnBoot {
		st.ExitCode = 1
	}
	if c.srv != nil {
		st.Addr = strings.TrimPrefix(c.srv.URL, "http://")
	} else if c.running {
		st.Addr = "127.0.0.1:1" // nothing listens
	}
	return st, nil
}

func (e *fakeEngine) Logs(context.Context, string, int) string { return "SyntaxError: boom" }
func (e *fakeEngine) EnsureNetwork(context.Context) error      { return nil }

func (e *fakeEngine) running(release string) int {
	e.mu.Lock()
	defer e.mu.Unlock()
	n := 0
	for _, c := range e.cs {
		if c.running && (release == "" || c.labels[LabelRelease] == release) {
			n++
		}
	}
	return n
}

func (e *fakeEngine) count() (containers, creates, starts int) {
	e.mu.Lock()
	defer e.mu.Unlock()
	return len(e.cs), e.creates, e.starts
}

func (e *fakeEngine) setBlock(ch chan struct{}) {
	e.mu.Lock()
	e.block = ch
	e.mu.Unlock()
}

type clock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *clock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.t }
func (c *clock) Add(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

func newGateway(t *testing.T, e *fakeEngine, mut ...func(*Options)) (*Gateway, *clock, *httptest.Server) {
	t.Helper()
	clk := &clock{t: time.Date(2026, 9, 30, 12, 0, 0, 0, time.UTC)}
	o := Options{Engine: e, ReapInterval: -1, Now: clk.Now, ReadyPoll: 2 * time.Millisecond,
		Logger: slog.New(slog.NewTextHandler(io.Discard, nil))}
	for _, m := range mut {
		m(&o)
	}
	g := New(o)
	srv := httptest.NewServer(g)
	t.Cleanup(func() {
		srv.Close()
		g.Wait()
		for _, c := range e.cs {
			e.stopLocked(c)
		}
	})
	return g, clk, srv
}

func spec(site, release string) Spec {
	return Spec{Site: site, Release: release, Image: "kiln-fn-bun:test", Entrypoint: "index.ts", ReleaseDir: "/var/lib/kiln/functions/" + site + "/releases/" + release,
		Env: map[string]string{"GREETING": "hi"}, Scaling: Scaling{MinInstances: 0, MaxInstances: 3, Concurrency: 1, IdleTimeoutS: 60}, Limits: Limits{StartTimeoutS: 5, RequestTimeoutS: 5}}
}

func get(t *testing.T, srv *httptest.Server, site string) (int, string) {
	req, _ := http.NewRequest(http.MethodGet, srv.URL+"/hello", nil)
	if site != "" {
		req.Header.Set(Header, site)
	}
	req.Host = "hello.example.com"
	req.Header.Set("X-Forwarded-For", "203.0.113.9")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return 0, err.Error() // goroutines call this too: no t.Fatal
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(b)
}

func mustApply(t *testing.T, g *Gateway, s Spec) ApplyResult {
	t.Helper()
	res, err := g.Apply(context.Background(), s)
	if err != nil {
		t.Fatal(err)
	}
	return res
}

// idleDown advances the clock past the idle timeout and reaps.
func idleDown(g *Gateway, clk *clock, d time.Duration) {
	clk.Add(d)
	g.Reap()
	g.Wait()
}

func waitFor(t *testing.T, what string, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !cond() {
		if time.Now().After(deadline) {
			t.Fatalf("timed out waiting for %s", what)
		}
		time.Sleep(2 * time.Millisecond)
	}
}

func TestUnknownFunctionAndHeaderHandling(t *testing.T) {
	e := newFake()
	g, _, srv := newGateway(t, e)
	if code, _ := get(t, srv, "nope"); code != http.StatusNotFound {
		t.Fatalf("unknown function: %d", code)
	}
	if code, _ := get(t, srv, ""); code != http.StatusNotFound {
		t.Fatalf("no header: %d", code)
	}
	mustApply(t, g, spec("hello", "r1"))
	code, body := get(t, srv, "hello")
	if code != 200 {
		t.Fatalf("status %d: %s", code, body)
	}
	for _, want := range []string{`header=""`, "host=hello.example.com", "xff=203.0.113.9", "release=r1"} {
		if !strings.Contains(body, want) {
			t.Errorf("missing %s in %q", want, body)
		}
	}
}

func TestColdStartServesQueuedConcurrentRequests(t *testing.T) {
	e := newFake()
	g, clk, srv := newGateway(t, e)
	s := spec("hello", "r1")
	s.Scaling.MaxInstances, s.Scaling.Concurrency = 1, 10
	mustApply(t, g, s)
	idleDown(g, clk, time.Hour)
	if n := e.running(""); n != 0 {
		t.Fatalf("scaled to zero expected, %d running", n)
	}
	containers, creates, starts := e.count()
	if containers != 1 {
		t.Fatalf("the stopped container is kept for a fast start, have %d", containers)
	}

	e.startDelay = 50 * time.Millisecond
	var wg sync.WaitGroup
	codes := make(chan int, 8)
	for range 8 {
		wg.Add(1)
		go func() { defer wg.Done(); c, _ := get(t, srv, "hello"); codes <- c }()
	}
	wg.Wait()
	close(codes)
	for c := range codes {
		if c != 200 {
			t.Fatalf("status %d", c)
		}
	}
	_, creates2, starts2 := e.count()
	if creates2 != creates || starts2 != starts+1 {
		t.Fatalf("one docker start of the kept container expected: creates %d→%d starts %d→%d", creates, creates2, starts, starts2)
	}
	if st := g.Status()[0]; st.ColdStarts != 1 || st.Requests != 8 || st.Running != 1 {
		t.Fatalf("status %+v", st)
	}
}

func TestScalesUpToMaxUnderLoadAndBackToZero(t *testing.T) {
	e := newFake()
	g, clk, srv := newGateway(t, e)
	s := spec("hello", "r1") // max 3, concurrency 1
	mustApply(t, g, s)
	block := make(chan struct{})
	e.setBlock(block)

	var wg sync.WaitGroup
	codes := make(chan int, 6)
	for range 6 {
		wg.Add(1)
		go func() { defer wg.Done(); c, _ := get(t, srv, "hello"); codes <- c }()
	}
	waitFor(t, "3 instances", func() bool { return e.running("r1") == 3 })
	waitFor(t, "3 busy", func() bool { return atomic.LoadInt32(&e.live) == 3 })
	time.Sleep(20 * time.Millisecond)
	if n := e.running("r1"); n != 3 {
		t.Fatalf("max_instances exceeded: %d", n)
	}
	close(block)
	wg.Wait()
	close(codes)
	for c := range codes {
		if c != 200 {
			t.Fatalf("status %d", c)
		}
	}
	if p := atomic.LoadInt32(&e.peak); p != 3 {
		t.Fatalf("peak concurrency %d, want 3 (concurrency 1 × max 3)", p)
	}

	// Not idle long enough: nothing stops.
	idleDown(g, clk, 30*time.Second)
	if n := e.running("r1"); n != 3 {
		t.Fatalf("stopped before idle_timeout: %d running", n)
	}
	idleDown(g, clk, time.Minute)
	if n := e.running("r1"); n != 0 {
		t.Fatalf("scale to zero: %d running", n)
	}
}

func TestMinInstancesStayWarm(t *testing.T) {
	e := newFake()
	g, clk, _ := newGateway(t, e)
	s := spec("hello", "r1")
	s.Scaling.MinInstances = 2
	mustApply(t, g, s)
	g.Wait()
	waitFor(t, "min instances", func() bool { return e.running("r1") == 2 })
	idleDown(g, clk, time.Hour)
	if n := e.running("r1"); n != 2 {
		t.Fatalf("min_instances=2 but %d running", n)
	}
	// Lowering min lets them go.
	s.Scaling.MinInstances = 0
	if res := mustApply(t, g, s); res.Changed != true || res.BootMS != 0 {
		t.Fatalf("scaling-only change should keep the instances: %+v", res)
	}
	idleDown(g, clk, time.Hour)
	if n := e.running("r1"); n != 0 {
		t.Fatalf("%d running after min lowered", n)
	}
}

func TestReleaseSwitchDrainsTheOldRelease(t *testing.T) {
	e := newFake()
	g, _, srv := newGateway(t, e)
	s := spec("hello", "r1")
	mustApply(t, g, s)

	block := make(chan struct{})
	e.setBlock(block)
	inflight := make(chan string, 1)
	go func() { _, b := get(t, srv, "hello"); inflight <- b }()
	waitFor(t, "request in flight", func() bool { return atomic.LoadInt32(&e.live) == 1 })

	e.setBlock(nil)
	res := mustApply(t, g, spec("hello", "r2"))
	if res.PreviousRelease != "r1" || res.Release != "r2" {
		t.Fatalf("result %+v", res)
	}
	if _, body := get(t, srv, "hello"); !strings.Contains(body, "release=r2") {
		t.Fatalf("new requests must go to r2: %s", body)
	}
	if e.running("r1") != 1 {
		t.Fatal("the old instance must keep serving its in-flight request")
	}
	close(block)
	if b := <-inflight; !strings.Contains(b, "release=r1") {
		t.Fatalf("in-flight request not completed by r1: %s", b)
	}
	g.Wait()
	for _, c := range e.cs {
		if c.labels[LabelRelease] == "r1" {
			t.Fatal("r1 container not removed after draining")
		}
	}
}

func TestFailedBootKeepsThePreviousRelease(t *testing.T) {
	e := newFake()
	g, _, srv := newGateway(t, e)
	mustApply(t, g, spec("hello", "r1"))
	e.exitOnBoot = true
	_, err := g.Apply(context.Background(), spec("hello", "r2"))
	if err == nil || !strings.Contains(err.Error(), "exited with code 1") || !strings.Contains(err.Error(), "SyntaxError: boom") {
		t.Fatalf("want exit error with logs, got %v", err)
	}
	e.exitOnBoot = false
	if _, body := get(t, srv, "hello"); !strings.Contains(body, "release=r1") {
		t.Fatalf("r1 must keep serving: %s", body)
	}
	for _, c := range e.cs {
		if c.labels[LabelRelease] == "r2" {
			t.Fatal("failed r2 container left behind")
		}
	}
}

func TestQueueFullIs503(t *testing.T) {
	e := newFake()
	g, _, srv := newGateway(t, e, func(o *Options) { o.QueueLimit = 1 })
	s := spec("hello", "r1")
	s.Scaling.MaxInstances = 1
	mustApply(t, g, s)
	block := make(chan struct{})
	e.setBlock(block)
	defer close(block)

	go get(t, srv, "hello") // occupies the only slot
	waitFor(t, "busy", func() bool { return atomic.LoadInt32(&e.live) == 1 })
	go get(t, srv, "hello") // waits in the queue
	waitFor(t, "queued", func() bool {
		g.mu.Lock()
		defer g.mu.Unlock()
		return g.fns["hello"].waiters == 1
	})
	if code, _ := get(t, srv, "hello"); code != http.StatusServiceUnavailable {
		t.Fatalf("queue full: %d", code)
	}
}

func TestStartTimeoutIs504AndCrashIs502(t *testing.T) {
	e := newFake()
	g, clk, srv := newGateway(t, e)
	s := spec("hello", "r1")
	s.Limits.StartTimeoutS = 1
	mustApply(t, g, s)
	idleDown(g, clk, time.Hour)

	e.neverReady = true
	start := time.Now()
	if code, _ := get(t, srv, "hello"); code != http.StatusGatewayTimeout {
		t.Fatalf("start timeout: %d", code)
	}
	if d := time.Since(start); d > 3*time.Second {
		t.Fatalf("took %s", d)
	}
	g.Wait()

	e.neverReady = false
	e.exitOnBoot = true
	clk.Add(time.Minute) // past the failure backoff
	if code, _ := get(t, srv, "hello"); code != http.StatusBadGateway {
		t.Fatalf("crashing runtime: %d", code)
	}
}

func TestRestartAdoptsRunningContainers(t *testing.T) {
	e := newFake()
	state := filepath.Join(t.TempDir(), "gateway.json")
	g1, _, _ := newGateway(t, e, func(o *Options) { o.StateFile = state })
	mustApply(t, g1, spec("hello", "r1"))
	s2 := spec("other", "r7")
	s2.Scaling.MinInstances = 0
	mustApply(t, g1, s2)
	// A leftover container of a release that is no longer current.
	e.mu.Lock()
	e.cs["stale"] = &fakeContainer{id: "stale", name: "kiln-fn-hello-r0-0", labels: map[string]string{LabelManaged: "true", LabelService: ServiceName, LabelSite: "hello", LabelRelease: "r0"}}
	e.mu.Unlock()

	_, creates, starts := e.count()
	g2, _, srv2 := newGateway(t, e, func(o *Options) { o.StateFile = state })
	if err := g2.Load(context.Background()); err != nil {
		t.Fatal(err)
	}
	st := g2.Status()
	if len(st) != 2 || st[0].Site != "hello" || st[0].Running != 1 || st[1].Site != "other" {
		t.Fatalf("status after restart: %+v", st)
	}
	if code, body := get(t, srv2, "hello"); code != 200 || !strings.Contains(body, "release=r1") {
		t.Fatalf("adopted instance not serving: %d %s", code, body)
	}
	if _, c2, s2n := e.count(); c2 != creates || s2n != starts {
		t.Fatalf("adoption must not create/start containers: creates %d→%d starts %d→%d", creates, c2, starts, s2n)
	}
	if _, ok := e.cs["stale"]; ok {
		t.Fatal("stale container of an old release not removed")
	}
}

func TestDeleteRemovesContainers(t *testing.T) {
	e := newFake()
	g, _, srv := newGateway(t, e)
	mustApply(t, g, spec("hello", "r1"))
	removed, err := g.Delete(context.Background(), "hello")
	if err != nil || !removed {
		t.Fatalf("delete: %v %v", removed, err)
	}
	if n, _, _ := e.count(); n != 0 {
		t.Fatalf("%d containers left", n)
	}
	if code, _ := get(t, srv, "hello"); code != http.StatusNotFound {
		t.Fatalf("deleted function: %d", code)
	}
}

func TestAdminAPIRoundTrip(t *testing.T) {
	e := newFake()
	g, _, _ := newGateway(t, e, func(o *Options) { o.Version = "0.4.0" })
	sock := filepath.Join(t.TempDir(), "gw.sock")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- g.Serve(ctx, "127.0.0.1:0", sock) }()
	c := NewClient(sock)
	waitFor(t, "admin socket", func() bool { _, err := c.Version(context.Background()); return err == nil })
	if v, _ := c.Version(context.Background()); v != "0.4.0" {
		t.Fatalf("version %q", v)
	}
	res, err := c.Apply(context.Background(), spec("hello", "r1"))
	if err != nil || res.Release != "r1" {
		t.Fatalf("apply: %+v %v", res, err)
	}
	bad := spec("hello", "r2")
	bad.Site = "Bad Site"
	if _, err := c.Apply(context.Background(), bad); err == nil {
		t.Fatal("invalid spec accepted")
	}
	st, err := c.Status(context.Background())
	if err != nil || len(st) != 1 || st[0].Release != "r1" {
		t.Fatalf("status %+v %v", st, err)
	}
	if removed, err := c.Delete(context.Background(), "hello"); err != nil || !removed {
		t.Fatalf("delete %v %v", removed, err)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestCreateBodyIsHardened(t *testing.T) {
	s := spec("hello", "01j9z8y7x6w5v4t3s2r1q0p9na")
	_ = s.Normalize()
	b := s.createBody(2)
	hc := b.HostConfig
	if !hc.ReadonlyRootfs || hc.CapDrop[0] != "ALL" || hc.SecurityOpt[0] != "no-new-privileges" || hc.PidsLimit != 256 ||
		hc.NetworkMode != Network || hc.Binds[0] != s.ReleaseDir+":/app:ro" || b.User != UID || len(hc.PortBindings) != 0 {
		t.Fatalf("not hardened: %+v", b)
	}
	if ContainerName(s.Site, s.Release, 2) != "kiln-fn-hello-01j9z8y7x6w5-2" {
		t.Fatalf("name %s", ContainerName(s.Site, s.Release, 2))
	}
	if b.Labels[LabelSite] != "hello" || b.Labels[LabelService] != ServiceName || b.Labels[LabelSlot] != "2" {
		t.Fatalf("labels %v", b.Labels)
	}
}
