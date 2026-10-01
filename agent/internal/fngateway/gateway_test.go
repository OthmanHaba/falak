package fngateway

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/kiln/agent/internal/docker"
	"github.com/kiln/agent/internal/otlp"
	tracepb "go.opentelemetry.io/proto/otlp/trace/v1"
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
	// echoHeaders makes instances answer with every request header they got.
	echoHeaders bool
	runs        []docker.CreateBody
	peak        int32
	live        int32
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
			b, echo := e.block, e.echoHeaders
			e.mu.Unlock()
			if b != nil {
				<-b
			}
			if echo {
				for k, v := range r.Header {
					fmt.Fprintf(w, "%s: %s\n", k, strings.Join(v, ","))
				}
			}
			fmt.Fprintf(w, "release=%s slot=%s header=%q host=%s xff=%s cold=%q", rel, slot, r.Header.Get(Header), r.Host, r.Header.Get("X-Forwarded-For"), r.Header.Get(ColdStartHeader))
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

// RunOnce records the run and "executes" it: exit 0 unless the schedule is "fails"; "slow" runs until ctx ends.
func (e *fakeEngine) RunOnce(ctx context.Context, name string, body docker.CreateBody, w io.Writer) (int, error) {
	e.mu.Lock()
	e.runs = append(e.runs, body)
	e.mu.Unlock()
	switch body.Labels[LabelRun] {
	case "slow":
		<-ctx.Done()
		return -1, ctx.Err()
	case "fails":
		fmt.Fprintln(w, "Error: nope")
		return 1, nil
	}
	fmt.Fprintf(w, "ran %s %s\n", name, strings.Join(body.Cmd, " "))
	return 0, nil
}

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

	// The client can see its response before the gateway releases the request slot.
	waitFor(t, "requests done", func() bool { return g.Status()[0].InFlight == 0 })

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

func TestRecreatesAContainerRemovedWhileStopped(t *testing.T) {
	e := newFake()
	g, clk, srv := newGateway(t, e)
	mustApply(t, g, spec("hello", "r1"))
	idleDown(g, clk, 2*time.Minute)
	if n := e.running("r1"); n != 0 {
		t.Fatalf("scale to zero: %d running", n)
	}
	// `docker container prune` removes the kept, stopped container.
	e.mu.Lock()
	for id := range e.cs {
		delete(e.cs, id)
	}
	e.mu.Unlock()

	if code, body := get(t, srv, "hello"); code != 200 || !strings.Contains(body, "release=r1") {
		t.Fatalf("cold start after prune: %d %s", code, body)
	}
}

func TestDeleteDoesNotWaitForOtherFunctions(t *testing.T) {
	e := newFake()
	g, _, srv := newGateway(t, e)
	mustApply(t, g, spec("busy", "r1"))
	mustApply(t, g, spec("gone", "r1"))
	block := make(chan struct{})
	e.setBlock(block)
	defer close(block)

	// A request holds busy's instance; its new release then drains for up to request_timeout_s (5s).
	go get(t, srv, "busy")
	waitFor(t, "busy request", func() bool { return atomic.LoadInt32(&e.live) == 1 })
	go func() { _, _ = g.Apply(context.Background(), spec("busy", "r2")) }()
	waitFor(t, "busy r2", func() bool { return e.running("r2") == 1 })

	start := time.Now()
	if removed, err := g.Delete(context.Background(), "gone"); err != nil || !removed {
		t.Fatalf("delete: %v %v", removed, err)
	}
	if d := time.Since(start); d > 2*time.Second {
		t.Fatalf("delete waited %s for another function's drain", d)
	}
}

// fakeAgent is the agent's OTLP receiver on a unix socket; it records the resources of the traces it gets.
type fakeAgent struct {
	mu    sync.Mutex
	spans []*tracepb.ResourceSpans
}

func newFakeAgent(t *testing.T) (*fakeAgent, string) {
	t.Helper()
	sock := filepath.Join(shortTemp(t), "agent.sock")
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	a := &fakeAgent{}
	srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		if r.URL.Path == "/v1/traces" && r.Header.Get("Content-Type") == "application/x-protobuf" {
			rs, _ := otlp.DecodeTraces(b)
			a.mu.Lock()
			a.spans = append(a.spans, rs...)
			a.mu.Unlock()
		}
		w.WriteHeader(200)
	})}
	go func() { _ = srv.Serve(ln) }()
	t.Cleanup(func() { _ = srv.Close() })
	return a, sock
}

