package fngateway

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"net/http/httputil"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/kiln/agent/internal/otlp"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
)

// Options configure a Gateway.
type Options struct {
	Engine Engine
	// StateFile persists the registered functions (gateway.json); "" = in memory only.
	StateFile string
	// QueueLimit caps the requests waiting for an instance, per function (default 1000).
	QueueLimit int
	// ReadyPoll is how often a starting instance is probed (default 10ms).
	ReadyPoll time.Duration
	// StopTimeout is the grace period of `docker stop` (default 10s).
	StopTimeout time.Duration
	// ReapInterval is how often idle instances are stopped and min_instances enforced (default 2s; <0 = never,
	// tests call Reap).
	ReapInterval time.Duration
	// Dial probes readiness (default: TCP connect).
	Dial   func(ctx context.Context, addr string) error
	Now    func() time.Time
	Logger *slog.Logger
	// Version is reported on GET /v1/version (the agent restarts an outdated gateway).
	Version string
	// AgentOTLPSocket is the agent's OTLP receiver; functions' telemetry is relayed there ("" = no telemetry).
	AgentOTLPSocket string
}

type instState int

const (
	stopped instState = iota
	starting
	ready
	stopping
)

func (s instState) String() string {
	return [...]string{"stopped", "starting", "ready", "stopping"}[s]
}

type instance struct {
	slot     int
	id       string // container id ("" until created)
	addr     string // runtime address while ready
	st       instState
	inflight int
	lastUsed time.Time
	readyAt  time.Time // when it last became ready (a request that arrived before waited for a cold start)
	gone     bool      // removed (release switched or function deleted)
}

type function struct {
	spec     Spec
	hash     string
	insts    []*instance // current release, by slot
	nextSlot int
	waiters  int

	startFailures uint64
	lastStartErr  error
	lastFailAt    time.Time

	requests    uint64
	coldStarts  uint64
	lastRequest time.Time
}

// Gateway is the function proxy + scaler.
type Gateway struct {
	o Options

	mu      sync.Mutex
	fns     map[string]*function
	changed chan struct{} // closed and replaced on every state change

	applyMu sync.Mutex
	bg      sync.WaitGroup // boots, stops and drains in flight

	proxy *httputil.ReverseProxy
	tel   *telemetry // nil without an agent receiver
}

// Errors of acquire, mapped to HTTP statuses.
var (
	errUnknown      = errors.New("unknown function")
	errQueueFull    = errors.New("function is at capacity, try again")
	errStartTimeout = errors.New("function did not start in time")
)

type startFailed struct{ err error }

func (e startFailed) Error() string { return "function failed to start: " + e.err.Error() }

