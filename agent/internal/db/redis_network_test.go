package db

import (
	"net"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/facts"
	"github.com/kiln/agent/internal/runner/runnertest"
)

// fakeInterfaces replaces the host's interfaces for one test.
func fakeInterfaces(t *testing.T, ifs ...facts.Interface) {
	t.Helper()
	old := redisInterfaces
	redisInterfaces = func() ([]facts.Interface, error) { return ifs, nil }
	t.Cleanup(func() { redisInterfaces = old })
}

func iface(name string, addrs ...string) facts.Interface {
	it := facts.Interface{Name: name}
	for _, a := range addrs {
		it.Addrs = append(it.Addrs, net.ParseIP(a))
	}
	return it
}

func hostInterfaces(t *testing.T, root string, docker bool) {
	t.Helper()
	ifs := []facts.Interface{
		iface("lo", "127.0.0.1", "::1"),
		iface("eth0", "203.0.113.5", "2001:db8::5"),
		iface("ens10", "10.0.1.5", "fd00:1::5"),
		iface("wg-kiln", "10.90.0.3"),
		// A private network whose range is not a private one: only its WireGuard type makes it acceptable.
		iface("wg-pub", "198.51.100.7"),
		iface("tun0", "198.51.100.9"),
	}
	if docker {
		ifs = append(ifs, iface("docker0", "172.17.0.1"), iface("br-1a2b", "172.18.0.1"))
	}
	fakeInterfaces(t, ifs...)
	for name, uevent := range map[string]string{"wg-pub": "DEVTYPE=wireguard\nINTERFACE=wg-pub\n", "tun0": "DEVTYPE=tun\nINTERFACE=tun0\n", "eth0": "INTERFACE=eth0\n"} {
		p := filepath.Join(root, "/sys/class/net", name, "uevent")
		os.MkdirAll(filepath.Dir(p), 0o755)
		os.WriteFile(p, []byte(uevent), 0o644)
	}
}

func TestRedisBindAcceptsOnlyPrivateAndWireGuardAddresses(t *testing.T) {
	db, root := newDB(t, &runnertest.Fake{}, nil)
	hostInterfaces(t, root, true)

	got, err := db.resolveBind([]string{"10.0.1.5", "10.90.0.3", "198.51.100.7", "fd00:1::5", "127.0.0.1", "10.0.1.5"}, false)
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"127.0.0.1", "10.0.1.5", "10.90.0.3", "198.51.100.7", "fd00:1::5"}; !slices.Equal(got.addrs, want) || got.containerHost != "" || len(got.skipped) != 0 {
		t.Fatalf("%+v", got)
	}

	// Public addresses (on a real interface, on a tunnel that is not WireGuard, or not on the host at all), the
	// wildcard and link-local are refused: the command fails without touching the instance.
	for _, bad := range []string{"203.0.113.5", "2001:db8::5", "198.51.100.9", "8.8.8.8", "0.0.0.0", "::", "169.254.1.1", "fe80::1", "nope", ""} {
		if _, err := db.resolveBind([]string{bad}, false); !commands.IsPayloadError(err) {
			t.Fatalf("%q accepted: %v", bad, err)
		}
	}

	// Valid addresses the host doesn't have yet (a private network not converged) are left out and reported.
	got, err = db.resolveBind([]string{"10.90.0.9", "100.64.3.4", "192.168.7.7", "::1"}, false)
	if err != nil || !slices.Equal(got.addrs, []string{"127.0.0.1", "::1"}) || !slices.Equal(got.skipped, []string{"10.90.0.9", "100.64.3.4", "192.168.7.7"}) {
		t.Fatalf("%+v %v", got, err)
	}
}

func TestRedisBindContainersUseDocker0(t *testing.T) {
	db, root := newDB(t, &runnertest.Fake{}, nil)
	hostInterfaces(t, root, true)
	got, err := db.resolveBind([]string{"10.90.0.3"}, true)
	if err != nil || !slices.Equal(got.addrs, []string{"127.0.0.1", "10.90.0.3", "172.17.0.1"}) || got.containerHost != "172.17.0.1" {
		t.Fatalf("%+v %v", got, err)
	}

	// No Docker: nothing to add, nothing reported as the container host.
	hostInterfaces(t, root, false)
	got, err = db.resolveBind(nil, true)
	if err != nil || !slices.Equal(got.addrs, []string{"127.0.0.1"}) || got.containerHost != "" {
		t.Fatalf("%+v %v", got, err)
	}

	// A docker0 with a public address (a misconfigured bip) is never listened on.
	fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("docker0", "203.0.113.1"))
	got, err = db.resolveBind(nil, true)
	if err != nil || !slices.Equal(got.addrs, []string{"127.0.0.1"}) || got.containerHost != "" {
		t.Fatalf("%+v %v", got, err)
	}
}

