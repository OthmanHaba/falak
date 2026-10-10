package docker

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/resources"
)

func TestLimitsMapToHostConfig(t *testing.T) {
	var h HostConfig
	Limits{MemoryBytes: 512 << 20, MemoryReservationBytes: 256 << 20, CPUs: 1.5, PidsLimit: 256, RestartPolicy: "on-failure", MaxRestarts: 5,
		Log: &LogLimits{MaxSizeMB: 20, MaxFiles: 3}, OomScoreAdj: -500}.apply(&h)
	b, _ := json.Marshal(h)
	for _, want := range []string{`"Memory":536870912`, `"MemorySwap":536870912`, `"MemoryReservation":268435456`, `"NanoCpus":1500000000`,
		`"PidsLimit":256`, `"RestartPolicy":{"Name":"on-failure","MaximumRetryCount":5}`, `"OomScoreAdj":-500`,
		`"LogConfig":{"Type":"json-file","Config":{"max-file":"3","max-size":"20m"}}`} {
		if !strings.Contains(string(b), want) {
			t.Errorf("missing %s in %s", want, b)
		}
	}

	// Unset limits: Docker's defaults, the restart policy unless-stopped and no LogConfig (the daemon's driver).
	h = HostConfig{}
	Limits{}.apply(&h)
	b, _ = json.Marshal(h)
	if string(b) != `{"RestartPolicy":{"Name":"unless-stopped"}}` {
		t.Fatalf("%s", b)
	}
	// One log file when only the size is set.
	h = HostConfig{}
	Limits{Log: &LogLimits{MaxSizeMB: 10}}.apply(&h)
	if h.LogConfig.Config["max-file"] != "1" {
		t.Fatalf("%+v", h.LogConfig)
	}
}

func TestLimitsValidate(t *testing.T) {
	for _, tc := range []struct {
		l  Limits
		ok bool
	}{
		{Limits{}, true},
		{Limits{MemoryBytes: 64 << 20, MemoryReservationBytes: 32 << 20}, true},
		{Limits{MemoryBytes: 1 << 20}, false},
		{Limits{MemoryBytes: 64 << 20, MemoryReservationBytes: 128 << 20}, false},
		{Limits{MemoryReservationBytes: 128 << 20}, true},
		{Limits{RestartPolicy: "sometimes"}, false},
		{Limits{RestartPolicy: "always", MaxRestarts: 3}, false},
		{Limits{RestartPolicy: "on-failure", MaxRestarts: 3}, true},
		{Limits{OomScoreAdj: -1001}, false},
		{Limits{CPUs: -1}, false},
		{Limits{Log: &LogLimits{MaxSizeMB: 0}}, false},
	} {
		if err := tc.l.validate(); (err == nil) != tc.ok {
			t.Errorf("%+v: err=%v", tc.l, err)
		}
	}
}

func TestRunAndSwapCarryLimits(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	lim := Limits{MemoryBytes: 256 << 20, CPUs: 0.5, PidsLimit: 128, Log: &LogLimits{MaxSizeMB: 10, MaxFiles: 2}, OomScoreAdj: -500}
	fin, _ := exec1(t, s, "docker.run", RunPayload{Name: "web", Image: "nginx", Limits: lim})
	c := e.byName("web")
	if fin.Error != "" || c.body.HostConfig.Memory != 256<<20 || c.body.HostConfig.PidsLimit != 128 || c.body.HostConfig.LogConfig == nil {
		t.Fatalf("%s %+v", fin.Error, c.body.HostConfig)
	}
	// Changed limits recreate the container (the spec hash covers them).
	lim.MemoryBytes = 512 << 20
	exec1(t, s, "docker.run", RunPayload{Name: "web", Image: "nginx", Limits: lim})
	if e.byName("web").id == c.id || e.byName("web").body.HostConfig.Memory != 512<<20 {
		t.Fatal("not recreated with the new limit")
	}
	// Refused before anything is touched.
	fin, _ = exec1(t, s, "docker.run", RunPayload{Name: "web", Image: "nginx", Limits: Limits{MemoryBytes: 1}})
	if !strings.Contains(fin.Error, "6 MB") || e.byName("web") == nil {
		t.Fatalf("%+v", fin)
	}

	ok := 200
	p := swapPayload(healthServer(t, &ok), healthServer(t, &ok))
	p.Limits = Limits{MemoryBytes: 128 << 20, RestartPolicy: "on-failure", MaxRestarts: 4}
	fin, col := exec1(t, s, "deploy.container.swap", p)
	if fin.Error != "" {
		t.Fatal(fin.Error, col.Output(""))
	}
	hc := e.byName("falak-shop-blue").body.HostConfig
	if hc.Memory != 128<<20 || hc.RestartPolicy.Name != "on-failure" || hc.RestartPolicy.MaximumRetryCount != 4 {
		t.Fatalf("%+v", hc)
	}
}

