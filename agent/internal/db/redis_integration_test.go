package db

import (
	"bytes"
	"context"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
)

// Opt-in: KILN_REDIS_INTEGRATION=1 go test ./internal/db -run TestRedisIntegration -v
//
// Runs RedisApply / RedisRemove against real redis-server and valkey-server processes in Docker: the generated config
// must start each version, the renamed CONFIG must work for the agent and nobody else, and persistence switches (live
// and through restarts) must keep the data. systemctl maps to the container's lifecycle (a restart re-reads the
// mounted config file), redis-cli runs inside the container; useradd, daemon-reload and ss are no-ops.
var redisIntegrationImages = []struct{ engine, image string }{
	{"redis", "redis:6.0"},
	{"redis", "redis:7.0"},
	{"redis", "redis:8.0"},
	{"valkey", "valkey/valkey:7.2"},
	{"valkey", "valkey/valkey:8.1"},
	{"valkey", "valkey/valkey:9.0"},
}

type dockerRunner struct {
	t         *testing.T
	root      string
	image     string
	k         kvEngine
	name      string
	container string
	netns     string // run in this container's network namespace (its addresses outlive the instance's restarts)
}

func (d *dockerRunner) docker(ctx context.Context, stdin io.Reader, args ...string) (runner.Result, error) {
	cmd := exec.CommandContext(ctx, "docker", args...)
	var out, errb bytes.Buffer
	cmd.Stdin, cmd.Stdout, cmd.Stderr = stdin, &out, &errb
	err := cmd.Run()
	code := 0
	if ee, ok := err.(*exec.ExitError); ok {
		code, err = ee.ExitCode(), nil
	}
	return runner.Result{ExitCode: code, Stdout: out.Bytes(), Stderr: errb.Bytes()}, err
}

func (d *dockerRunner) Run(ctx context.Context, c runner.Cmd) (runner.Result, error) {
	switch {
	case c.Name == "systemctl" && c.Args[0] == "is-active":
		res, err := d.docker(ctx, nil, "inspect", "-f", "{{.State.Running}}", d.container)
		if err != nil || strings.TrimSpace(string(res.Stdout)) != "true" {
			return runner.Result{ExitCode: 3}, nil
		}
		return runner.Result{}, nil
	case c.Name == "systemctl" && c.Args[0] == "stop":
		// A short grace period: an instance that won't stop (Redis refuses while writing its first AOF) gets killed, as
		// on a host whose stop times out.
		return d.docker(ctx, nil, "stop", "-t", "3", d.container)
	case c.Name == "systemctl" && (c.Args[0] == "start" || c.Args[0] == "restart"):
		if res, _ := d.docker(ctx, nil, "inspect", d.container); res.ExitCode == 0 {
			return d.docker(ctx, nil, c.Args[0], d.container)
		}
		conf := d.k.confPath(d.name)
		args := []string{"run", "-d", "--name", d.container, "--user", "0:0", "--entrypoint", d.k.server}
		if d.netns != "" {
			args = append(args, "--network", "container:"+d.netns)
		}
		return d.docker(ctx, nil, append(args,
			"-v", filepath.Join(d.root, d.k.confDir)+":"+d.k.confDir,
			"-v", filepath.Join(d.root, d.k.dataPath(d.name))+":"+d.k.dataPath(d.name),
			d.image, conf)...)
	case c.Name == "systemctl" && c.Args[0] == "show" && slices.Contains(c.Args, "--property=ActiveState"):
		res, err := d.docker(ctx, nil, "inspect", "-f", "{{.State.Running}}", d.container)
		if err != nil || strings.TrimSpace(string(res.Stdout)) != "true" {
			return runner.Result{Stdout: []byte("failed\n")}, nil
		}
		return runner.Result{Stdout: []byte("active\n")}, nil
	case c.Name == "ss" && slices.Contains(c.Args, "-ltn"):
		return d.listening(ctx, c.Args[len(c.Args)-1])
	case c.Name == "systemctl" && c.Args[0] == "disable":
		return d.docker(ctx, nil, "rm", "-f", d.container)
	case c.Name == "journalctl":
		return d.docker(ctx, nil, "logs", "--tail", "15", d.container)
	case c.Name == d.k.cli:
		args := []string{"exec", "-i"}
		for _, e := range c.Env {
			args = append(args, "-e", e)
		}
		return d.docker(ctx, c.Stdin, append(append(args, d.container, d.k.cli), c.Args...)...)
	case strings.HasSuffix(c.Name, "/usr/bin/"+d.k.server):
		return d.docker(ctx, nil, "run", "--rm", "--entrypoint", d.k.server, d.image, "--version")
	case c.Name == "getent":
		return runner.Result{ExitCode: 2}, nil // the user is "created" every time: useradd is a no-op here
	}
	return runner.Result{}, nil // systemctl daemon-reload / enable / is-enabled / reset-failed, useradd, userdel, ss -p
}