func TestRedisWireGuardByNameWithoutSysfs(t *testing.T) {
	db, _ := newDB(t, &runnertest.Fake{}, nil)
	fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("wg-x", "198.51.100.7"), iface("eth0", "203.0.113.5"))
	if _, err := db.resolveBind([]string{"198.51.100.7"}, false); err != nil {
		t.Fatal(err)
	}
	if _, err := db.resolveBind([]string{"203.0.113.5"}, false); !commands.IsPayloadError(err) {
		t.Fatal(err)
	}
}

// A new bind (containers, then a private address, then one gone) restarts the instance the phase-1 way: snapshot
// first, data dir kept, the restarted process loads dump.rdb. The same bind again is a no-op.
func TestRedisBindChangesRestartAndKeepTheData(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	hostInterfaces(t, root, true)
	p := redisPayload()
	p.Bind = []string{"127.0.0.1"}
	if r := applyOK(t, db, p); !slices.Equal(r.Bind, []string{"127.0.0.1"}) || r.ContainerHost != "" {
		t.Fatalf("%+v", r)
	}
	proc := func() *fakeProc { return h.procs["redis-server@kiln-cache.service"] }
	conf := func() string {
		b, _ := os.ReadFile(filepath.Join(root, "/etc/kiln-redis/cache.conf"))
		return string(b)
	}
	dump := filepath.Join(root, "/var/lib/kiln-redis/cache/dump.rdb")

	h.redisCmds = nil
	p.Containers = true
	r := applyOK(t, db, p)
	if !r.Restarted || r.ContainerHost != "172.17.0.1" || !slices.Equal(r.Bind, []string{"127.0.0.1", "172.17.0.1"}) {
		t.Fatalf("%+v", r)
	}
	if !strings.Contains(conf(), "bind 127.0.0.1 172.17.0.1\n") || !strings.Contains(conf(), "protected-mode yes\n") || !strings.Contains(conf(), "requirepass ") {
		t.Fatal(conf())
	}
	inOrder(t, h.redisCmds, "SAVE", "PING")
	if b, _ := os.ReadFile(dump); string(b) != "REDIS0009 at stop" || proc().loadedFrom != "dump.rdb" {
		t.Fatalf("data not kept: %q %+v", b, proc())
	}
	if st := readState(t, db, "redis", "cache"); !slices.Equal(st.Bind, []string{"127.0.0.1", "172.17.0.1"}) {
		t.Fatalf("%+v", st)
	}

	f.Reset()
	if r := applyOK(t, db, p); r.Changed || r.Restarted || r.ContainerHost != "172.17.0.1" {
		t.Fatalf("not idempotent: %+v %v", r, f.Lines())
	}

	// A private network address joins: restart again; one the host lost is skipped (and the instance still starts).
	p.Bind = []string{"127.0.0.1", "10.90.0.3", "10.0.9.9"}
	r = applyOK(t, db, p)
	if !r.Restarted || !slices.Equal(r.Bind, []string{"127.0.0.1", "10.90.0.3", "172.17.0.1"}) || !slices.Equal(r.Skipped, []string{"10.0.9.9"}) || proc().loadedFrom != "dump.rdb" {
		t.Fatalf("%+v %+v", r, proc())
	}
	if !strings.Contains(conf(), "bind 127.0.0.1 10.90.0.3 172.17.0.1\n") {
		t.Fatal(conf())
	}

	// A public address fails the command before anything changes.
	before := conf()
	p.Bind = []string{"203.0.113.5"}
	if _, err := db.RedisApply(t.Context(), p, st); !commands.IsPayloadError(err) || conf() != before || proc() == nil {
		t.Fatalf("%v", err)
	}
}
