package db

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

// redisHost fakes systemd + redis-cli: a unit is active once started, PING answers PONG with the right password.
type redisHost struct {
	active   map[string]bool
	password string
	ssOut    string
}

func newRedisHost(t *testing.T, f *runnertest.Fake, root string) *redisHost {
	t.Helper()
	for _, unit := range []string{"redis-server@.service", "valkey-server@.service"} {
		p := filepath.Join(root, "/usr/lib/systemd/system", unit)
		os.MkdirAll(filepath.Dir(p), 0o755)
		os.WriteFile(p, []byte("[Service]\n"), 0o644)
	}
	h := &redisHost{active: map[string]bool{}}
	f.OnFunc("systemctl is-active", func(c runnertest.Call) (runner.Result, error) {
		if h.active[c.Args[len(c.Args)-1]] {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl start", func(c runnertest.Call) (runner.Result, error) {
		h.active[c.Args[len(c.Args)-1]] = true
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl disable --now", func(c runnertest.Call) (runner.Result, error) {
		delete(h.active, c.Args[len(c.Args)-1])
		return runner.Result{}, nil
	})
	f.OnFunc("ss ", func(runnertest.Call) (runner.Result, error) { return runner.Result{Stdout: []byte(h.ssOut)}, nil })
	cli := func(c runnertest.Call) (runner.Result, error) {
		for _, e := range c.Env {
			if e == "REDISCLI_AUTH="+h.password {
				return runner.Result{Stdout: []byte("PONG\n")}, nil
			}
		}
		return runner.Result{Stdout: []byte("AUTH failed: WRONGPASS invalid username-password pair or user is disabled.\n")}, nil
	}
	f.OnFunc("redis-cli", cli)
	f.OnFunc("valkey-cli", cli)
	return h
}

func redisPayload() RedisApplyPayload {
	return RedisApplyPayload{Engine: "redis", Name: "cache", Port: 6380, Password: "Xk3pQ9vR2mT7wL4nB8cF6hJ1", MaxMemoryMB: 128, Eviction: "noeviction", Persistence: "rdb"}
}

func TestRedisApplyWritesConfigStartsAndIsIdempotent(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	h.password = p.Password

	r, err := db.RedisApply(context.Background(), p, st)
	if err != nil {
		t.Fatal(err)
	}
	if res := r.(RedisApplyResult); !res.Changed || !res.Restarted || res.Port != 6380 {
		t.Fatalf("%+v", res)
	}
	conf := filepath.Join(root, "/etc/redis/redis-kiln-cache.conf")
	b, err := os.ReadFile(conf)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"port 6380\n", "bind 127.0.0.1\n", "protected-mode yes\n", `requirepass "Xk3pQ9vR2mT7wL4nB8cF6hJ1"` + "\n",
		"maxmemory 128mb\n", "maxmemory-policy noeviction\n", "save 3600 1\nsave 300 100\nsave 60 10000\n", "appendonly no\n",
		"dir /var/lib/redis/kiln-cache\n", "pidfile /run/redis-kiln-cache/redis-server.pid\n",
		`rename-command CONFIG ""`, `rename-command DEBUG ""`, `rename-command MODULE ""`, `rename-command SHUTDOWN ""`,
	} {
		if !strings.Contains(string(b), want) {
			t.Fatalf("config misses %q:\n%s", want, b)
		}
	}
	if fi, _ := os.Stat(conf); fi.Mode().Perm() != 0o640 {
		t.Fatalf("mode %v", fi.Mode())
	}
	if fi, err := os.Stat(filepath.Join(root, "/var/lib/redis/kiln-cache")); err != nil || !fi.IsDir() || fi.Mode().Perm() != 0o750 {
		t.Fatal("data dir", err)
	}
	if !f.Ran("systemctl enable --quiet redis-server@kiln-cache.service") || !f.Ran("systemctl start redis-server@kiln-cache.service") {
		t.Fatal(f.Lines())
	}
	// The password never reaches a command line.
	for _, c := range f.Calls() {
		if strings.Contains(c.Line, p.Password) {
			t.Fatalf("password on the command line: %s", c.Line)
		}
	}

	// Same payload, running instance: nothing changes, no restart.
	f.Reset()
	r, err = db.RedisApply(context.Background(), p, st)
	if err != nil {
		t.Fatal(err)
	}
	if res := r.(RedisApplyResult); res.Changed || res.Restarted {
		t.Fatalf("not idempotent: %+v %v", res, f.Lines())
	}
	if f.Ran("systemctl restart") || f.Ran("ss ") {
		t.Fatal(f.Lines())
	}

	// A new password restarts the instance and PING uses it.
	p.Password = "Nn4vX8sD2kQ6pR9tY3wZ7aB5"
	h.password = p.Password
	r, err = db.RedisApply(context.Background(), p, st)
	if err != nil {
		t.Fatal(err)
	}
	if res := r.(RedisApplyResult); !res.Changed || !res.Restarted || !f.Ran("systemctl restart redis-server@kiln-cache.service") {
		t.Fatalf("%+v %v", res, f.Lines())
	}
}