func (a *fakeAgent) got() []*tracepb.ResourceSpans {
	a.mu.Lock()
	defer a.mu.Unlock()
	return append([]*tracepb.ResourceSpans(nil), a.spans...)
}

// shortTemp keeps unix socket paths under the OS limit (macOS: 104 bytes).
func shortTemp(t *testing.T) string {
	dir, err := os.MkdirTemp("/tmp", "kfn")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(dir) })
	return dir
}

func telemetrySpec(root, site, release string) Spec {
	s := spec(site, release)
	s.ReleaseDir = filepath.Join(root, site, "releases", release)
	s.Labels = map[string]string{"kiln.site.id": "01SITEULID", "kiln.release.id": "01RELEASEULID"}
	return s
}

func TestFunctionTelemetryIsStampedAndRelayed(t *testing.T) {
	agent, agentSock := newFakeAgent(t)
	root := shortTemp(t)
	e := newFake()
	g, _, _ := newGateway(t, e, func(o *Options) { o.AgentOTLPSocket = agentSock })
	s := telemetrySpec(root, "hello", "r1")
	mustApply(t, g, s)
	defer g.tel.closeAll()

	// The container mounts its function's socket.
	b := s.createBody(0)
	if b.HostConfig.Binds[1] != filepath.Join(root, "hello", "otlp")+":"+OTLPMount+":ro" || !strings.Contains(strings.Join(b.Env, " "), OTLPSocketEnv+"="+OTLPMount+"/otlp.sock") {
		t.Fatalf("binds %v env %v", b.HostConfig.Binds, b.Env)
	}

	// A function claiming to be another site is reported as itself.
	body := `{"resourceSpans":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"other"}},{"key":"kiln.site.id","value":{"stringValue":"01OTHERSITE"}},{"key":"kiln.org.id","value":{"stringValue":"01OTHERORG"}},{"key":"telemetry.sdk.language","value":{"stringValue":"js"}}]},"scopeSpans":[{"spans":[{"traceId":"0af7651916cd43dd8448eb211c80319c","spanId":"b7ad6b7169203331","name":"GET /","kind":2,"startTimeUnixNano":"1","endTimeUnixNano":"2"}]}]}]}`
	c := &http.Client{Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, "unix", filepath.Join(root, "hello", "otlp", "otlp.sock"))
	}}}
	resp, err := c.Post("http://fn/v1/traces", "application/json", strings.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != 200 {
		t.Fatalf("post: %d", resp.StatusCode)
	}
	got := agent.got()
	if len(got) != 1 {
		t.Fatalf("agent got %d batches", len(got))
	}
	attrs := map[string]string{}
	for _, kv := range got[0].Resource.Attributes {
		attrs[kv.Key] = kv.Value.GetStringValue()
	}
	if attrs["service.name"] != "hello" || attrs["kiln.site.id"] != "01SITEULID" || attrs["kiln.release.id"] != "01RELEASEULID" ||
		attrs["kiln.org.id"] != "" || attrs["telemetry.sdk.language"] != "js" || got[0].ScopeSpans[0].Spans[0].Name != "GET /" {
		t.Fatalf("resource %v", attrs)
	}

	// Deleting the function closes its socket.
	if _, err := g.Delete(context.Background(), "hello"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root, "hello", "otlp", "otlp.sock")); !os.IsNotExist(err) {
		t.Fatalf("socket left behind: %v", err)
	}
}

