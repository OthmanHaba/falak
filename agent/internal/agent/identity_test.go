package agent

import (
	"bytes"
	"context"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/enroll"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// serviceFake answers systemctl like a host where falak-agent.service is running (or not); stops records how many
// enrollments the fleet had seen when the service was stopped.
func serviceFake(fleet *fakeFleet, running bool, stopsAt *[]int) *runnertest.Fake {
	f := &runnertest.Fake{}
	f.OnFunc("systemctl is-active", func(runnertest.Call) (runner.Result, error) {
		if running {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl stop", func(runnertest.Call) (runner.Result, error) {
		*stopsAt = append(*stopsAt, fleet.enrolls)
		running = false
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl start", func(runnertest.Call) (runner.Result, error) { running = true; return runner.Result{}, nil })
	return f
}

func TestEnrollStopsAndRestartsARunningAgent(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	ctx := context.Background()
	var stops []int
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, serviceFake(fleet, true, &stops)); err != nil {
		t.Fatal(err)
	}
	if len(stops) != 0 {
		t.Fatal("a first enrollment has no identity to protect")
	}

	f := serviceFake(fleet, true, &stops)
	var out bytes.Buffer
	if err := EnrollOnly(ctx, cfg, quiet(), &out, f); err != nil {
		t.Fatal(err)
	}
	if len(stops) != 1 || stops[0] != 1 {
		t.Fatalf("the service must stop before the new enrollment: %v", stops)
	}
	lines := strings.Join(f.Lines(), "|")
	if lines != "systemctl is-active --quiet falak-agent.service|systemctl stop falak-agent.service|systemctl start falak-agent.service" {
		t.Fatal(lines)
	}
	if !strings.Contains(out.String(), "stopping falak-agent while its identity is replaced") || !strings.HasSuffix(out.String(), "started falak-agent again\n") {
		t.Fatalf("%q", out.String())
	}

	// A failed enrollment starts it again too, with the old identity.
	cfg.Token = "already-used"
	f = serviceFake(fleet, true, &stops)
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, f); err == nil || !f.Ran("systemctl start falak-agent.service") {
		t.Fatalf("%v %v", err, f.Lines())
	}

	// Not running: left alone.
	cfg.Token = "one-time"
	f = serviceFake(fleet, false, &stops)
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, f); err != nil || f.Ran("systemctl stop") || f.Ran("systemctl start") {
		t.Fatalf("%v %v", err, f.Lines())
	}
}

// An explicit `enroll --token` after a crashed replacement enrolls with the token; only `run` restores.
func TestEnrollWithTokenDoesNotRestoreAnUnfinishedReplacement(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	ctx := context.Background()
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	paths := enroll.Paths{Dir: cfg.EtcDir}
	backup := filepath.Join(cfg.EtcDir, PreviousDir, "20261002T120000Z")
	os.MkdirAll(backup, 0o700)
	for _, f := range paths.Files() {
		os.Rename(f, filepath.Join(backup, filepath.Base(f)))
	}
	os.WriteFile(filepath.Join(backup, ".incomplete"), nil, 0o600)
	os.WriteFile(paths.Key(), []byte("half-installed new key"), 0o600)

	// `enroll` without a token restores nothing either.
	cfg.Token = ""
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, &runnertest.Fake{}); err == nil || paths.Enrolled() {
		t.Fatalf("enroll without a token must not restore: %v", err)
	}

	cfg.Token = "one-time"
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	st, err := enroll.LoadState(paths)
	if err != nil || st.AgentID != fleetAgentIDs[1] || fleet.enrolls != 2 {
		t.Fatalf("want the token's new identity, got %+v %v (%d enrollments)", st, err, fleet.enrolls)
	}
	if _, err := os.Stat(filepath.Join(backup, "agent.json")); err != nil {
		t.Fatal("the backup must stay in place")
	}
	if _, err := os.Stat(filepath.Join(backup, ".incomplete")); err == nil {
		t.Fatal("the backup must be marked done")
	}
	// A later start keeps the new identity.
	if id, err := ensureEnrolled(ctx, cfg, quiet(), true); err != nil || id.State.AgentID != fleetAgentIDs[1] {
		t.Fatalf("%v %+v", err, id)
	}
}