// listening answers `ss -H -ltn sport = :<port>` from /proc/net/tcp{,6} of the instance's network namespace (the images
// have no ss): one "LISTEN 0 0 <addr>:<port> *:*" line per listening socket on the port.
func (d *dockerRunner) listening(ctx context.Context, filter string) (runner.Result, error) {
	port, err := strconv.Atoi(filter[strings.LastIndex(filter, ":")+1:]) // "sport = :6390"
	if err != nil {
		return runner.Result{ExitCode: 1}, nil
	}
	in := d.container
	if d.netns != "" {
		in = d.netns
	}
	res, _ := d.docker(ctx, nil, "exec", in, "cat", "/proc/net/tcp", "/proc/net/tcp6")
	var out strings.Builder
	for _, line := range strings.Split(string(res.Stdout), "\n") {
		f := strings.Fields(line)
		if len(f) < 4 || f[3] != "0A" { // 0A = LISTEN
			continue
		}
		hexAddr, hexPort, ok := strings.Cut(f[1], ":")
		p, err := strconv.ParseUint(hexPort, 16, 16)
		raw, err2 := hex.DecodeString(hexAddr)
		if !ok || err != nil || err2 != nil || int(p) != port || (len(raw) != 4 && len(raw) != 16) {
			continue
		}
		// The kernel prints each 32-bit word in host (little-endian) order.
		ip := make(net.IP, len(raw))
		for w := 0; w < len(raw); w += 4 {
			ip[w], ip[w+1], ip[w+2], ip[w+3] = raw[w+3], raw[w+2], raw[w+1], raw[w]
		}
		fmt.Fprintf(&out, "LISTEN 0 0 %s *:*\n", net.JoinHostPort(ip.String(), strconv.Itoa(port)))
	}
	return runner.Result{Stdout: []byte(out.String())}, nil
}

