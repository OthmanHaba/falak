package db

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// watchHost adds what RedisCheck asks systemd on top of newRedisHost: ActiveState (failed once a unit has no process)
// and is-enabled (every unit).
func watchHost(t *testing.T) (*runnertest.Fake, *DB, string, *fakeRedisHost) {
	t.Helper()
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	var h *fakeRedisHost
	f.OnFunc("systemctl show --property=ActiveState --value", func(c runnertest.Call) (runner.Result, error) {
		if h.procs[c.Args[len(c.Args)-1]] != nil {
			return runner.Result{Stdout: []byte("active\n")}, nil
		}
		return runner.Result{Stdout: []byte("failed\n")}, nil
	})
	h = newRedisHost(t, f, root)
	return f, db, root, h
}

func writeWireGuardConf(t *testing.T, root, iface, header string) {
	t.Helper()
	p := filepath.Join(root, "/etc/wireguard", iface+".conf")
	os.MkdirAll(filepath.Dir(p), 0o700)
	os.WriteFile(p, []byte(header+"\n[Interface]\n"), 0o600)
}

func TestRedisBootDropInOrdersAfterDockerAndFalakWireGuard(t *testing.T) {
	f, db, root, _ := watchHost(t)
	hostInterfaces(t, root, true)
	writeWireGuardConf(t, root, "wg-a1b2c3d4", "# Managed by Falak (net.wireguard.apply) — do not edit")
	writeWireGuardConf(t, root, "wg0", "# someone else's tunnel")
	p := redisPayload()
	applyOK(t, db, p)
	boot := func() string {
		b, _ := os.ReadFile(filepath.Join(root, "/etc/systemd/system/redis-server@falak-cache.service.d/40-falak-boot.conf"))
		return string(b)
	}
	for _, want := range []string{"[Unit]\nWants=network-online.target\nAfter=network-online.target docker.service wg-quick@wg-a1b2c3d4.service\nStartLimitIntervalSec=10min\nStartLimitBurst=150\n",
		"[Service]\nRestartSec=2s\n"} {
		if !strings.Contains(boot(), want) {
			t.Fatalf("boot drop-in misses %q:\n%s", want, boot())
		}
	}
	if strings.Contains(boot(), "wg0") {
		t.Fatal(boot())
	}
	main, _ := os.ReadFile(filepath.Join(root, "/etc/systemd/system/redis-server@falak-cache.service.d/50-falak.conf"))
	if strings.Contains(string(main), "After=") || strings.Contains(string(main), "RestartSec") {
		t.Fatalf("ordering belongs in the boot drop-in only:\n%s", main)
	}

	// A private network added later: the next apply rewrites the ordering and reloads systemd, without a restart.
	writeWireGuardConf(t, root, "wg-e5f6a7b8", "# Managed by Falak (net.wireguard.apply) — do not edit")
	f.Reset()
	if r := applyOK(t, db, p); r.Changed || r.Restarted {
		t.Fatalf("%+v %v", r, f.Lines())
	}
	if !strings.Contains(boot(), "After=network-online.target docker.service wg-quick@wg-a1b2c3d4.service wg-quick@wg-e5f6a7b8.service\n") ||
		!slices.Contains(f.Lines(), "systemctl daemon-reload") {
		t.Fatalf("%s %v", boot(), f.Lines())
	}
	// Unchanged: no reload.
	f.Reset()
	applyOK(t, db, p)
	if slices.Contains(f.Lines(), "systemctl daemon-reload") {
		t.Fatal(f.Lines())
	}
}

