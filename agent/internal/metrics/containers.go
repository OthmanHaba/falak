package metrics

import (
	"context"
	"os"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/docker"
	commonpb "go.opentelemetry.io/proto/otlp/common/v1"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
	resourcepb "go.opentelemetry.io/proto/otlp/resource/v1"
)

// ContainerEngine is the Engine API subset the container collector needs (docker.Client).
type ContainerEngine interface {
	ContainerList(ctx context.Context, all bool, labels []string) ([]docker.ContainerSummary, error)
	ContainerStats(ctx context.Context, id string, oneShot bool) (docker.Stats, error)
}

// ContainerCollector samples `docker stats` of site containers (label falak.site) into OTLP metrics
// falak.container.*: one ResourceMetrics per site (service.name = site slug, enriched to falak.site.id by
// the relay), data points labelled with the compose service and container.
type ContainerCollector struct {
	// Socket, when set, is checked before each collection (Docker may be installed after the agent started).
	Socket string
	engine ContainerEngine
	out    Emitter
	now    func() time.Time

	enabled  atomic.Bool
	interval atomic.Int64
	wake     chan struct{}

	mu   sync.Mutex
	prev map[string]docker.CPUStats
}

// NewContainerCollector creates an enabled collector with a 15 s interval.
func NewContainerCollector(engine ContainerEngine, out Emitter) *ContainerCollector {
	c := &ContainerCollector{engine: engine, out: out, now: time.Now, wake: make(chan struct{}, 1), prev: map[string]docker.CPUStats{}}
	c.enabled.Store(true)
	c.interval.Store(int64(15 * time.Second))
	return c
}

// Configure hot-reloads enablement and interval (shared with host metrics).
func (c *ContainerCollector) Configure(enabled bool, interval time.Duration) {
	if interval < time.Second {
		interval = 15 * time.Second
	}
	c.enabled.Store(enabled)
	c.interval.Store(int64(interval))
	select {
	case c.wake <- struct{}{}:
	default:
	}
}

// Run emits container metrics every interval until ctx is done.
func (c *ContainerCollector) Run(ctx context.Context) {
	for {
		t := time.NewTimer(time.Duration(c.interval.Load()))
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-c.wake:
			t.Stop()
			continue
		case <-t.C:
		}
		if c.enabled.Load() {
			for _, rm := range c.Collect(ctx) {
				c.out.EmitMetrics(rm)
			}
		}
	}
}

type containerPoint struct {
	attrs              []*commonpb.KeyValue
	cpu                float64
	mem, limit, rx, tx int64
}

// Collect samples every running site container once. CPU utilisation needs two samples, so a
// container's first sample only reports memory and network.
func (c *ContainerCollector) Collect(ctx context.Context) []*metricspb.ResourceMetrics {
	if c.Socket != "" {
		if _, err := os.Stat(c.Socket); err != nil {
			return nil
		}
	}
	list, err := c.engine.ContainerList(ctx, false, []string{"falak.site"})
	if err != nil {
		return nil
	}
	now := uint64(c.now().UnixNano())
	bySite := map[string][]containerPoint{}
	hasCPU := map[string]bool{}
	seen := map[string]bool{}
	for _, ct := range list {
		site := ct.Labels["falak.site"]
		if site == "" {
			continue
		}
		sctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		st, err := c.engine.ContainerStats(sctx, ct.ID, true)
		cancel()
		if err != nil {
			continue
		}
		seen[ct.ID] = true
		c.mu.Lock()
		prev, ok := c.prev[ct.ID]
		c.prev[ct.ID] = st.CPUStats
		c.mu.Unlock()
		service := ct.Labels["falak.service"]
		if service == "" {
			service = ct.Labels[docker.LabelComposeService]
		}
		name := ct.ID[:min(12, len(ct.ID))]
		if len(ct.Names) > 0 {
			name = strings.TrimPrefix(ct.Names[0], "/")
		}
		attrs := []*commonpb.KeyValue{str("container.id", ct.ID), str("container.name", name)}
		if service != "" {
			attrs = append(attrs, str("falak.compose.service", service))
		}
		p := containerPoint{attrs: attrs, cpu: -1, mem: int64(st.MemoryUsed()), limit: int64(st.MemoryStats.Limit)}
		if ok {
			p.cpu = docker.CPUPercent(prev, st.CPUStats) / 100
			hasCPU[site] = true
		}
		for _, n := range st.Networks {
			p.rx += int64(n.RxBytes)
			p.tx += int64(n.TxBytes)
		}
		bySite[site] = append(bySite[site], p)
	}
	c.mu.Lock()
	for id := range c.prev {
		if !seen[id] {
			delete(c.prev, id)
		}
	}
	c.mu.Unlock()

	sites := make([]string, 0, len(bySite))
	for s := range bySite {
		sites = append(sites, s)
	}
	sort.Strings(sites)
	var out []*metricspb.ResourceMetrics
	for _, site := range sites {
		dbl := func(v float64, attrs []*commonpb.KeyValue) *metricspb.NumberDataPoint {
			return &metricspb.NumberDataPoint{TimeUnixNano: now, Value: &metricspb.NumberDataPoint_AsDouble{AsDouble: v}, Attributes: attrs}
		}
		in := func(v int64, attrs []*commonpb.KeyValue) *metricspb.NumberDataPoint {
			return &metricspb.NumberDataPoint{TimeUnixNano: now, Value: &metricspb.NumberDataPoint_AsInt{AsInt: v}, Attributes: attrs}
		}
		var cpu, mem, limit, netio []*metricspb.NumberDataPoint
		for _, p := range bySite[site] {
			if p.cpu >= 0 {
				cpu = append(cpu, dbl(p.cpu, p.attrs))
			}
			mem = append(mem, in(p.mem, p.attrs))
			if p.limit > 0 {
				limit = append(limit, in(p.limit, p.attrs))
			}
			netio = append(netio, in(p.rx, append(append([]*commonpb.KeyValue{}, p.attrs...), str("network.io.direction", "receive"))),
				in(p.tx, append(append([]*commonpb.KeyValue{}, p.attrs...), str("network.io.direction", "transmit"))))
		}
		var ms []*metricspb.Metric
		if hasCPU[site] {
			ms = append(ms, gauge("falak.container.cpu.utilization", "1", cpu...))
		}
		ms = append(ms, gauge("falak.container.memory.usage", "By", mem...))
		if len(limit) > 0 {
			ms = append(ms, gauge("falak.container.memory.limit", "By", limit...))
		}
		ms = append(ms, counter("falak.container.network.io", "By", netio...))
		out = append(out, &metricspb.ResourceMetrics{
			Resource:     &resourcepb.Resource{Attributes: []*commonpb.KeyValue{str("service.name", site)}},
			ScopeMetrics: []*metricspb.ScopeMetrics{{Scope: &commonpb.InstrumentationScope{Name: "falak-agent/containers"}, Metrics: ms}},
		})
	}
	return out
}