func TestRedisIntegration(t *testing.T) {
	if os.Getenv("KILN_REDIS_INTEGRATION") == "" {
		t.Skip("set KILN_REDIS_INTEGRATION=1 (needs Docker and the images in redisIntegrationImages)")
	}
	for _, img := range redisIntegrationImages {
		t.Run(img.image, func(t *testing.T) {
			k, _ := kvEngineFor(img.engine)
			root := t.TempDir()
			for _, unit := range []string{k.server + "@.service"} {
				p := filepath.Join(root, "/usr/lib/systemd/system", unit)
				os.MkdirAll(filepath.Dir(p), 0o755)
				os.WriteFile(p, []byte("[Service]\n"), 0o644)
			}
			d := &dockerRunner{t: t, root: root, image: img.image, k: k, name: "cache",
				container: fmt.Sprintf("kiln-redis-it-%s-%d", strings.NewReplacer("/", "-", ":", "-", ".", "-").Replace(img.image), time.Now().UnixNano())}
			defer d.docker(context.Background(), nil, "rm", "-f", d.container)
			db := New(Deps{Runner: d, FS: hostfs.FS{Root: root}, TempDir: t.TempDir()})
			ctx := context.Background()
			apply := func(p RedisApplyPayload) RedisApplyResult {
				t.Helper()
				r, err := db.RedisApply(ctx, p, st)
				if err != nil {
					t.Fatalf("apply %+v: %v", p, err)
				}
				return r.(RedisApplyResult)
			}
			p := RedisApplyPayload{Engine: img.engine, Name: "cache", Port: 6380, Password: "Xk3pQ9vR2mT7wL4nB8cF6hJ1", MaxMemoryMB: 64, Eviction: "noeviction", Persistence: "rdb"}
			c := func() conn {
				return conn{db: db, k: k, port: p.Port, password: p.Password, config: db.loadRedisState(k, "cache").ConfigName}
			}
			get := func(key string) string {
				t.Helper()
				out, err := c().do(ctx, "GET", key)
				if err != nil {
					t.Fatalf("GET %s: %v", key, err)
				}
				return out
			}
			set := func(key, value string) {
				t.Helper()
				if _, err := c().do(ctx, "SET", key, value); err != nil {
					t.Fatalf("SET: %v", err)
				}
			}

			if r := apply(p); !r.Restarted {
				t.Fatal(r)
			}
			set("a", "1")

			// Network access (db.redis.network): the container's own address stands in for docker0's. The bind change
			// restarts the instance (data kept); another container reaches it there with the password only.
			ipRes, err := d.docker(ctx, nil, "inspect", "-f", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", d.container)
			ip := strings.TrimSpace(string(ipRes.Stdout))
			if err != nil || ip == "" {
				t.Fatalf("container address: %q %v", ip, err)
			}
			fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("docker0", ip))
			p.Containers = true
			if r := apply(p); !r.Restarted || r.ContainerHost != ip || strings.Join(r.Bind, " ") != "127.0.0.1 "+ip {
				t.Fatalf("%+v", r)
			}
			if get("a") != "1" {
				t.Fatal("lost a after the bind change")
			}
			if out, err := c().do(ctx, c().config, "GET", "bind"); err != nil || !strings.Contains(out, ip) {
				t.Fatalf("bind: %q %v", out, err)
			}
			remote := func(auth bool) string {
				args := []string{"run", "--rm", "--entrypoint", k.cli}
				if auth {
					args = append(args, "-e", "REDISCLI_AUTH="+p.Password)
				}
				res, _ := d.docker(ctx, nil, append(args, img.image, "-h", ip, "-p", fmt.Sprint(p.Port), "--no-auth-warning", "GET", "a")...)
				return strings.TrimSpace(string(res.Stdout) + string(res.Stderr))
			}
			if out := remote(true); out != "1" {
				t.Fatalf("from another container: %q", out)
			}
			if out := remote(false); !strings.Contains(out, "NOAUTH") {
				t.Fatalf("without the password: %q", out)
			}
			// A public address is refused before anything changes.
			p.Bind = []string{"8.8.8.8"}
			if _, err := db.RedisApply(ctx, p, st); err == nil || !strings.Contains(err.Error(), "refusing") {
				t.Fatal(err)
			}
			// Back to loopback only (later restarts may give the container another address).
			p.Bind, p.Containers = nil, false
			if r := apply(p); !r.Restarted || get("a") != "1" {
				t.Fatalf("%+v", r)
			}

			// The renamed CONFIG works for the agent only; the disabled commands are gone.
			if out, err := c().do(ctx, c().config, "GET", "maxmemory"); err != nil || !strings.Contains(out, "67108864") {
				t.Fatalf("secret CONFIG GET: %q %v", out, err)
			}
			for _, cmd := range [][]string{{"CONFIG", "GET", "dir"}, {"DEBUG", "SLEEP", "0"}, {"ACL", "WHOAMI"}, {"REPLICAOF", "NO", "ONE"},
				{"SLAVEOF", "NO", "ONE"}, {"MODULE", "LIST"}, {"SLOWLOG", "GET"}, {"MIGRATE"}, {"COMMANDLOG", "GET", "10", "slow"}} {
				if _, err := c().do(ctx, cmd...); err == nil || !strings.Contains(err.Error(), "unknown command") {
					t.Fatalf("%s still works: %v", cmd[0], err)
				}
			}
			// The agent's commands never show up for clients (SLOWLOG / COMMANDLOG gone), and errors are redacted.
			if _, err := c().do(ctx, c().config, "SET", "no-such-option", "secret-value"); err == nil || strings.Contains(err.Error(), c().config) || strings.Contains(err.Error(), "secret-value") {
				t.Fatalf("error not redacted: %v", err)
			}

			// Live: memory, eviction, password; no restart, data still there.
			p.MaxMemoryMB, p.Eviction, p.Password = 96, "allkeys-lru", "Nn4vX8sD2kQ6pR9tY3wZ7aB5"
			if r := apply(p); r.Restarted || !r.Changed {
				t.Fatal(r)
			}
			if get("a") != "1" {
				t.Fatal("lost a after the live change")
			}
			if r := apply(p); r.Changed {
				t.Fatal("not idempotent")
			}

			// Persistence, live: rdb → aof → rdb → none → rdb, a key written at each step survives.
			for i, to := range []string{"aof", "rdb", "none", "rdb"} {
				p.Persistence = to
				if r := apply(p); r.Restarted {
					t.Fatalf("%s: restarted", to)
				}
				set(fmt.Sprintf("k%d", i), to)
				if get("a") != "1" {
					t.Fatalf("lost data switching to %s", to)
				}
			}

			// Through restarts (a new port): → aof (SAVE, start from dump.rdb, AOF on live), → rdb, → aof again.
			for i, to := range []string{"aof", "rdb", "aof"} {
				p.Port++
				p.Persistence = to
				if r := apply(p); !r.Restarted {
					t.Fatalf("%s: not restarted", to)
				}
				if get("a") != "1" || get("k3") != "rdb" {
					t.Fatalf("lost data restarting into %s (step %d)", to, i)
				}
				set(fmt.Sprintf("r%d", i), to)
			}
			if m, err := c().info(ctx, "persistence"); err != nil || m["aof_enabled"] != "1" {
				t.Fatalf("AOF not on: %v %v", m, err)
			}
			// A plain restart (as after a reboot) loads the AOF with everything.
			if _, err := d.docker(ctx, nil, "restart", d.container); err != nil {
				t.Fatal(err)
			}
			if err := c().ready(ctx); err != nil {
				t.Fatal(err)
			}
			if get("r2") != "aof" || get("a") != "1" {
				t.Fatal("lost data across a restart with AOF")
			}

			// A restart during the first AOF rewrite (H-A): the rewrite is throttled (rdb-key-save-delay) so the agent's wait
			// runs out — the apply fails without restarting — and the redelivery needs a restart (new port) while the
			// rewrite still runs. The data must come back from the snapshot, not an empty incomplete AOF.
			p.Persistence = "rdb"
			apply(p)
			if _, err := c().do(ctx, "EVAL", "for i=1,3000 do redis.call('SET','pop:'..i,i) end return 1", "0"); err != nil {
				t.Fatal(err)
			}
			if out, err := c().do(ctx, c().config, "GET", "rdb-key-save-delay"); err != nil || !strings.Contains(out, "rdb-key-save-delay") {
				t.Logf("%s has no rdb-key-save-delay (%q, %v): restart-mid-rewrite case skipped", img.image, out, err)
			} else {
				// 10 ms per key: the rewrite child (forked with this value) takes ~30 s, longer than the stop's grace period.
				if _, err := c().do(ctx, c().config, "SET", "rdb-key-save-delay", "10000"); err != nil {
					t.Fatal(err)
				}
				oldAOF := RedisAOFTimeout
				RedisAOFTimeout = 300 * time.Millisecond
				p.Persistence = "aof"
				_, err := db.RedisApply(ctx, p, st)
				RedisAOFTimeout = oldAOF
				if err == nil || !strings.Contains(err.Error(), "still running") {
					t.Fatalf("expected the wait to run out: %v", err)
				}
				if m, _ := c().info(ctx, "persistence"); m["aof_rewrite_in_progress"] != "1" {
					t.Fatalf("rewrite not running any more: %v", m)
				}
				// The parent is fast again (its SAVE before the restart too); the running rewrite child stays slow.
				if _, err := c().do(ctx, c().config, "SET", "rdb-key-save-delay", "0"); err != nil {
					t.Fatal(err)
				}
				p.Port++
				if r := apply(p); !r.Restarted {
					t.Fatal(r)
				}
				if n, _ := c().do(ctx, "DBSIZE"); get("pop:3000") != "3000" || get("a") != "1" {
					t.Fatalf("restart during the first AOF rewrite lost data (DBSIZE %s)", n)
				}
				if m, _ := c().info(ctx, "persistence"); m["aof_enabled"] != "1" {
					t.Fatalf("AOF not on after the restart: %v", m)
				}
				// And a plain restart now loads the finished AOF with everything.
				if _, err := d.docker(ctx, nil, "restart", d.container); err != nil {
					t.Fatal(err)
				}
				if err := c().ready(ctx); err != nil || get("pop:3000") != "3000" {
					t.Fatalf("lost data after the AOF restart: %v", err)
				}
			}

			// none through a restart: nothing comes back, even though the old process had save points or AOF.
			p.Port++
			p.Persistence = "none"
			if r := apply(p); !r.Restarted {
				t.Fatal(r)
			}
			if n, err := c().do(ctx, "DBSIZE"); err != nil || n != "0" {
				t.Fatalf("none after a restart has %s keys (%v)", n, err)
			}
			// And back to snapshots through a restart: written keys survive the next one.
			set("z", "1")
			p.Port++
			p.Persistence = "rdb"
			apply(p)
			if get("z") != "1" {
				t.Fatal("none → rdb through a restart lost the keys written in none")
			}

			if _, err := db.RedisRemove(ctx, RedisRemovePayload{Engine: img.engine, Name: "cache"}, st); err != nil {
				t.Fatal(err)
			}
			if exists(filepath.Join(root, k.dataPath("cache"))) {
				t.Fatal("data left")
			}

			t.Run("address appears after the start", func(t *testing.T) { redisIntegrationLateAddress(t, img.engine, img.image) })
		})
	}
}