func TestUnfinishedReplacementIsRestoredOnStart(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	ctx := context.Background()
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	paths := enroll.Paths{Dir: cfg.EtcDir}
	cert := read(t, paths.Cert())
	os.WriteFile(filepath.Join(cfg.EtcDir, "telemetry.json"), []byte(`{}`), 0o600)

	// Crash while the new files were moving in: the old identity is in the backup, one new file is in place.
	backup := filepath.Join(cfg.EtcDir, PreviousDir, "20261002T120000Z")
	os.MkdirAll(backup, 0o700)
	for _, f := range append(paths.Files(), filepath.Join(cfg.EtcDir, "telemetry.json")) {
		os.Rename(f, filepath.Join(backup, filepath.Base(f)))
	}
	os.WriteFile(filepath.Join(backup, ".incomplete"), nil, 0o600)
	os.WriteFile(paths.Key(), []byte("half-installed new key"), 0o600)
	// An older finished backup must not be picked.
	os.MkdirAll(filepath.Join(cfg.EtcDir, PreviousDir, "20250101T000000Z"), 0o700)

	var logs bytes.Buffer
	cfg.Token = "" // `falak-agent run` without a token
	id, err := ensureEnrolled(ctx, cfg, slog.New(slog.NewTextHandler(&logs, nil)), true)
	if err != nil || id.State.AgentID != fleetAgentIDs[0] || read(t, paths.Cert()) != cert {
		t.Fatalf("%v %+v", err, id)
	}
	if _, err := os.Stat(filepath.Join(cfg.EtcDir, "telemetry.json")); err != nil {
		t.Fatal("telemetry.json not restored")
	}
	if _, err := os.Stat(filepath.Join(backup, ".incomplete")); err == nil || !strings.Contains(logs.String(), "restored the previous agent identity") {
		t.Fatalf("marker left or nothing logged: %s", logs.String())
	}

	// Crash while the old files were moving out: some are still here, the rest in the backup.
	os.Rename(paths.Cert(), filepath.Join(backup, "agent.crt"))
	os.Rename(paths.State(), filepath.Join(backup, "agent.json"))
	os.WriteFile(filepath.Join(backup, ".incomplete"), nil, 0o600)
	if !restoreIncomplete(cfg, quiet()) || !paths.Enrolled() || read(t, paths.Cert()) != cert {
		t.Fatal("partial move-out not restored")
	}

	// A successful replacement leaves no marker.
	cfg.Token = "one-time"
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	if m, _ := filepath.Glob(filepath.Join(cfg.EtcDir, PreviousDir, "*", ".incomplete")); len(m) != 0 {
		t.Fatalf("markers left: %v", m)
	}
}

func identityConfig(t *testing.T, fleet *fakeFleet) config.Config {
	t.Helper()
	root := t.TempDir()
	cfg := config.Default()
	cfg.PanelURL, cfg.Token, cfg.Insecure = fleet.srv.URL, "one-time", true
	cfg.HostRoot = root
	cfg.EtcDir = filepath.Join(root, "etc/falak")
	return cfg
}

func quiet() *slog.Logger { return slog.New(slog.NewTextHandler(io.Discard, nil)) }

