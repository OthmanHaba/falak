package deploy

import (
	"context"
	"errors"
	"strings"
	"sync"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/obs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

type recSink struct {
	mu   sync.Mutex
	logs []obs.LogRecord
}

func (r *recSink) EmitLog(l obs.LogRecord) { r.mu.Lock(); r.logs = append(r.logs, l); r.mu.Unlock() }
func (r *recSink) EmitSpan(obs.Span)       {}

func (r *recSink) take() []obs.LogRecord {
	r.mu.Lock()
	defer r.mu.Unlock()
	out := r.logs
	r.logs = nil
	return out
}

type failingProcs struct{}

func (failingProcs) Restart(context.Context, []string) error {
	return errors.New("worker restart failed")
}

// Mirrors the Grafana alert / dashboard LogQL:
//
//	{service_name="falak-agent"} | falak_event_type="deployment" | falak_deployment_status="failed"
//	sum by (falak_site_id, falak_deployment_id) (...)
func checkEvent(t *testing.T, l obs.LogRecord, status, phase, release string) {
	t.Helper()
	if l.Service != "falak-agent" {
		t.Errorf("service.name %q, want falak-agent", l.Service)
	}
	if l.SiteID != "01J9Z8Y7X6W5V4T3S2R1Q0P9S1" {
		t.Errorf("falak.site.id (resource) %q", l.SiteID)
	}
	want := map[string]string{
		"falak.event.type":        "deployment",
		"falak.deployment.status": status,
		"falak.deployment.phase":  phase,
		"falak.deployment.id":     "01J9Z8Y7X6W5V4T3S2R1Q0P9D1",
		"falak.release.id":        release,
	}
	for k, v := range want {
		if l.Attrs[k] != v {
			t.Errorf("%s/%s: attr %s = %q, want %q", status, phase, k, l.Attrs[k], v)
		}
	}
	if status == StatusFailed {
		if l.Severity != "ERROR" || l.Body == "" || strings.HasPrefix(l.Body, "deployment ") {
			t.Errorf("failed event must be ERROR with the error message as body: %+v", l)
		}
	} else if l.Severity != "INFO" {
		t.Errorf("severity %q", l.Severity)
	}
}

func TestDeploymentLifecycleEvents(t *testing.T) {
	d, fake, _, srv, arts := newDeployer(t)
	sink := &recSink{}
	d.o.Events = sink
	dc := &Context{SiteID: "01J9Z8Y7X6W5V4T3S2R1Q0P9S1", DeploymentID: "01J9Z8Y7X6W5V4T3S2R1Q0P9D1", Commit: "abc", Trigger: "push"}
	bg := context.Background()

	// fetch → started (only when work actually starts).
	b := appTar(t, "v1")
	arts["/a/1.tgz"] = b
	fp := FetchPayload{Site: "shop", ReleaseID: r1, Context: dc, Artifact: Artifact{URL: srv.URL + "/a/1.tgz", SHA256: sum(b), Headers: map[string]string{"Authorization": "Bearer art"}}}
	if _, err := d.Fetch(bg, fp, st()); err != nil {
		t.Fatal(err)
	}
	ev := sink.take()
	if len(ev) != 1 {
		t.Fatalf("fetch events: %+v", ev)
	}
	checkEvent(t, ev[0], StatusStarted, PhaseFetch, r1)
	if _, err := d.Fetch(bg, fp, st()); err != nil || len(sink.take()) != 0 {
		t.Fatal("idempotent re-fetch must not emit another started event")
	}

	// fetch failure (sha mismatch) → started + failed.
	bad := fp
	bad.ReleaseID = r2
	bad.Artifact.SHA256 = strings.Repeat("0", 64)
	if _, err := d.Fetch(bg, bad, st()); err == nil {
		t.Fatal("expected sha mismatch")
	}
	ev = sink.take()
	if len(ev) != 2 {
		t.Fatalf("got %+v", ev)
	}
	checkEvent(t, ev[1], StatusFailed, PhaseFetch, r2)
	if !strings.Contains(ev[1].Body, "sha256 mismatch") {
		t.Fatalf("body %q", ev[1].Body)
	}

	// prepare success → no event; failure → failed.
	if _, err := d.Prepare(bg, PreparePayload{Site: "shop", ReleaseID: r1, Context: dc}, st()); err != nil || len(sink.take()) != 0 {
		t.Fatalf("prepare: %v", err)
	}
	if _, err := d.Prepare(bg, PreparePayload{Site: "shop", ReleaseID: r3, Context: dc}, st()); err == nil {
		t.Fatal("prepare of unfetched release must fail")
	}
	ev = sink.take()
	checkEvent(t, ev[0], StatusFailed, PhasePrepare, r3)

	// hook failure → failed with the hook name.
	fake.On("/bin/bash", runner.Result{ExitCode: 1})
	if _, err := d.Hook(bg, HookPayload{Site: "shop", ReleaseID: r1, Name: "migrate", Script: "false", Context: dc}, st()); err == nil {
		t.Fatal("expected hook failure")
	}
	ev = sink.take()
	checkEvent(t, ev[0], StatusFailed, PhaseHook, r1)
	if ev[0].Attrs["falak.deployment.hook"] != "migrate" {
		t.Fatal("hook name missing")
	}

	// activate → succeeded; re-activate (no-op) emits nothing.
	if _, err := d.Activate(bg, ActivatePayload{Site: "shop", ReleaseID: r1, Context: dc}, st()); err != nil {
		t.Fatal(err)
	}
	ev = sink.take()
	checkEvent(t, ev[0], StatusSucceeded, PhaseActivate, r1)
	if _, err := d.Activate(bg, ActivatePayload{Site: "shop", ReleaseID: r1, Context: dc}, st()); err != nil || len(sink.take()) != 0 {
		t.Fatal("no-op activate must not emit")
	}

	// activate whose reload fails → failed (not succeeded).
	arts["/a/2.tgz"] = b
	fp2 := fp
	fp2.ReleaseID, fp2.Artifact.URL = r2, srv.URL+"/a/2.tgz"
	fp2.Artifact.SHA256 = sum(b)
	if _, err := d.Fetch(bg, fp2, st()); err != nil {
		t.Fatal(err)
	}
	sink.take()
	d.o.Procs = failingProcs{}
	if _, err := d.Activate(bg, ActivatePayload{Site: "shop", ReleaseID: r2, Context: dc, Reload: []Reload{{Kind: "proc"}}}, st()); err == nil {
		t.Fatal("expected reload failure")
	}
	ev = sink.take()
	if len(ev) != 1 {
		t.Fatalf("got %+v", ev)
	}
	checkEvent(t, ev[0], StatusFailed, PhaseActivate, r2)

	// rollback → rolled_back labelled with the target release.
	if _, err := d.Rollback(bg, RollbackPayload{Site: "shop", Context: dc}, st()); err != nil {
		t.Fatal(err)
	}
	ev = sink.take()
	checkEvent(t, ev[0], StatusRolledBack, PhaseRollback, r1)
}
