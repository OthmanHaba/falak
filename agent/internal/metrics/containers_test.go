package metrics

import (
	"context"
	"testing"
	"time"

	"github.com/kiln/agent/internal/docker"
	metricspb "go.opentelemetry.io/proto/otlp/metrics/v1"
)

type fakeEngine struct {
	list  []docker.ContainerSummary
	stats map[string][]docker.Stats
	calls map[string]int
}

func (f *fakeEngine) ContainerList(_ context.Context, _ bool, labels []string) ([]docker.ContainerSummary, error) {
	if len(labels) != 1 || labels[0] != "kiln.site" {
		panic("unexpected filter")
	}
	return f.list, nil
}

func (f *fakeEngine) ContainerStats(_ context.Context, id string, oneShot bool) (docker.Stats, error) {
	if !oneShot {
		panic("collector must use one-shot stats")
	}
	i := f.calls[id]
	f.calls[id]++
	return f.stats[id][min(i, len(f.stats[id])-1)], nil
}

func sample(total, system uint64, mem uint64) docker.Stats {
	var s docker.Stats
	s.CPUStats.CPUUsage.TotalUsage, s.CPUStats.SystemUsage, s.CPUStats.OnlineCPUs = total, system, 2
	s.MemoryStats.Usage, s.MemoryStats.Limit = mem, 1<<30
	s.Networks = map[string]struct {
		RxBytes uint64 `json:"rx_bytes"`
		TxBytes uint64 `json:"tx_bytes"`
	}{"eth0": {RxBytes: 10, TxBytes: 20}}
	return s
}

func metricByName(rm *metricspb.ResourceMetrics, name string) *metricspb.Metric {
	for _, m := range rm.ScopeMetrics[0].Metrics {
		if m.Name == name {
			return m
		}
	}
	return nil
}

func TestContainerCollector(t *testing.T) {
	e := &fakeEngine{calls: map[string]int{}, list: []docker.ContainerSummary{
		{ID: "aaaaaaaaaaaaaaaa", Names: []string{"/shop-app-1"}, Labels: map[string]string{"kiln.site": "shop", "kiln.service": "app"}},
		{ID: "bbbbbbbbbbbbbbbb", Names: []string{"/shop-redis-1"}, Labels: map[string]string{"kiln.site": "shop", "com.docker.compose.service": "redis"}},
		{ID: "cccccccccccccccc", Names: []string{"/blog-blue"}, Labels: map[string]string{"kiln.site": "blog"}},
	}, stats: map[string][]docker.Stats{
		"aaaaaaaaaaaaaaaa": {sample(1000, 10000, 100), sample(2000, 20000, 200)},
		"bbbbbbbbbbbbbbbb": {sample(0, 10000, 50)},
		"cccccccccccccccc": {sample(0, 10000, 70)},
	}}
	c := NewContainerCollector(e, nil)
	c.now = func() time.Time { return time.Unix(1700000000, 0) }

	first := c.Collect(context.Background())
	if len(first) != 2 {
		t.Fatalf("one resource per site, got %d", len(first))
	}
	if metricByName(first[1], "kiln.container.cpu.utilization") != nil {
		t.Fatal("cpu needs two samples")
	}
	second := c.Collect(context.Background())
	shop := second[1]
	if v := shop.Resource.Attributes[0]; v.Key != "service.name" || v.Value.GetStringValue() != "shop" {
		t.Fatalf("resource %v", shop.Resource)
	}
	cpu := metricByName(shop, "kiln.container.cpu.utilization").GetGauge().DataPoints
	// app: (2000-1000)/(20000-10000) * 2 cpus = 0.2
	if len(cpu) != 2 || cpu[0].GetAsDouble() != 0.2 {
		t.Fatalf("cpu %v", cpu)
	}
	mem := metricByName(shop, "kiln.container.memory.usage").GetGauge().DataPoints
	if mem[0].GetAsInt() != 200 || mem[1].GetAsInt() != 50 {
		t.Fatalf("mem %v", mem)
	}
	svc := map[string]bool{}
	for _, p := range mem {
		for _, a := range p.Attributes {
			if a.Key == "kiln.compose.service" {
				svc[a.Value.GetStringValue()] = true
			}
		}
	}
	if !svc["app"] || !svc["redis"] {
		t.Fatalf("compose service attrs %v", svc)
	}
	if metricByName(shop, "kiln.container.network.io").GetSum().DataPoints[0].GetAsInt() != 10 {
		t.Fatal("network rx")
	}
}