// New returns a gateway; call Load to restore state and adopt containers, then Serve.
func New(o Options) *Gateway {
	if o.QueueLimit <= 0 {
		o.QueueLimit = 1000
	}
	if o.ReadyPoll <= 0 {
		o.ReadyPoll = 10 * time.Millisecond
	}
	if o.StopTimeout <= 0 {
		o.StopTimeout = 10 * time.Second
	}
	if o.ReapInterval == 0 {
		o.ReapInterval = 2 * time.Second
	}
	if o.Dial == nil {
		o.Dial = func(ctx context.Context, addr string) error {
			var d net.Dialer
			c, err := d.DialContext(ctx, "tcp", addr)
			if err == nil {
				c.Close()
			}
			return err
		}
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	g := &Gateway{o: o, fns: map[string]*function{}, changed: make(chan struct{})}
	if o.AgentOTLPSocket != "" {
		g.tel = newTelemetry(o.AgentOTLPSocket, g.identity, o.Logger)
	}
	tr := &http.Transport{
		Proxy:               nil,
		DialContext:         (&net.Dialer{Timeout: 5 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		MaxIdleConns:        1024,
		MaxIdleConnsPerHost: 256,
		IdleConnTimeout:     60 * time.Second,
		DisableCompression:  true,
	}
	g.proxy = &httputil.ReverseProxy{
		Transport:     tr,
		FlushInterval: -1, // stream responses (SSE, chunked) as they come
		Rewrite: func(pr *httputil.ProxyRequest) {
			t := pr.In.Context().Value(targetKey{}).(*instance)
			pr.Out.URL.Scheme = "http"
			pr.Out.URL.Host = t.addr
			pr.Out.Host = pr.In.Host
			// Caddy already set these; Rewrite strips them from the outbound request, so carry them over.
			for _, h := range []string{"Forwarded", "X-Forwarded-For", "X-Forwarded-Host", "X-Forwarded-Proto"} {
				if v := pr.In.Header.Values(h); len(v) > 0 {
					pr.Out.Header[h] = v
				}
			}
			pr.Out.Header.Del(Header)
			pr.Out.Header.Del(ColdStartHeader)
			if cold, _ := pr.In.Context().Value(coldKey{}).(bool); cold {
				pr.Out.Header.Set(ColdStartHeader, "1")
			}
		},
		ErrorHandler: g.proxyError,
	}
	return g
}

type targetKey struct{}

type coldKey struct{}

type siteKey struct{}

type arrivedKey struct{}

// failBackoff is how long after a failed start requests fail fast instead of booting again.
const failBackoff = 2 * time.Second

// broadcast wakes every waiter; call with g.mu held.
func (g *Gateway) broadcast() {
	close(g.changed)
	g.changed = make(chan struct{})
}

// ServeHTTP is the proxy (127.0.0.1:7070).
func (g *Gateway) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	site := r.Header.Get(Header)
	r.Header.Del(Header)
	arrived := time.Now()
	inst, spec, err := g.acquire(r.Context(), site)
	if err != nil {
		g.writeError(w, r, err)
		if g.tel != nil && !errors.Is(err, errUnknown) && r.Context().Err() == nil {
			g.tel.requestSpan(site, r, errorStatus(err), arrived, err.Error())
		}
		return
	}
	defer g.done(inst)

	g.mu.Lock()
	cold := inst.readyAt.After(arrived)
	g.mu.Unlock()
	ctx := context.WithValue(r.Context(), targetKey{}, inst)
	ctx = context.WithValue(ctx, coldKey{}, cold)
	ctx = context.WithValue(ctx, siteKey{}, site)
	ctx = context.WithValue(ctx, arrivedKey{}, arrived)
	if !isUpgrade(r) {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, spec.requestTimeout())
		defer cancel()
	}
	g.proxy.ServeHTTP(w, r.WithContext(ctx))
}

func isUpgrade(r *http.Request) bool {
	return strings.EqualFold(r.Header.Get("Upgrade"), "websocket") || strings.Contains(strings.ToLower(r.Header.Get("Connection")), "upgrade")
}

// errorStatus is the status writeError answers err with.
func errorStatus(err error) int {
	var sf startFailed
	switch {
	case errors.Is(err, errQueueFull):
		return http.StatusServiceUnavailable
	case errors.Is(err, errStartTimeout):
		return http.StatusGatewayTimeout
	case errors.As(err, &sf):
		return http.StatusBadGateway
	default:
		return http.StatusBadGateway
	}
}

// identity is the resource a function's telemetry is reported under.
func (g *Gateway) identity(site string) ([]*commonpb.KeyValue, bool) {
	g.mu.Lock()
	defer g.mu.Unlock()
	f := g.fns[site]
	if f == nil {
		return nil, false
	}
	id := []*commonpb.KeyValue{otlp.Str("service.name", site)}
	for _, k := range []string{"kiln.site.id", "kiln.release.id", "kiln.deployment.id"} {
		if v := f.spec.Labels[k]; v != "" {
			id = append(id, otlp.Str(k, v))
		}
	}
	return id, true
}

func (g *Gateway) writeError(w http.ResponseWriter, r *http.Request, err error) {
	var sf startFailed
	switch {
	case errors.Is(err, errUnknown):
		http.Error(w, "unknown function", http.StatusNotFound)
	case errors.Is(err, errQueueFull):
		w.Header().Set("Retry-After", "1")
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
	case errors.Is(err, errStartTimeout):
		http.Error(w, err.Error(), http.StatusGatewayTimeout)
	case errors.As(err, &sf):
		http.Error(w, "function failed to start", http.StatusBadGateway)
	case r.Context().Err() != nil:
		// The client went away while waiting.
	default:
		http.Error(w, err.Error(), http.StatusBadGateway)
	}
}

func (g *Gateway) proxyError(w http.ResponseWriter, r *http.Request, err error) {
	if errors.Is(err, context.DeadlineExceeded) {
		http.Error(w, "function timed out", http.StatusGatewayTimeout)
		return
	}
	if r.Context().Err() != nil && errors.Is(err, context.Canceled) {
		return
	}
	// The runtime is gone (crashed or stopped under us): take the instance out of rotation; the next request
	// starts it again.
	if inst, ok := r.Context().Value(targetKey{}).(*instance); ok {
		var oe *net.OpError
		if errors.As(err, &oe) && oe.Op == "dial" {
			g.mu.Lock()
			if inst.st == ready {
				inst.st = stopped
				inst.addr = ""
				g.broadcast()
			}
			g.mu.Unlock()
			if site, ok := r.Context().Value(siteKey{}).(string); ok && g.tel != nil {
				arrived, _ := r.Context().Value(arrivedKey{}).(time.Time)
				g.tel.requestSpan(site, r, http.StatusBadGateway, arrived, "function unavailable")
			}
		}
	}
	g.o.Logger.Warn("function request failed", "err", err)
	http.Error(w, "function unavailable", http.StatusBadGateway)
}

// pick is the ready instance with room that is already the busiest (packing load keeps the others idle so
// they can scale down). Call with g.mu held.
func (f *function) pick() *instance {
	var best *instance
	for _, in := range f.insts {
		if in.st != ready || in.inflight >= f.spec.Scaling.Concurrency {
			continue
		}
		if best == nil || in.inflight > best.inflight {
			best = in
		}
	}
	return best
}

func (f *function) count(states ...instState) int {
	n := 0
	for _, in := range f.insts {
		for _, s := range states {
			if in.st == s {
				n++
			}
		}
	}
	return n
}

// acquire waits for an instance with room (starting instances as needed) and reserves a request slot on it.
func (g *Gateway) acquire(ctx context.Context, site string) (*instance, Spec, error) {
	g.mu.Lock()
	f := g.fns[site]
	if f == nil || site == "" {
		g.mu.Unlock()
		return nil, Spec{}, errUnknown
	}
	deadline := time.NewTimer(f.spec.startTimeout())
	defer deadline.Stop()
	failures := f.startFailures
	queued := false
	unqueue := func() {
		if queued {
			f.waiters--
			queued = false
		}
	}
	for {
		if g.fns[site] != f {
			unqueue()
			g.mu.Unlock()
			return nil, Spec{}, errUnknown
		}
		if in := f.pick(); in != nil {
			unqueue()
			in.inflight++
			f.requests++
			f.lastRequest = g.o.Now()
			spec := f.spec
			g.mu.Unlock()
			return in, spec, nil
		}
		// A start failed since this request arrived, or moments ago: do not boot a broken release per request.
		if f.count(ready, starting) == 0 && (f.startFailures != failures || (!f.lastFailAt.IsZero() && g.o.Now().Sub(f.lastFailAt) < failBackoff)) {
			err := f.lastStartErr
			unqueue()
			g.mu.Unlock()
			return nil, Spec{}, startFailed{err}
		}
		if !queued {
			if f.waiters >= g.o.QueueLimit {
				g.mu.Unlock()
				return nil, Spec{}, errQueueFull
			}
			f.waiters++
			queued = true
		}
		g.scaleUp(f)
		ch := g.changed
		g.mu.Unlock()

		select {
		case <-ch:
			g.mu.Lock()
		case <-deadline.C:
			g.mu.Lock()
			unqueue()
			g.mu.Unlock()
			return nil, Spec{}, errStartTimeout
		case <-ctx.Done():
			g.mu.Lock()
			unqueue()
			g.mu.Unlock()
			return nil, Spec{}, ctx.Err()
		}
	}
}

// scaleUp starts one more instance when the waiting requests exceed what the starting ones will absorb and
// max_instances allows it. Call with g.mu held.
func (g *Gateway) scaleUp(f *function) {
	startingN := f.count(starting)
	active := f.count(ready, starting, stopping)
	if f.waiters <= startingN*f.spec.Scaling.Concurrency || active >= f.spec.Scaling.MaxInstances {
		return
	}
	g.startSlot(f, f.count(ready) == 0)
}

// startSlot boots a stopped instance (its container is kept) or a new slot. Call with g.mu held.
func (g *Gateway) startSlot(f *function, cold bool) {
	var in *instance
	for _, c := range f.insts {
		if c.st == stopped {
			in = c
			break
		}
	}
	if in == nil {
		in = &instance{slot: f.nextSlot}
		f.nextSlot++
		f.insts = append(f.insts, in)
	}
	in.st = starting
	g.broadcast()
	spec := f.spec
	g.bg.Add(1)
	go func() {
		defer g.bg.Done()
		start := time.Now()
		err := g.boot(context.Background(), spec, in)
		g.mu.Lock()
		defer g.mu.Unlock()
		if in.gone {
			// The release switched (or the function was deleted) while this instance booted.
			if id := in.id; id != "" {
				g.bg.Add(1)
				go func() { defer g.bg.Done(); _ = g.o.Engine.Remove(context.Background(), id) }()
			}
			return
		}
		if err != nil {
			in.st = stopped
			in.addr = ""
			f.startFailures++
			f.lastStartErr = err
			f.lastFailAt = g.o.Now()
			g.o.Logger.Error("function instance failed to start", "site", spec.Site, "slot", in.slot, "err", err)
		} else {
			in.st = ready
			in.lastUsed = g.o.Now()
			in.readyAt = time.Now()
			if cold {
				f.coldStarts++
			}
			g.o.Logger.Info("function instance ready", "site", spec.Site, "slot", in.slot, "cold", cold, "ms", time.Since(start).Milliseconds())
		}
		g.broadcast()
		g.persistLocked()
	}()
}

// boot creates (first time) and starts the instance's container and waits until its runtime accepts
// connections. It does not touch g.mu except to record the container id.
func (g *Gateway) boot(ctx context.Context, spec Spec, in *instance) error {
	ctx, cancel := context.WithTimeout(ctx, spec.startTimeout())
	defer cancel()
	g.mu.Lock()
	id := in.id
	g.mu.Unlock()
	for attempt := 0; ; attempt++ {
		if id == "" {
			var err error
			if id, err = g.create(ctx, spec, in); err != nil {
				return err
			}
		}
		err := g.o.Engine.Start(ctx, id)
		if err == nil {
			break
		}
		// A kept (stopped) container was removed behind the gateway's back: create it again, once.
		if !isGone(err) || attempt > 0 {
			return err
		}
		g.mu.Lock()
		in.id = ""
		g.mu.Unlock()
		id = ""
	}
	addr, err := g.waitReady(ctx, id)
	if err != nil {
		if logs := g.o.Engine.Logs(context.Background(), id, 30); logs != "" {
			err = fmt.Errorf("%w\n%s", err, logs)
		}
		_ = g.o.Engine.Stop(context.Background(), id, time.Second)
		return err
	}
	g.mu.Lock()
	in.addr = addr
	g.mu.Unlock()
	return nil
}

// create makes the instance's container and records its id.
func (g *Gateway) create(ctx context.Context, spec Spec, in *instance) (string, error) {
	if err := g.o.Engine.EnsureNetwork(ctx); err != nil {
		return "", fmt.Errorf("network %s: %w", Network, err)
	}
	name := ContainerName(spec.Site, spec.Release, in.slot)
	id, err := g.o.Engine.Create(ctx, name, spec.createBody(in.slot))
	if isNameConflict(err) { // left over from an earlier gateway run
		_ = g.o.Engine.Remove(ctx, name)
		id, err = g.o.Engine.Create(ctx, name, spec.createBody(in.slot))
	}
	if err != nil {
		return "", err
	}
	g.mu.Lock()
	in.id = id
	g.mu.Unlock()
	return id, nil
}

// waitReady polls until the runtime accepts a TCP connection; it fails fast when the container exits.
func (g *Gateway) waitReady(ctx context.Context, id string) (string, error) {
	var addr string
	lastState := time.Time{}
	for {
		if addr == "" || time.Since(lastState) > 250*time.Millisecond {
			st, err := g.o.Engine.State(ctx, id)
			if err != nil {
				if ctx.Err() != nil {
					return "", errStartTimeout
				}
				return "", err
			}
			if !st.Running {
				return "", fmt.Errorf("the runtime exited with code %d", st.ExitCode)
			}
			addr, lastState = st.Addr, time.Now()
		}
		if addr != "" {
			dctx, cancel := context.WithTimeout(ctx, 250*time.Millisecond)
			err := g.o.Dial(dctx, addr)
			cancel()
			if err == nil {
				return addr, nil
			}
		}
		select {
		case <-ctx.Done():
			return "", errStartTimeout
		case <-time.After(g.o.ReadyPoll):
		}
	}
}

// done releases a request slot.
func (g *Gateway) done(in *instance) {
	g.mu.Lock()
	in.inflight--
	in.lastUsed = g.o.Now()
	g.broadcast()
	g.mu.Unlock()
}

// Reap stops instances idle for idle_timeout_s (and extras beyond max_instances) down to min_instances, and
// starts instances up to min_instances.
func (g *Gateway) Reap() {
	now := g.o.Now()
	g.mu.Lock()
	defer g.mu.Unlock()
	for _, f := range g.fns {
		sc := f.spec.Scaling
		active := f.count(ready, starting)
		idle := make([]*instance, 0)
		for _, in := range f.insts {
			if in.st == ready && in.inflight == 0 {
				idle = append(idle, in)
			}
		}
		// Stop the highest slots first, so the low ones stay warm.
		sort.Slice(idle, func(i, j int) bool { return idle[i].slot > idle[j].slot })
		for _, in := range idle {
			if active <= sc.MinInstances {
				break
			}
			if now.Sub(in.lastUsed) < f.spec.idleTimeout() && active <= sc.MaxInstances {
				continue
			}
			in.st = stopping
			active--
			g.stopAsync(f, in)
		}
		for active < sc.MinInstances {
			g.startSlot(f, false)
			active++
		}
	}
}

// stopAsync stops an instance's container (kept for a fast restart). Call with g.mu held.
func (g *Gateway) stopAsync(f *function, in *instance) {
	id, site := in.id, f.spec.Site
	g.bg.Add(1)
	go func() {
		defer g.bg.Done()
		if err := g.o.Engine.Stop(context.Background(), id, g.o.StopTimeout); err != nil {
			g.o.Logger.Warn("stop function instance", "site", site, "err", err)
		}
		g.mu.Lock()
		if in.st == stopping {
			in.st = stopped
			in.addr = ""
		}
		g.broadcast()
		g.mu.Unlock()
	}()
}

// Wait blocks until background boots, stops and drains have finished (tests, shutdown).
func (g *Gateway) Wait() { g.bg.Wait() }

// Apply registers a function's release: the new release boots one instance, then takes the traffic, and the
// previous release's instances drain and are removed. A scaling-only change keeps the instances.
func (g *Gateway) Apply(ctx context.Context, spec Spec) (ApplyResult, error) {
	if err := spec.Normalize(); err != nil {
		return ApplyResult{}, err
	}
	g.applyMu.Lock()
	defer g.applyMu.Unlock()

	if g.tel != nil {
		g.tel.ensure(spec.Site, otlpDir(spec.ReleaseDir))
	}
	hash := spec.containerHash()
	g.mu.Lock()
	f := g.fns[spec.Site]
	prev := ""
	slot := 0
	if f != nil {
		prev = f.spec.Release
		if f.hash == hash {
			changed := f.spec.Scaling != spec.Scaling
			f.spec = spec
			g.broadcast()
			g.persistLocked()
			g.mu.Unlock()
			if changed {
				g.Reap()
			}
			return ApplyResult{Release: spec.Release, PreviousRelease: prev, Changed: changed}, nil
		}
		slot = f.nextSlot
		f.nextSlot++
	}
	g.mu.Unlock()

	in := &instance{slot: slot, st: starting}
	start := time.Now()
	if err := g.boot(ctx, spec, in); err != nil {
		if in.id != "" {
			_ = g.o.Engine.Remove(context.Background(), in.id)
		}
		return ApplyResult{}, err
	}
	boot := time.Since(start)

	g.mu.Lock()
	in.st, in.lastUsed, in.readyAt = ready, g.o.Now(), time.Now()
	var old []*instance
	if f == nil {
		f = &function{nextSlot: slot + 1}
		g.fns[spec.Site] = f
	} else {
		old = f.insts
	}
	oldTimeout := f.spec.requestTimeout()
	f.spec, f.hash, f.insts = spec, hash, []*instance{in}
	f.startFailures, f.lastStartErr, f.lastFailAt = 0, nil, time.Time{}
	for _, o := range old {
		o.gone = true
	}
	g.broadcast()
	g.persistLocked()
	g.mu.Unlock()

	g.drain(old, oldTimeout)
	g.Reap() // min_instances of the new release
	return ApplyResult{Release: spec.Release, PreviousRelease: prev, BootMS: boot.Milliseconds(), Changed: true}, nil
}

// drain removes instances once their in-flight requests finish (at most timeout later); the returned channel is
// closed when it is done.
func (g *Gateway) drain(insts []*instance, timeout time.Duration) <-chan struct{} {
	done := make(chan struct{})
	if len(insts) == 0 {
		close(done)
		return done
	}
	g.bg.Add(1)
	go func() {
		defer g.bg.Done()
		defer close(done)
		deadline := time.NewTimer(timeout)
		defer deadline.Stop()
		for {
			g.mu.Lock()
			busy := 0
			for _, in := range insts {
				busy += in.inflight
			}
			ch := g.changed
			g.mu.Unlock()
			if busy == 0 {
				break
			}
			select {
			case <-ch:
				continue
			case <-deadline.C:
			}
			break
		}
		for _, in := range insts {
			g.mu.Lock()
			id := in.id
			g.mu.Unlock()
			if id != "" {
				if err := g.o.Engine.Remove(context.Background(), id); err != nil {
					g.o.Logger.Warn("remove drained function instance", "id", id, "err", err)
				}
			}
		}
	}()
	return done
}

// Delete deregisters a function and removes all its containers.
func (g *Gateway) Delete(ctx context.Context, site string) (bool, error) {
	g.applyMu.Lock()
	defer g.applyMu.Unlock()
	g.mu.Lock()
	f := g.fns[site]
	var insts []*instance
	timeout := 5 * time.Second
	if f != nil {
		delete(g.fns, site)
		insts = f.insts
		for _, in := range insts {
			in.gone = true
		}
		timeout = f.spec.requestTimeout()
		g.broadcast()
		g.persistLocked()
	}
	g.mu.Unlock()
	if g.tel != nil {
		g.tel.remove(site)
	}
	// Wait for this function's drain only: other functions' boots and drains go on (and keep using g.bg).
	select {
	case <-g.drain(insts, timeout):
	case <-ctx.Done():
		return f != nil, ctx.Err()
	}
	// Anything else of the site (e.g. from before a lost state file).
	cs, err := g.o.Engine.List(ctx, []string{LabelService + "=" + ServiceName, LabelSite + "=" + site})
	if err != nil {
		return f != nil, err
	}
	for _, c := range cs {
		if err := g.o.Engine.Remove(ctx, c.ID); err != nil {
			return f != nil, err
		}
	}
	return f != nil || len(cs) > 0, nil
}

// Status returns every function's counters, sorted by site.
func (g *Gateway) Status() []Status {
	g.mu.Lock()
	defer g.mu.Unlock()
	out := make([]Status, 0, len(g.fns))
	for site, f := range g.fns {
		s := Status{Site: site, Release: f.spec.Release, Running: f.count(ready), Starting: f.count(starting),
			ColdStarts: f.coldStarts, Requests: f.requests}
		for _, in := range f.insts {
			s.InFlight += in.inflight
		}
		if !f.lastRequest.IsZero() {
			t := f.lastRequest.UTC()
			s.LastRequestAt = &t
		}
		out = append(out, s)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Site < out[j].Site })
	return out
}