func TestUpdateChangesLiveLimits(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	exec1(t, s, "docker.run", RunPayload{Name: "falak-shop-blue", Image: "shop:1", Labels: map[string]string{LabelSite: "shop"}})
	exec1(t, s, "docker.run", RunPayload{Name: "falak-other-blue", Image: "other:1", Labels: map[string]string{LabelSite: "other"}})
	exec1(t, s, "docker.run", RunPayload{Name: "stack-db-1", Image: "pg", Labels: map[string]string{labelComposeProject: "stack", labelComposeService: "db"}})

	fin, _ := exec1(t, s, "docker.update", UpdatePayload{Site: "shop", MemoryBytes: 256 << 20, CPUs: 2, RestartPolicy: "always"})
	r := fin.Result.(UpdateResult)
	if fin.Error != "" || !r.Changed || strings.Join(r.Containers, ",") != "falak-shop-blue" {
		t.Fatalf("%+v %s", r, fin.Error)
	}
	u := e.updates["falak-shop-blue"][0]
	if u.Memory != 256<<20 || u.MemorySwap != 256<<20 || u.NanoCPUs != 2e9 || u.RestartPolicy.Name != "always" || len(e.updates["falak-other-blue"]) != 0 {
		t.Fatalf("%+v", e.updates)
	}

	fin, _ = exec1(t, s, "docker.update", UpdatePayload{Project: "stack", Service: "db", PidsLimit: 100})
	if r := fin.Result.(UpdateResult); strings.Join(r.Containers, ",") != "stack-db-1" || e.updates["stack-db-1"][0].PidsLimit != 100 || e.updates["stack-db-1"][0].Memory != 0 {
		t.Fatalf("%+v %+v", r, e.updates)
	}

	for _, bad := range []UpdatePayload{{}, {Site: "shop", Project: "stack", Service: "db"}, {Project: "stack"}, {Site: "../x"}, {Site: "shop", MaxRestarts: 2}} {
		if fin, _ := exec1(t, s, "docker.update", bad); fin.Error == "" {
			t.Errorf("%+v accepted", bad)
		}
	}
}

func TestWatchReportsOOMKillsAndRestarts(t *testing.T) {
	s, e, _, _, _ := newSvc(t)
	oom := func(name string, labels map[string]string) EngineEvent {
		ev := EngineEvent{Type: "container", Action: "oom", Time: 1760000000}
		ev.Actor.ID = "x"
		ev.Actor.Attributes = map[string]string{"name": name}
		for k, v := range labels {
			ev.Actor.Attributes[k] = v
		}
		return ev
	}
	e.events = []EngineEvent{
		oom("unmanaged", nil),
		oom("falak-shop-green", map[string]string{LabelSite: "shop"}),
		oom("stack-db-1", map[string]string{labelComposeProject: "stack", labelComposeService: "db"}),
		oom("falak-db-01hzyinst00000000000000001", map[string]string{labelDBInstance: "01hzyinst00000000000000001"}),
	}
	got := make(chan resources.Event, 10)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go s.Watch(ctx, func(ev resources.Event) { got <- ev })
	var evs []resources.Event
	for len(evs) < 3 {
		select {
		case ev := <-got:
			evs = append(evs, ev)
		case <-time.After(5 * time.Second):
			t.Fatalf("events: %+v", evs)
		}
	}
	cancel()
	if evs[0].Kind != resources.KindOOMKill || evs[0].Site != "shop" || evs[0].Name != "falak-shop-green" || evs[0].At.Unix() != 1760000000 ||
		evs[1].Project != "stack" || evs[1].Service != "db" || evs[2].Instance != "01hzyinst00000000000000001" {
		t.Fatalf("%+v", evs)
	}

	// Restart counts: the first read is a baseline, increases are reported, unmanaged containers ignored.
	exec1(t, s, "docker.run", RunPayload{Name: "falak-shop-blue", Image: "shop:1", Labels: map[string]string{LabelSite: "shop"}})
	exec1(t, s, "docker.run", RunPayload{Name: "plain", Image: "x"})
	var counts resources.Counter
	var restarts []resources.Event
	collect := func(ev resources.Event) { restarts = append(restarts, ev) }
	s.restartsOnce(context.Background(), &counts, collect)
	e.byName("falak-shop-blue").restarts = 3
	e.byName("plain").restarts = 9
	s.restartsOnce(context.Background(), &counts, collect)
	if len(restarts) != 1 || restarts[0].Kind != resources.KindRestart || restarts[0].Count != 3 || restarts[0].Site != "shop" {
		t.Fatalf("%+v", restarts)
	}
}