func TestRedisNotListeningParsesSS(t *testing.T) {
	f := &runnertest.Fake{}
	db, _ := newDB(t, f, nil)
	f.On("ss -H -ltn sport = :6380", runner.Result{Stdout: []byte(
		"LISTEN 0 511 127.0.0.1:6380 0.0.0.0:*\n" +
			"LISTEN 0 511 10.90.0.3%wg-falak:6380 0.0.0.0:*\n" +
			"LISTEN 0 511 [::1]:6380 [::]:*\n" +
			"LISTEN 0 511 10.0.1.5:63800 0.0.0.0:*\n")})
	missing, err := db.notListening(context.Background(), 6380, []string{"127.0.0.1", "10.90.0.3", "::1", "10.0.1.5", "172.17.0.1"})
	if err != nil || !slices.Equal(missing, []string{"10.0.1.5", "172.17.0.1"}) {
		t.Fatalf("%v %v", missing, err)
	}
	f.On("ss -H -ltn sport = :6381", runner.Result{Stdout: []byte("LISTEN 0 511 *:6381 *:*\n")})
	if missing, err := db.notListening(context.Background(), 6381, []string{"10.0.1.5"}); err != nil || len(missing) != 0 {
		t.Fatalf("a wildcard listener covers every address: %v %v", missing, err)
	}
	f.On("ss -H -ltn sport = :6382", runner.Result{ExitCode: 1})
	if _, err := db.notListening(context.Background(), 6382, []string{"10.0.1.5"}); err == nil {
		t.Fatal("ss failing is an error")
	}
}

// Redis 6.0 started before its WireGuard address existed: it runs on 127.0.0.1 only. An apply restarts it (data kept)
// instead of taking it as live.
func TestRedisApplyRestartsAnInstanceMissingAnAddress(t *testing.T) {
	_, db, root, h := watchHost(t)
	hostInterfaces(t, root, true)
	p := redisPayload()
	p.Bind = []string{"10.90.0.3"}
	applyOK(t, db, p)
	proc := func() *fakeProc { return h.procs["redis-server@falak-cache.service"] }
	proc().bind = []string{"127.0.0.1"}
	h.redisCmds = nil
	if r := applyOK(t, db, p); !r.Restarted {
		t.Fatalf("%+v", r)
	}
	inOrder(t, h.redisCmds, "SAVE", "PING")
	if !slices.Equal(proc().bind, []string{"127.0.0.1", "10.90.0.3"}) || proc().loadedFrom != "dump.rdb" {
		t.Fatalf("%+v", proc())
	}
	if r := applyOK(t, db, p); r.Changed || r.Restarted {
		t.Fatalf("not idempotent: %+v", r)
	}
}

// fakeClock replaces redisNow for one test; advance moves it.
func fakeClock(t *testing.T) (advance func(time.Duration)) {
	t.Helper()
	now := time.Date(2026, 10, 5, 12, 0, 0, 0, time.UTC)
	var mu sync.Mutex
	old := redisNow
	redisNow = func() time.Time { mu.Lock(); defer mu.Unlock(); return now }
	t.Cleanup(func() { redisNow = old })
	return func(d time.Duration) { mu.Lock(); now = now.Add(d); mu.Unlock() }
}