func read(t *testing.T, p string) string {
	t.Helper()
	b, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestEnrollReplacesAnExistingIdentity(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	ctx := context.Background()
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	paths := enroll.Paths{Dir: cfg.EtcDir}
	oldCert := read(t, paths.Cert())
	// State that belongs to the old agent, and state that belongs to the machine.
	state := filepath.Join(cfg.HostRoot, cfg.StateDir)
	os.MkdirAll(filepath.Join(state, "otlp-buffer"), 0o755)
	os.WriteFile(filepath.Join(state, "otlp-buffer", "1.pb"), []byte("x"), 0o600)
	os.WriteFile(filepath.Join(state, "commands.json"), []byte(`{"old":1}`), 0o600)
	os.WriteFile(filepath.Join(state, "proc.json"), []byte(`{}`), 0o600)
	os.WriteFile(filepath.Join(cfg.EtcDir, "telemetry.json"), []byte(`{"sites":[]}`), 0o600)
	os.WriteFile(filepath.Join(cfg.EtcDir, "agent.env"), []byte("FALAK_TOKEN=used\n"), 0o600)

	// `falak-agent run` never re-enrolls on its own.
	if _, err := ensureEnrolled(ctx, cfg, quiet(), true); err != nil || fleet.enrolls != 1 {
		t.Fatalf("run re-enrolled: %v (%d enrollments)", err, fleet.enrolls)
	}

	var out bytes.Buffer
	if err := EnrollOnly(ctx, cfg, quiet(), &out, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	if fleet.enrolls != 2 {
		t.Fatalf("enrollments %d", fleet.enrolls)
	}
	st, err := enroll.LoadState(paths)
	if err != nil || st.AgentID != fleetAgentIDs[1] {
		t.Fatalf("new identity not in place: %+v %v", st, err)
	}
	if _, err := enroll.Load(paths); err != nil {
		t.Fatalf("new identity does not load: %v", err)
	}
	if !strings.Contains(out.String(), "agent "+fleetAgentIDs[0]) || !strings.Contains(out.String(), filepath.Join(cfg.EtcDir, "previous")) {
		t.Fatalf("output %q", out.String())
	}
	backups, _ := filepath.Glob(filepath.Join(cfg.EtcDir, "previous", "*"))
	if len(backups) != 1 {
		t.Fatalf("backups %v", backups)
	}
	for _, dir := range []string{filepath.Join(cfg.EtcDir, "previous"), backups[0]} {
		if fi, _ := os.Stat(dir); fi.Mode().Perm() != 0o700 {
			t.Fatalf("%s mode %v", dir, fi.Mode().Perm())
		}
	}
	if read(t, filepath.Join(backups[0], "agent.crt")) != oldCert || !strings.Contains(read(t, filepath.Join(backups[0], "agent.json")), fleetAgentIDs[0]) {
		t.Fatal("old identity not backed up")
	}
	for _, f := range []string{"agent.key", "ca.crt", "telemetry.json", "commands.json"} {
		if _, err := os.Stat(filepath.Join(backups[0], f)); err != nil {
			t.Fatalf("%s not backed up: %v", f, err)
		}
	}
	for _, gone := range []string{filepath.Join(cfg.EtcDir, "telemetry.json"), filepath.Join(state, "commands.json"), filepath.Join(state, "otlp-buffer")} {
		if _, err := os.Stat(gone); err == nil {
			t.Fatalf("%s should be cleared", gone)
		}
	}
	for _, kept := range []string{filepath.Join(state, "proc.json"), filepath.Join(cfg.EtcDir, "agent.env")} {
		if _, err := os.Stat(kept); err != nil {
			t.Fatalf("%s should be kept: %v", kept, err)
		}
	}
	if leftovers, _ := filepath.Glob(filepath.Join(cfg.EtcDir, ".enroll-*")); len(leftovers) != 0 {
		t.Fatalf("temp dirs left: %v", leftovers)
	}
}

func TestFailedReenrollKeepsTheOldIdentity(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	if err := EnrollOnly(context.Background(), cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	paths := enroll.Paths{Dir: cfg.EtcDir}
	before := map[string]string{}
	for _, f := range paths.Files() {
		before[f] = read(t, f)
	}
	cfg.Token = "already-used"
	if err := EnrollOnly(context.Background(), cfg, quiet(), io.Discard, &runnertest.Fake{}); err == nil {
		t.Fatal("expected the enrollment error")
	}
	for f, b := range before {
		if read(t, f) != b {
			t.Fatalf("%s changed", f)
		}
	}
	if _, err := os.Stat(filepath.Join(cfg.EtcDir, "previous")); err == nil {
		t.Fatal("no backup on failure")
	}
	if leftovers, _ := filepath.Glob(filepath.Join(cfg.EtcDir, ".enroll-*")); len(leftovers) != 0 {
		t.Fatalf("temp dirs left: %v", leftovers)
	}
}

func TestEnrollWithoutTokenKeepsTheIdentity(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	if err := EnrollOnly(context.Background(), cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	cfg.Token = ""
	if err := EnrollOnly(context.Background(), cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil || fleet.enrolls != 1 {
		t.Fatalf("%v, %d enrollments", err, fleet.enrolls)
	}
}

func TestCheck(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	ctx := context.Background()

	if err := Check(ctx, CheckOptions{Config: cfg}); err == nil || !strings.Contains(err.Error(), "no agent identity") {
		t.Fatalf("missing identity: %v", err)
	}
	if err := EnrollOnly(ctx, cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	if err := Check(ctx, CheckOptions{Config: cfg, Out: &out}); err != nil || out.String() != "falak-agent connected as "+fleetAgentIDs[0]+"\n" {
		t.Fatalf("%q %v", out.String(), err)
	}

	// Clock: before the certificate's validity, and off the control plane's time.
	err := Check(ctx, CheckOptions{Config: cfg, Now: func() time.Time { return time.Now().Add(-24 * time.Hour) }})
	if err == nil || !strings.HasPrefix(err.Error(), "clock:") {
		t.Fatalf("clock behind: %v", err)
	}
	fleet.mu.Lock()
	fleet.now = time.Now().Add(10 * time.Minute)
	fleet.mu.Unlock()
	if err := Check(ctx, CheckOptions{Config: cfg}); err == nil || !strings.Contains(err.Error(), "clock is 10m0s off") {
		t.Fatalf("skew: %v", err)
	}
	fleet.mu.Lock()
	fleet.now = time.Time{}
	fleet.mu.Unlock()

	// Revoked: fails at once, even with --wait.
	id, err := enroll.Load(enroll.Paths{Dir: cfg.EtcDir})
	if err != nil {
		t.Fatal(err)
	}
	fp := id.Fingerprint()
	fleet.mu.Lock()
	fleet.revoked[fp] = true
	fleet.mu.Unlock()
	start := time.Now()
	err = Check(ctx, CheckOptions{Config: cfg, Wait: time.Minute})
	if err == nil || !strings.HasPrefix(err.Error(), "revoked: this agent was revoked or its server was removed from Falak (agent "+fleetAgentIDs[0]+")") || time.Since(start) > 10*time.Second {
		t.Fatalf("revoked: %v", err)
	}

	// Unreachable: retried until --wait runs out.
	fleet.srv.Close()
	start = time.Now()
	err = Check(ctx, CheckOptions{Config: cfg, Wait: 300 * time.Millisecond, Retry: 100 * time.Millisecond})
	if err == nil || !strings.Contains(err.Error(), "cannot reach the agents host") {
		t.Fatalf("unreachable: %v", err)
	}
}
