package db

import (
	"context"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

func TestReportConnections(t *testing.T) {
	h := newHarness(t)
	if _, err := h.db.InstanceCreate(context.Background(), createPayload(), stream()); err != nil {
		t.Fatal(err)
	}
	h.run.On("docker exec falak-db-"+instID+" falak-db stats", runner.Result{Stdout: []byte(`{"connections":85,"max_connections":100}` + "\n")})

	// The first report never waits for the exec: the figures arrive with a later heartbeat.
	if r := h.db.Report(context.Background()); r[0].Connections != nil {
		t.Fatalf("connections on the first report: %+v", r[0].Connections)
	}
	var got *Connections
	for i := 0; i < 200 && got == nil; i++ {
		time.Sleep(5 * time.Millisecond)
		got = h.db.Report(context.Background())[0].Connections
	}
	if got == nil || *got != (Connections{Used: 85, Max: 100}) {
		t.Fatalf("connections %+v", got)
	}

	// Cached: no exec per heartbeat.
	execs := func() int {
		n := 0
		for _, c := range h.run.Calls() {
			if len(c.Args) > 3 && c.Args[len(c.Args)-1] == "stats" {
				n++
			}
		}
		return n
	}
	before := execs()
	for i := 0; i < 5; i++ {
		h.db.Report(context.Background())
	}
	if execs() != before {
		t.Errorf("stats ran %d more times within StatsEvery", execs()-before)
	}

	// A stopped instance drops its figures.
	h.dock.containers["falak-db-"+instID].State.Running = false
	if r := h.db.Report(context.Background()); r[0].Connections != nil {
		t.Errorf("connections of a stopped instance: %+v", r[0].Connections)
	}
}