// Load restores the persisted functions and adopts their containers: running ones of the current release
// serve again, stopped ones are kept for a fast start, and containers of other releases or unknown functions
// are removed.
func (g *Gateway) Load(ctx context.Context) error {
	st, err := readState(g.o.StateFile)
	if err != nil {
		return err
	}
	cs, err := g.o.Engine.List(ctx, []string{LabelService + "=" + ServiceName, LabelManaged + "=true"})
	if err != nil {
		return err
	}
	g.mu.Lock()
	for site, ps := range st.Functions {
		spec := ps.Spec
		if spec.Normalize() != nil {
			continue
		}
		g.fns[site] = &function{spec: spec, hash: spec.containerHash(), nextSlot: ps.NextSlot}
	}
	g.mu.Unlock()

	for _, c := range cs {
		site := c.Labels[LabelSite]
		g.mu.Lock()
		f := g.fns[site]
		keep := f != nil && c.Labels[LabelRelease] == f.spec.Release && c.Labels[LabelSpec] == f.hash
		g.mu.Unlock()
		if !keep {
			g.o.Logger.Info("removing stale function container", "site", site, "id", c.ID)
			_ = g.o.Engine.Remove(ctx, c.ID)
			continue
		}
		slot, _ := strconv.Atoi(c.Labels[LabelSlot])
		in := &instance{slot: slot, id: c.ID, st: stopped, lastUsed: g.o.Now()}
		if c.State == "running" {
			if s, err := g.o.Engine.State(ctx, c.ID); err == nil && s.Running && s.Addr != "" {
				dctx, cancel := context.WithTimeout(ctx, time.Second)
				if g.o.Dial(dctx, s.Addr) == nil {
					in.st, in.addr = ready, s.Addr
				}
				cancel()
			}
			if in.st != ready {
				_ = g.o.Engine.Stop(ctx, c.ID, g.o.StopTimeout)
			}
		}
		g.mu.Lock()
		f.insts = append(f.insts, in)
		if slot >= f.nextSlot {
			f.nextSlot = slot + 1
		}
		g.mu.Unlock()
	}
	g.mu.Lock()
	sockets := map[string]string{}
	for site, f := range g.fns {
		sort.Slice(f.insts, func(i, j int) bool { return f.insts[i].slot < f.insts[j].slot })
		sockets[site] = otlpDir(f.spec.ReleaseDir)
	}
	g.persistLocked()
	g.mu.Unlock()
	if g.tel != nil {
		for site, dir := range sockets {
			g.tel.ensure(site, dir)
		}
	}
	return nil
}