func TestRedisApplyValkeyPathsAndPersistence(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	p.Engine, p.Name, p.Persistence, p.Eviction, p.Bind = "valkey", "sessions", "aof", "allkeys-lru", []string{"10.0.0.5", "127.0.0.1"}
	h.password = p.Password
	if _, err := db.RedisApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	b, err := os.ReadFile(filepath.Join(root, "/etc/valkey/valkey-kiln-sessions.conf"))
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"bind 127.0.0.1 10.0.0.5\n", "appendonly yes\n", `save ""`, "maxmemory-policy allkeys-lru\n", "dir /var/lib/valkey/kiln-sessions\n", "pidfile /run/valkey-kiln-sessions/valkey-server.pid\n"} {
		if !strings.Contains(string(b), want) {
			t.Fatalf("config misses %q:\n%s", want, b)
		}
	}
	if !f.Ran("systemctl start valkey-server@kiln-sessions.service") || !f.Ran("valkey-cli -h 127.0.0.1 -p 6380 --no-auth-warning PING") {
		t.Fatal(f.Lines())
	}
}

func TestRedisApplyPortInUse(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	h.ssOut = `LISTEN 0 511 127.0.0.1:6380 0.0.0.0:* users:(("memcached",pid=812,fd=26))` + "\n"
	_, err := db.RedisApply(context.Background(), redisPayload(), st)
	if err == nil || !strings.Contains(err.Error(), "port 6380 is in use by memcached (pid 812)") {
		t.Fatal(err)
	}
	if f.Ran("systemctl start") || f.Ran("systemctl enable") {
		t.Fatal("started despite the port being taken", f.Lines())
	}
}

func TestRedisApplyFailsWhenNoPong(t *testing.T) {
	old := RedisReadyTimeout
	RedisReadyTimeout = 50 * time.Millisecond
	defer func() { RedisReadyTimeout = old }()
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	h.password = "something-else-entirely"
	f.On("journalctl", runner.Result{Stdout: []byte("Fatal error loading the DB\n")})
	_, err := db.RedisApply(context.Background(), redisPayload(), st)
	if err == nil || !strings.Contains(err.Error(), "did not answer PING") || !strings.Contains(err.Error(), "WRONGPASS") || !strings.Contains(err.Error(), "Fatal error loading the DB") {
		t.Fatal(err)
	}
}

func TestRedisApplyRejectsBadPayloads(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	newRedisHost(t, f, root)
	for _, mut := range []func(*RedisApplyPayload){
		func(p *RedisApplyPayload) { p.Name = "../etc" },
		func(p *RedisApplyPayload) { p.Name = "Cache" },
		func(p *RedisApplyPayload) { p.Engine = "memcached" },
		func(p *RedisApplyPayload) { p.Password = "short" },
		func(p *RedisApplyPayload) { p.Password = "with\"quote\nrename-command x y" },
		func(p *RedisApplyPayload) { p.Port = 80 },
		func(p *RedisApplyPayload) { p.Eviction = "lru" },
		func(p *RedisApplyPayload) { p.Persistence = "both" },
		func(p *RedisApplyPayload) { p.MaxMemoryMB = 1 },
		func(p *RedisApplyPayload) { p.Bind = []string{"0.0.0.0"} },
		func(p *RedisApplyPayload) { p.Bind = []string{"10.0.0.5 protected-mode no"} },
	} {
		p := redisPayload()
		mut(&p)
		if _, err := db.RedisApply(context.Background(), p, st); !commands.IsPayloadError(err) {
			t.Fatalf("%+v accepted: %v", p, err)
		}
	}
	if len(f.Calls()) != 0 {
		t.Fatal("ran commands for invalid payloads", f.Lines())
	}
}

func TestRedisApplyNotInstalled(t *testing.T) {
	f := &runnertest.Fake{}
	db, _ := newDB(t, f, nil)
	p := redisPayload()
	p.Engine = "valkey"
	if _, err := db.RedisApply(context.Background(), p, st); err == nil || !strings.Contains(err.Error(), "Valkey is not installed") {
		t.Fatal(err)
	}
}

func TestRedisRemove(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	h.password = p.Password
	if _, err := db.RedisApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(filepath.Join(root, "/var/lib/redis/kiln-cache/dump.rdb"), []byte("REDIS0011"), 0o600)
	r, err := db.RedisRemove(context.Background(), RedisRemovePayload{Engine: "redis", Name: "cache"}, st)
	if err != nil || !r.(ChangedResult).Changed {
		t.Fatal(r, err)
	}
	if !f.Ran("systemctl disable --now --quiet redis-server@kiln-cache.service") {
		t.Fatal(f.Lines())
	}
	for _, p := range []string{"/etc/redis/redis-kiln-cache.conf", "/var/lib/redis/kiln-cache"} {
		if _, err := os.Stat(filepath.Join(root, p)); !os.IsNotExist(err) {
			t.Fatalf("%s still there", p)
		}
	}
	// The stock instance's files are untouched.
	if r, _ := db.RedisRemove(context.Background(), RedisRemovePayload{Engine: "redis", Name: "cache"}, st); r.(ChangedResult).Changed {
		t.Fatal("remove not idempotent")
	}
	if _, err := db.RedisRemove(context.Background(), RedisRemovePayload{Engine: "redis", Name: "a/../../b"}, st); !commands.IsPayloadError(err) {
		t.Fatal(err)
	}
}