func TestColdStartsAreMarkedAndGatewayErrorsReported(t *testing.T) {
	agent, agentSock := newFakeAgent(t)
	root := shortTemp(t)
	e := newFake()
	g, clk, srv := newGateway(t, e, func(o *Options) { o.AgentOTLPSocket = agentSock })
	mustApply(t, g, telemetrySpec(root, "hello", "r1"))
	defer g.tel.closeAll()

	if _, body := get(t, srv, "hello"); !strings.Contains(body, "cold=\"\"") {
		t.Fatalf("warm request marked cold: %s", body)
	}
	idleDown(g, clk, 2*time.Minute)
	if _, body := get(t, srv, "hello"); !strings.Contains(body, "cold=\"1\"") {
		t.Fatalf("cold start not marked: %s", body)
	}
	if _, body := get(t, srv, "hello"); !strings.Contains(body, "cold=\"\"") {
		t.Fatalf("second request marked cold: %s", body)
	}

	// A release that cannot start: the gateway answers 502 and reports it.
	idleDown(g, clk, 2*time.Minute)
	e.mu.Lock()
	e.exitOnBoot = true
	e.mu.Unlock()
	if code, _ := get(t, srv, "hello"); code != http.StatusBadGateway {
		t.Fatalf("status %d", code)
	}
	waitFor(t, "gateway span", func() bool { return len(agent.got()) == 1 })
	sp := agent.got()[0].ScopeSpans[0].Spans[0]
	a := map[string]string{}
	for _, kv := range sp.Attributes {
		a[kv.Key] = otlp.AttrString(kv.Value)
	}
	if a["kiln.event.type"] != "request" || a["http.route"] != gatewayRoute || a["http.response.status_code"] != "502" || sp.Status.GetCode() != tracepb.Status_STATUS_CODE_ERROR {
		t.Fatalf("span %v", a)
	}
}

func TestScheduledRunsStreamOutputAndExitCode(t *testing.T) {
	e := newFake()
	g, _, _ := newGateway(t, e)
	s := spec("hello", "r1")
	mustApply(t, g, s)
	sock := filepath.Join(shortTemp(t), "gw.sock")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go func() { _ = g.Serve(ctx, "127.0.0.1:0", sock) }()
	c := NewClient(sock)
	waitFor(t, "admin socket", func() bool { _, err := c.Version(context.Background()); return err == nil })

	var out strings.Builder
	code, err := c.Run(context.Background(), "hello", RunRequest{Schedule: "nightly", Name: "Nightly cleanup", Cron: "0 3 * * *"}, &out)
	if err != nil || code != 0 || !strings.Contains(out.String(), "kiln-fn-run") {
		t.Fatalf("run: %d %v %q", code, err, out.String())
	}
	b := e.runs[0]
	env := strings.Join(b.Env, "\n")
	if b.Cmd[0] != RunCommand || b.Labels[LabelRun] != "nightly" || b.Labels[LabelSpec] != "run" || b.HostConfig.Binds[0] != s.ReleaseDir+":/app:ro" ||
		!strings.Contains(env, "KILN_TRIGGER=cron") || !strings.Contains(env, "KILN_SCHEDULE_NAME=Nightly cleanup") || !strings.Contains(env, "KILN_SCHEDULE_CRON=0 3 * * *") ||
		!strings.Contains(env, "GREETING=hi") || !b.HostConfig.ReadonlyRootfs {
		t.Fatalf("run container %+v", b)
	}

	out.Reset()
	if code, err := c.Run(context.Background(), "hello", RunRequest{Schedule: "fails"}, &out); err != nil || code != 1 || !strings.Contains(out.String(), "nope") {
		t.Fatalf("failing run: %d %v %q", code, err, out.String())
	}
	if code, err := c.Run(context.Background(), "hello", RunRequest{Schedule: "slow", TimeoutS: 1}, io.Discard); !errors.Is(err, ErrRunTimeout) || code != ExitTimeout {
		t.Fatalf("slow run: %d %v", code, err)
	}
	if got := truncate("ab€", 4); got != "ab" {
		t.Fatalf("truncate split a character: %q", got)
	}
	if _, err := c.Run(context.Background(), "nope", RunRequest{Schedule: "x"}, io.Discard); err == nil || !strings.Contains(err.Error(), "404") {
		t.Fatalf("unknown function: %v", err)
	}
	if _, err := c.Run(context.Background(), "hello", RunRequest{Schedule: "../x"}, io.Discard); err == nil {
		t.Fatal("invalid schedule accepted")
	}
	// Run containers are never instances.
	if e.running("r1") != 1 {
		t.Fatalf("instances changed: %d", e.running("r1"))
	}
}
