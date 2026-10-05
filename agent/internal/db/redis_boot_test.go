package db

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
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

func TestRedisBootDropInOrdersAfterDockerAndKilnWireGuard(t *testing.T) {
	f, db, root, _ := watchHost(t)
	hostInterfaces(t, root, true)
	writeWireGuardConf(t, root, "wg-a1b2c3d4", "# Managed by Kiln (net.wireguard.apply) — do not edit")
	writeWireGuardConf(t, root, "wg0", "# someone else's tunnel")
	p := redisPayload()
	applyOK(t, db, p)
	boot := func() string {
		b, _ := os.ReadFile(filepath.Join(root, "/etc/systemd/system/redis-server@kiln-cache.service.d/40-kiln-boot.conf"))
		return string(b)
	}
	for _, want := range []string{"[Unit]\nWants=network-online.target\nAfter=network-online.target docker.service wg-quick@wg-a1b2c3d4.service\nStartLimitIntervalSec=0\n",
		"[Service]\nRestartSec=2s\n"} {
		if !strings.Contains(boot(), want) {
			t.Fatalf("boot drop-in misses %q:\n%s", want, boot())
		}
	}
	if strings.Contains(boot(), "wg0") {
		t.Fatal(boot())
	}
	main, _ := os.ReadFile(filepath.Join(root, "/etc/systemd/system/redis-server@kiln-cache.service.d/50-kiln.conf"))
	if strings.Contains(string(main), "After=") || strings.Contains(string(main), "RestartSec") {
		t.Fatalf("ordering belongs in the boot drop-in only:\n%s", main)
	}

	// A private network added later: the next apply rewrites the ordering and reloads systemd, without a restart.
	writeWireGuardConf(t, root, "wg-e5f6a7b8", "# Managed by Kiln (net.wireguard.apply) — do not edit")
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
			"LISTEN 0 511 10.90.0.3%wg-kiln:6380 0.0.0.0:*\n" +
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
	proc := func() *fakeProc { return h.procs["redis-server@kiln-cache.service"] }
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

func TestRedisCheckRestartsAnInstanceOnceItsAddressExists(t *testing.T) {
	f, db, root, h := watchHost(t)
	hostInterfaces(t, root, true)
	p := redisPayload()
	p.Bind, p.Containers = []string{"10.90.0.3"}, true
	applyOK(t, db, p)
	unit := "redis-server@kiln-cache.service"
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

	// All listening: nothing to do.
	f.Reset()
	db.RedisCheck(context.Background())
	if starts() != 0 || slices.Contains(f.Lines(), "systemctl stop "+unit) {
		t.Fatal(f.Lines())
	}

	// After a reboot docker0 came up late and Redis 6.0 started without it. While docker0 is still missing nothing can
	// be done; once it is there the instance is restarted, its data kept.
	fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("wg-kiln", "10.90.0.3"))
	proc().bind = []string{"127.0.0.1", "10.90.0.3"}
	f.Reset()
	db.RedisCheck(context.Background())
	if starts() != 0 {
		t.Fatal(f.Lines())
	}
	hostInterfaces(t, root, true)
	h.redisCmds = nil
	f.Reset()
	db.RedisCheck(context.Background())
	if starts() != 1 || !slices.Equal(proc().bind, []string{"127.0.0.1", "10.90.0.3", "172.17.0.1"}) || proc().loadedFrom != "dump.rdb" {
		t.Fatalf("%v %+v", f.Lines(), proc())
	}
	inOrder(t, h.redisCmds, "SAVE", "PING")

	// A restart that did not help is not repeated for the same host addresses.
	proc().bind = []string{"127.0.0.1"}
	f.Reset()
	db.RedisCheck(context.Background())
	if starts() != 0 {
		t.Fatal("restarted again for the same addresses")
	}

	// Redis 6.2+ gave up starting (old drop-in, or systemd's limit): started once its addresses exist.
	delete(h.procs, unit)
	fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("wg-kiln", "10.90.0.3"), iface("docker0", "172.17.0.1"), iface("ens10", "10.0.1.5"))
	f.Reset()
	db.RedisCheck(context.Background())
	if starts() != 1 || proc() == nil || !slices.Contains(f.Lines(), "systemctl reset-failed "+unit) {
		t.Fatal(f.Lines())
	}
	delete(h.procs, unit)
	f.Reset()
	db.RedisCheck(context.Background())
	if starts() != 0 {
		t.Fatal("a unit that keeps failing is started once per set of addresses")
	}
}

func TestRedisCheckLeavesBusyAndHalfAppliedInstancesAlone(t *testing.T) {
	f, db, root, h := watchHost(t)
	hostInterfaces(t, root, true)
	p := redisPayload()
	p.Bind = []string{"10.90.0.3"}
	applyOK(t, db, p)
	unit := "redis-server@kiln-cache.service"
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
	conf := filepath.Join(root, "/etc/kiln-redis/cache.conf")
	b, _ := os.ReadFile(conf)
	os.WriteFile(conf, append(b, []byte("# changed\n")...), 0o640)
	f.Reset()
	db.RedisCheck(context.Background())
	if slices.Contains(f.Lines(), "systemctl stop "+unit) {
		t.Fatal(f.Lines())
	}
}