func TestRedisCheckRestartsAnInstanceOnceItsAddressExists(t *testing.T) {
	f, db, root, h := watchHost(t)
	hostInterfaces(t, root, true)
	advance := fakeClock(t)
	p := redisPayload()
	p.Bind, p.Containers = []string{"10.90.0.3"}, true
	applyOK(t, db, p)
	unit := "redis-server@falak-cache.service"
	proc := func() *fakeProc { return h.procs[unit] }
	starts := func() int {
		n := 0
		for _, l := range f.Lines() {
			if l == "systemctl start "+unit {
				n++
			}
		}
		return n
	}
	check := func() int {
		f.Reset()
		db.RedisCheck(context.Background())
		return starts()
	}

	// All listening: nothing to do.
	if check() != 0 || slices.Contains(f.Lines(), "systemctl stop "+unit) {
		t.Fatal(f.Lines())
	}

	// After a reboot docker0 came up late and Redis 6.0 started without it. While docker0 is still missing nothing can
	// be done; once it is there the instance is restarted, its data kept.
	fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("wg-falak", "10.90.0.3"))
	proc().bind = []string{"127.0.0.1", "10.90.0.3"}
	if check() != 0 {
		t.Fatal(f.Lines())
	}
	hostInterfaces(t, root, true)
	h.redisCmds = nil
	if check() != 1 || !slices.Equal(proc().bind, []string{"127.0.0.1", "10.90.0.3", "172.17.0.1"}) || proc().loadedFrom != "dump.rdb" {
		t.Fatalf("%v %+v", f.Lines(), proc())
	}
	inOrder(t, h.redisCmds, "SAVE", "PING")

	// Healthy again: the backoff starts over, so the next problem is handled at once.
	if check() != 0 {
		t.Fatal(f.Lines())
	}
	proc().bind = []string{"127.0.0.1"}
	if check() != 1 {
		t.Fatal("a new problem after a healthy pass waits")
	}

	// A restart that did not help backs off: 1, 2, 5, then every 10 minutes. Other interfaces coming and going
	// (veths, bridges) don't matter.
	stubborn := func() { proc().bind = []string{"127.0.0.1"} }
	stubborn()
	if check() != 0 {
		t.Fatal("restarted again at once")
	}
	fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("wg-falak", "10.90.0.3"), iface("docker0", "172.17.0.1"), iface("veth1a2b", "169.254.1.1"))
	if check() != 0 {
		t.Fatal("a new veth is no reason to restart")
	}
	for _, wait := range []time.Duration{time.Minute, 2 * time.Minute, 5 * time.Minute, 10 * time.Minute, 10 * time.Minute} {
		advance(wait - time.Second)
		if check() != 0 {
			t.Fatalf("restarted before %s", wait)
		}
		advance(time.Second)
		if check() != 1 {
			t.Fatalf("not restarted after %s", wait)
		}
		stubborn()
	}

	// An address gone and back: tried at once.
	fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("docker0", "172.17.0.1"))
	check()
	hostInterfaces(t, root, true)
	if check() != 1 {
		t.Fatal("an address back is not tried at once")
	}

	// Not reachable for the snapshot (nothing stopped): not an attempt, the next pass tries again.
	stubborn()
	db.resetAttempts(unit)
	pw := proc().password
	proc().password = "something-else-entirely"
	if check() != 0 || slices.Contains(f.Lines(), "systemctl stop "+unit) {
		t.Fatal(f.Lines())
	}
	proc().password = pw
	if check() != 1 {
		t.Fatal("a failure before the stop counted as an attempt")
	}

	// Redis 6.2+ gave up starting (systemd's start limit): started once its addresses exist, backing off when it keeps failing.
	delete(h.procs, unit)
	db.resetAttempts(unit)
	if check() != 1 || proc() == nil || !slices.Contains(f.Lines(), "systemctl reset-failed "+unit) {
		t.Fatal(f.Lines())
	}
	delete(h.procs, unit)
	if check() != 0 {
		t.Fatal("a unit that keeps failing is started again at once")
	}
	advance(time.Minute)
	if check() != 1 {
		t.Fatal("not started again after a minute")
	}
}

// The watch never brings back a drop-in directory a remove deleted (its config still there, say).
func TestRedisCheckSkipsAnInstanceWithoutItsDropIn(t *testing.T) {
	f, db, root, _ := watchHost(t)
	hostInterfaces(t, root, true)
	applyOK(t, db, redisPayload())
	dir := filepath.Join(root, "/etc/systemd/system/redis-server@falak-cache.service.d")
	os.RemoveAll(dir)
	f.Reset()
	db.RedisCheck(context.Background())
	if exists(dir) || len(f.Lines()) != 0 {
		t.Fatal(f.Lines())
	}
}

func TestRedisCheckLeavesBusyAndHalfAppliedInstancesAlone(t *testing.T) {
	f, db, root, h := watchHost(t)
	hostInterfaces(t, root, true)
	p := redisPayload()
	p.Bind = []string{"10.90.0.3"}
	applyOK(t, db, p)
	unit := "redis-server@falak-cache.service"
	h.procs[unit].bind = []string{"127.0.0.1"}
	k, _ := kvEngineFor("redis")

	// An apply holds the instance: skipped.
	unlock, err := lockInstance(context.Background(), k, "cache")
	if err != nil {
		t.Fatal(err)
	}
	f.Reset()
	db.RedisCheck(context.Background())
	unlock()
	if len(f.Lines()) != 0 {
		t.Fatal(f.Lines())
	}

	// The running process doesn't use the file on disk (an apply stopped half way): its redelivery restarts it.
	conf := filepath.Join(root, "/etc/falak-redis/cache.conf")
	b, _ := os.ReadFile(conf)
	os.WriteFile(conf, append(b, []byte("# changed\n")...), 0o640)
	f.Reset()
	db.RedisCheck(context.Background())
	if slices.Contains(f.Lines(), "systemctl stop "+unit) {
		t.Fatal(f.Lines())
	}
}