// redisIntegrationLateAddress: an instance bound to a private address that is missing when it starts (as docker0 or a
// WireGuard address after a reboot) comes up on it once the address appears, data kept. The address lives in a holder
// container's network namespace (NET_ADMIN), which the instance's container joins and which outlives its restarts.
func redisIntegrationLateAddress(t *testing.T, engine, image string) {
	k, _ := kvEngineFor(engine)
	ctx := context.Background()
	root := t.TempDir()
	unitFile := filepath.Join(root, "/usr/lib/systemd/system", k.server+"@.service")
	os.MkdirAll(filepath.Dir(unitFile), 0o755)
	os.WriteFile(unitFile, []byte("[Service]\n"), 0o644)
	suffix := fmt.Sprintf("%s-%d", strings.NewReplacer("/", "-", ":", "-", ".", "-").Replace(image), time.Now().UnixNano())
	d := &dockerRunner{t: t, root: root, image: image, k: k, name: "late", container: "kiln-redis-it-late-" + suffix, netns: "kiln-redis-it-netns-" + suffix}
	defer d.docker(ctx, nil, "rm", "-f", d.container)
	defer d.docker(ctx, nil, "rm", "-f", d.netns)
	must := func(args ...string) {
		t.Helper()
		if res, err := d.docker(ctx, nil, args...); err != nil || res.ExitCode != 0 {
			t.Fatalf("docker %v: %s %v", args, res.Stderr, err)
		}
	}
	must("run", "-d", "--name", d.netns, "--cap-add", "NET_ADMIN", "alpine:3.22", "sleep", "900")
	const addr = "10.250.0.5"
	addrUp := func(up bool) {
		t.Helper()
		op := map[bool]string{true: "add", false: "del"}[up]
		must("exec", d.netns, "ip", "addr", op, addr+"/32", "dev", "eth0")
		if up {
			fakeInterfaces(t, iface("lo", "127.0.0.1"), iface("eth0", addr))
		} else {
			fakeInterfaces(t, iface("lo", "127.0.0.1"))
		}
	}
	db := New(Deps{Runner: d, FS: hostfs.FS{Root: root}, TempDir: t.TempDir()})
	p := RedisApplyPayload{Engine: engine, Name: "late", Port: 6390, Password: "Xk3pQ9vR2mT7wL4nB8cF6hJ1", MaxMemoryMB: 32, Eviction: "noeviction", Persistence: "rdb", Bind: []string{addr}}
	c := conn{db: db, k: k, port: p.Port, password: p.Password}

	addrUp(true)
	if r, err := db.RedisApply(ctx, p, st); err != nil || !r.(RedisApplyResult).Restarted {
		t.Fatalf("%+v %v", r, err)
	}
	c.config = db.loadRedisState(k, "late").ConfigName
	if _, err := c.do(ctx, "SET", "b", "1"); err != nil {
		t.Fatal(err)
	}

	// "Reboot": the address is gone when the instance starts again.
	addrUp(false)
	must("restart", "-t", "5", d.container)
	time.Sleep(2 * time.Second)
	running := db.activeState(ctx, k.unit("late")) == "active"
	t.Logf("%s without its address: running=%v", image, running) // Redis 6.0 starts without it, 6.2+ / Valkey refuse
	db.RedisCheck(ctx)
	if running {
		if missing, err := db.notListening(ctx, p.Port, []string{"127.0.0.1", addr}); err != nil || !slices.Equal(missing, []string{addr}) {
			t.Fatalf("listens on a missing address? missing %v (%v)", missing, err)
		}
	} else if db.activeState(ctx, k.unit("late")) == "active" {
		t.Fatal("started while its address is missing")
	}

	// The address appears: the next check brings the instance up on it.
	addrUp(true)
	db.RedisCheck(ctx)
	if err := c.ready(ctx); err != nil {
		t.Fatal(err)
	}
	if missing, err := db.notListening(ctx, p.Port, []string{"127.0.0.1", addr}); err != nil || len(missing) != 0 {
		t.Fatalf("not listening on %v (%v)", missing, err)
	}
	res, _ := d.docker(ctx, nil, "exec", "-e", "REDISCLI_AUTH="+p.Password, d.container, k.cli, "-h", addr, "-p", strconv.Itoa(p.Port), "--no-auth-warning", "GET", "b")
	if got := strings.TrimSpace(string(res.Stdout)); got != "1" {
		t.Fatalf("GET b over %s: %q %s", addr, got, res.Stderr)
	}
}
