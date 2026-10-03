package db

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

// fakeProc is one running redis-server as the fake sees it (started from the config file on disk).
type fakeProc struct {
	unit       string
	port       int
	password   string
	config     string // CONFIG's name
	appendonly bool
	save       string
	maxmemory  string
	policy     string
	dir        string // host path of the data dir
	loadedFrom string // dump.rdb | aof | empty
	loading    int    // PINGs answered LOADING first
}

// fakeRedisHost fakes systemd, useradd/id and redis-cli on a temp root. Instances behave as their config says:
// a start loads the AOF when appendonly is on (else dump.rdb), CONFIG SET works under the renamed name only.
type fakeRedisHost struct {
	t            *testing.T
	root         string
	procs        map[string]*fakeProc // by unit
	users        map[string]bool
	ssOut        string
	failRestarts int
	loading      int
	redisCmds    []string
}

func newRedisHost(t *testing.T, f *runnertest.Fake, root string) *fakeRedisHost {
	t.Helper()
	for _, unit := range []string{"redis-server@.service", "valkey-server@.service"} {
		p := filepath.Join(root, "/usr/lib/systemd/system", unit)
		os.MkdirAll(filepath.Dir(p), 0o755)
		os.WriteFile(p, []byte("[Service]\n"), 0o644)
	}
	h := &fakeRedisHost{t: t, root: root, procs: map[string]*fakeProc{}, users: map[string]bool{}}
	last := func(c runnertest.Call) string { return c.Args[len(c.Args)-1] }
	f.OnFunc("systemctl is-active", func(c runnertest.Call) (runner.Result, error) {
		if h.procs[last(c)] != nil {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl start", func(c runnertest.Call) (runner.Result, error) { return h.start(last(c)) })
	f.OnFunc("systemctl restart", func(c runnertest.Call) (runner.Result, error) {
		if h.failRestarts > 0 {
			h.failRestarts--
			return runner.Result{ExitCode: 1, Stderr: []byte("Job for unit failed")}, nil
		}
		return h.start(last(c))
	})
	f.OnFunc("systemctl disable --now", func(c runnertest.Call) (runner.Result, error) {
		delete(h.procs, last(c))
		return runner.Result{}, nil
	})
	f.OnFunc("id -u", func(c runnertest.Call) (runner.Result, error) {
		if h.users[last(c)] {
			return runner.Result{Stdout: []byte("998\n")}, nil
		}
		return runner.Result{ExitCode: 1}, nil
	})
	f.OnFunc("useradd", func(c runnertest.Call) (runner.Result, error) { h.users[last(c)] = true; return runner.Result{}, nil })
	f.OnFunc("userdel", func(c runnertest.Call) (runner.Result, error) { delete(h.users, last(c)); return runner.Result{}, nil })
	f.OnFunc("ss ", func(runnertest.Call) (runner.Result, error) { return runner.Result{Stdout: []byte(h.ssOut)}, nil })
	f.OnFunc("redis-cli", h.cli)
	f.OnFunc("valkey-cli", h.cli)
	return h
}

func (h *fakeRedisHost) start(unit string) (runner.Result, error) {
	// redis-server@kiln-cache.service → /etc/kiln-redis/cache.conf
	engine := strings.TrimSuffix(strings.SplitN(unit, "@", 2)[0], "-server")
	inst := strings.TrimSuffix(strings.SplitN(unit, "@", 2)[1], ".service")
	b, err := os.ReadFile(filepath.Join(h.root, "/etc/kiln-"+engine, strings.TrimPrefix(inst, "kiln-")+".conf"))
	if err != nil {
		return runner.Result{ExitCode: 1, Stderr: []byte("no config")}, nil
	}
	p := &fakeProc{unit: unit, loading: h.loading}
	var saves []string
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) < 2 {
			continue
		}
		switch f[0] {
		case "port":
			p.port, _ = strconv.Atoi(f[1])
		case "requirepass":
			p.password, _ = strconv.Unquote(f[1])
		case "rename-command":
			if f[1] == "CONFIG" {
				p.config = f[2]
			}
		case "appendonly":
			p.appendonly = f[1] == "yes"
		case "save":
			if f[1] != `""` {
				saves = append(saves, f[1], f[2])
			}
		case "maxmemory":
			p.maxmemory = f[1]
		case "maxmemory-policy":
			p.policy = f[1]
		case "dir":
			p.dir = filepath.Join(h.root, f[1])
		}
	}
	p.save = strings.Join(saves, " ")
	switch {
	case p.appendonly && exists(filepath.Join(p.dir, "appendonlydir")):
		p.loadedFrom = "aof"
	case p.appendonly:
		p.loadedFrom = "empty" // Redis ignores dump.rdb when AOF is on
	case exists(filepath.Join(p.dir, "dump.rdb")):
		p.loadedFrom = "dump.rdb"
	default:
		p.loadedFrom = "empty"
	}
	h.procs[unit] = p
	return runner.Result{}, nil
}

func (h *fakeRedisHost) cli(c runnertest.Call) (runner.Result, error) {
	port, _ := strconv.Atoi(c.Args[3])
	var p *fakeProc
	for _, proc := range h.procs {
		if proc.port == port {
			p = proc
		}
	}
	if p == nil {
		return runner.Result{ExitCode: 1, Stderr: []byte("Could not connect to Redis at 127.0.0.1:" + c.Args[3] + ": Connection refused")}, nil
	}
	out := func(s string) (runner.Result, error) { return runner.Result{Stdout: []byte(s + "\n")}, nil }
	if p.loading > 0 {
		p.loading--
		return out("LOADING Redis is loading the dataset in memory")
	}
	if !slicesContain(c.Env, "REDISCLI_AUTH="+p.password) {
		return out("NOAUTH Authentication required.")
	}
	args := splitQuoted(h.t, strings.TrimSpace(c.Stdin))
	h.redisCmds = append(h.redisCmds, strings.Join(args, " "))
	switch {
	case args[0] == "PING":
		return out("PONG")
	case args[0] == "SAVE":
		os.WriteFile(filepath.Join(p.dir, "dump.rdb"), []byte("REDIS0009"), 0o600)
		return out("OK")
	case args[0] == "INFO":
		enabled := map[bool]string{true: "1", false: "0"}[p.appendonly]
		return out("# Persistence\r\naof_enabled:" + enabled + "\r\naof_rewrite_in_progress:0\r\naof_rewrite_scheduled:0\r\naof_last_bgrewrite_status:ok\r")
	case args[0] == p.config && len(args) == 4 && args[1] == "SET":
		switch args[2] {
		case "requirepass":
			p.password = args[3]
		case "maxmemory":
			p.maxmemory = args[3]
		case "maxmemory-policy":
			p.policy = args[3]
		case "save":
			p.save = args[3]
		case "appendonly":
			p.appendonly = args[3] == "yes"
			if p.appendonly {
				os.MkdirAll(filepath.Join(p.dir, "appendonlydir"), 0o700)
				os.WriteFile(filepath.Join(p.dir, "appendonlydir", "appendonly.aof.manifest"), []byte("fresh"), 0o600)
			}
		}
		return out("OK")
	}
	return out(fmt.Sprintf("ERR unknown command '%s', with args beginning with:", args[0]))
}

// splitQuoted splits a redis-cli stdin line of double-quoted arguments (as conn.do writes them).
func splitQuoted(t *testing.T, line string) []string {
	var args []string
	for line != "" {
		if line[0] != '"' {
			t.Fatalf("unquoted argument in %q", line)
		}
		end := 1
		for ; end < len(line) && line[end] != '"'; end++ {
			if line[end] == '\\' {
				end++
			}
		}
		u, err := strconv.Unquote(line[:end+1])
		if err != nil {
			t.Fatalf("bad argument in %q", line)
		}
		args = append(args, u)
		line = strings.TrimSpace(line[end+1:])
	}
	return args
}

func slicesContain(s []string, v string) bool {
	for _, x := range s {
		if x == v {
			return true
		}
	}
	return false
}

func redisPayload() RedisApplyPayload {
	return RedisApplyPayload{Engine: "redis", Name: "cache", Port: 6380, Password: "Xk3pQ9vR2mT7wL4nB8cF6hJ1", MaxMemoryMB: 128, Eviction: "noeviction", Persistence: "rdb"}
}

func applyOK(t *testing.T, db *DB, p RedisApplyPayload) RedisApplyResult {
	t.Helper()
	r, err := db.RedisApply(context.Background(), p, st)
	if err != nil {
		t.Fatal(err)
	}
	return r.(RedisApplyResult)
}

func readState(t *testing.T, db *DB, engine, name string) redisState {
	t.Helper()
	k, _ := kvEngineFor(engine)
	var s redisState
	b, err := db.d.FS.ReadFile(db.redisStatePath(k, name))
	if err != nil {
		t.Fatal(err)
	}
	json.Unmarshal(b, &s)
	return s
}

func noSecretsOnCommandLines(t *testing.T, f *runnertest.Fake, secrets ...string) {
	t.Helper()
	for _, c := range f.Calls() {
		for _, s := range secrets {
			if s != "" && strings.Contains(c.Line, s) {
				t.Fatalf("secret on a command line: %s", c.Line)
			}
		}
	}
}

func TestRedisApplyCreatesAnIsolatedInstance(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()

	if r := applyOK(t, db, p); !r.Changed || !r.Restarted || r.Port != 6380 {
		t.Fatalf("%+v", r)
	}
	// Own system user, set by a drop-in that only lets it write its data and runtime directories.
	if !h.users["kiln-redis-cache"] || !f.Ran("useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin") {
		t.Fatal(f.Lines())
	}
	dropIn, _ := os.ReadFile(filepath.Join(root, "/etc/systemd/system/redis-server@kiln-cache.service.d/50-kiln.conf"))
	for _, want := range []string{"ExecStart=\nExecStart=/usr/bin/redis-server /etc/kiln-redis/cache.conf --supervised systemd --daemonize no\n", "User=kiln-redis-cache\n", "Group=kiln-redis-cache\n", "ReadWritePaths=\nReadWritePaths=/var/lib/kiln-redis/cache\nReadWritePaths=-/run/redis-kiln-cache\n"} {
		if !strings.Contains(string(dropIn), want) {
			t.Fatalf("drop-in misses %q:\n%s", want, dropIn)
		}
	}
	if !f.Ran("systemctl daemon-reload") || !f.Ran("systemctl enable --quiet redis-server@kiln-cache.service") || !f.Ran("systemctl start redis-server@kiln-cache.service") {
		t.Fatal(f.Lines())
	}

	conf := filepath.Join(root, "/etc/kiln-redis/cache.conf")
	b, _ := os.ReadFile(conf)
	state := readState(t, db, "redis", "cache")
	for _, want := range []string{
		"port 6380\n", "bind 127.0.0.1\n", "protected-mode yes\n", `requirepass "Xk3pQ9vR2mT7wL4nB8cF6hJ1"` + "\n",
		"maxmemory 128mb\n", "maxmemory-policy noeviction\n", "save 3600 1\nsave 300 100\nsave 60 10000\n", "appendonly no\n",
		"dir /var/lib/kiln-redis/cache\n", "pidfile /run/redis-kiln-cache/redis-server.pid\n", "logfile \"\"\n",
		"rename-command CONFIG " + state.ConfigName + "\n",
		`rename-command DEBUG ""`, `rename-command MODULE ""`, `rename-command SHUTDOWN ""`, `rename-command REPLICAOF ""`,
		`rename-command SLAVEOF ""`, `rename-command MIGRATE ""`, `rename-command ACL ""`, `rename-command MONITOR ""`,
	} {
		if !strings.Contains(string(b), want) {
			t.Fatalf("config misses %q:\n%s", want, b)
		}
	}
	if !redisCfgName.MatchString(state.ConfigName) || state.Applied != hashOf(string(b)) || state.Persistence != "rdb" {
		t.Fatalf("%+v", state)
	}
	for path, mode := range map[string]os.FileMode{conf: 0o640, filepath.Join(root, "/var/lib/kiln-redis/cache"): 0o700, db.d.FS.P(db.redisStatePath(kvEngine{name: "redis"}, "cache")): 0o600} {
		if fi, err := os.Stat(path); err != nil || fi.Mode().Perm() != mode {
			t.Fatalf("%s: %v %v", path, fi.Mode(), err)
		}
	}
	noSecretsOnCommandLines(t, f, p.Password, state.ConfigName)

	// Same payload, running instance: nothing to do.
	f.Reset()
	if r := applyOK(t, db, p); r.Changed || r.Restarted {
		t.Fatalf("not idempotent: %+v %v", r, f.Lines())
	}
	if f.Ran("systemctl restart") || f.Ran("useradd") || len(h.redisCmds) != 1 /* the PING of the first apply's ready */ +1 {
		t.Fatal(f.Lines(), h.redisCmds)
	}
}

func TestRedisApplyChangesMemoryEvictionAndPasswordLive(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	applyOK(t, db, p)
	cfg := readState(t, db, "redis", "cache").ConfigName
	f.Reset()

	p.MaxMemoryMB, p.Eviction, p.Password = 256, "allkeys-lru", "Nn4vX8sD2kQ6pR9tY3wZ7aB5"
	if r := applyOK(t, db, p); !r.Changed || r.Restarted {
		t.Fatalf("%+v", r)
	}
	proc := h.procs["redis-server@kiln-cache.service"]
	if proc.maxmemory != "256mb" || proc.policy != "allkeys-lru" || proc.password != p.Password || f.Ran("systemctl restart") {
		t.Fatalf("%+v %v", proc, f.Lines())
	}
	if !slicesContain(h.redisCmds, cfg+" SET requirepass "+p.Password) || slicesContain(h.redisCmds, "CONFIG SET maxmemory 256mb") {
		t.Fatal(h.redisCmds)
	}
	b, _ := os.ReadFile(filepath.Join(root, "/etc/kiln-redis/cache.conf"))
	if !strings.Contains(string(b), `requirepass "`+p.Password+`"`) || readState(t, db, "redis", "cache").Applied != hashOf(string(b)) {
		t.Fatal("file / state do not match the live instance")
	}
	noSecretsOnCommandLines(t, f, p.Password, cfg)
	// Then a no-op.
	if r := applyOK(t, db, p); r.Changed {
		t.Fatal("not idempotent after a live change")
	}
}

func TestRedisPersistenceTransitionsLiveKeepTheData(t *testing.T) {
	old := redisNow
	redisNow = func() time.Time { return time.Date(2026, 10, 3, 12, 0, 0, 0, time.UTC) }
	defer func() { redisNow = old }()
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	applyOK(t, db, p)
	cfg := readState(t, db, "redis", "cache").ConfigName
	data := filepath.Join(root, "/var/lib/kiln-redis/cache")
	proc := func() *fakeProc { return h.procs["redis-server@kiln-cache.service"] }
	step := func(to string) {
		t.Helper()
		h.redisCmds = nil
		f.Reset()
		p.Persistence = to
		if r := applyOK(t, db, p); r.Restarted {
			t.Fatalf("%s: restarted", to)
		}
		if s := readState(t, db, "redis", "cache"); s.Persistence != to {
			t.Fatalf("state %+v", s)
		}
	}

	// rdb → aof: a stale AOF from long ago is moved aside first, then AOF is switched on live (rewrite from memory).
	os.MkdirAll(filepath.Join(data, "appendonlydir"), 0o700)
	os.WriteFile(filepath.Join(data, "appendonlydir", "appendonly.aof.manifest"), []byte("stale"), 0o600)
	step("aof")
	if !exists(filepath.Join(data, "appendonlydir.kiln-20261003T120000Z")) || !proc().appendonly || proc().save != "" {
		t.Fatalf("%+v", proc())
	}
	if m, _ := os.ReadFile(filepath.Join(data, "appendonlydir", "appendonly.aof.manifest")); string(m) != "fresh" {
		t.Fatal("stale AOF kept")
	}
	if strings.Join(h.redisCmds, "|") != "PING|"+cfg+" SET maxmemory 128mb|"+cfg+" SET maxmemory-policy noeviction|"+cfg+" SET appendonly yes|INFO persistence|"+cfg+" SET save " {
		t.Fatal(h.redisCmds)
	}

	// aof → rdb: SAVE before AOF goes off, so dump.rdb holds the data.
	step("rdb")
	if strings.Join(h.redisCmds[3:], "|") != cfg+" SET save "+redisSave+"|SAVE|"+cfg+" SET appendonly no" || !exists(filepath.Join(data, "dump.rdb")) {
		t.Fatal(h.redisCmds)
	}

	// rdb → none: nothing written any more; earlier files moved aside so a restart can't bring stale data back.
	step("none")
	if exists(filepath.Join(data, "dump.rdb")) || !exists(filepath.Join(data, "dump.rdb.kiln-20261003T120000Z")) || proc().save != "" {
		t.Fatal("none kept files", h.redisCmds)
	}

	// none → rdb: snapshot right away.
	step("rdb")
	if !slicesContain(h.redisCmds, "SAVE") || !exists(filepath.Join(data, "dump.rdb")) {
		t.Fatal(h.redisCmds)
	}
}

func TestRedisRestartsKeepTheDataAcrossPersistenceChanges(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	applyOK(t, db, p)
	proc := func() *fakeProc { return h.procs["redis-server@kiln-cache.service"] }

	// A new port needs a restart. rdb → aof that way: SAVE first, start without AOF (loads dump.rdb), AOF on live.
	h.redisCmds = nil
	p.Port, p.Persistence = 6381, "aof"
	if r := applyOK(t, db, p); !r.Restarted {
		t.Fatal(r)
	}
	if proc().loadedFrom != "dump.rdb" || !proc().appendonly || h.redisCmds[0] != "PING" || h.redisCmds[1] != "SAVE" {
		t.Fatalf("%+v %v", proc(), h.redisCmds)
	}
	b, _ := os.ReadFile(filepath.Join(root, "/etc/kiln-redis/cache.conf"))
	if !strings.Contains(string(b), "appendonly yes\n") || !strings.Contains(string(b), "port 6381\n") {
		t.Fatalf("final config:\n%s", b)
	}

	// aof → rdb with a restart: SAVE before it, then the restarted instance loads dump.rdb.
	h.redisCmds = nil
	p.Port, p.Persistence = 6382, "rdb"
	applyOK(t, db, p)
	if proc().loadedFrom != "dump.rdb" || !slicesContain(h.redisCmds, "SAVE") {
		t.Fatalf("%+v %v", proc(), h.redisCmds)
	}

	// aof again via a restart: the AOF left from the earlier aof period is stale and moved aside before the start.
	p.Port, p.Persistence = 6383, "aof"
	applyOK(t, db, p)
	if proc().loadedFrom != "dump.rdb" {
		t.Fatalf("loaded %s", proc().loadedFrom)
	}
}

func TestRedisApplyRedeliveredAfterAFailedRestart(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	applyOK(t, db, p)
	before := readState(t, db, "redis", "cache")

	// The config file is written, the restart fails: the marker stays on what runs.
	h.failRestarts = 1
	p.Port = 6390
	if _, err := db.RedisApply(context.Background(), p, st); err == nil {
		t.Fatal("restart failure not reported")
	}
	if s := readState(t, db, "redis", "cache"); s.Applied != before.Applied || s.Port != 6380 {
		t.Fatalf("marker moved: %+v", s)
	}
	if h.procs["redis-server@kiln-cache.service"].port != 6380 {
		t.Fatal("fake restarted")
	}

	// The redelivery sees the file already written but not live, and restarts.
	f.Reset()
	if r := applyOK(t, db, p); !r.Restarted {
		t.Fatalf("redelivery skipped the restart: %+v %v", r, f.Lines())
	}
	if h.procs["redis-server@kiln-cache.service"].port != 6390 || readState(t, db, "redis", "cache").Port != 6390 {
		t.Fatal("not converged")
	}

	// A process that does not take the wanted password is not "applied" either (someone changed it by hand).
	h.procs["redis-server@kiln-cache.service"].password = "someone-elses-password"
	f.Reset()
	if r := applyOK(t, db, p); !r.Restarted {
		t.Fatalf("%+v %v", r, f.Lines())
	}
}

func TestRedisApplyValkeyPathsAndLongNames(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	p.Engine, p.Name, p.Bind = "valkey", "sessions", []string{"10.0.0.5", "127.0.0.1"}
	applyOK(t, db, p)
	b, err := os.ReadFile(filepath.Join(root, "/etc/kiln-valkey/sessions.conf"))
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"bind 127.0.0.1 10.0.0.5\n", "dir /var/lib/kiln-valkey/sessions\n", "pidfile /run/valkey-kiln-sessions/valkey-server.pid\n"} {
		if !strings.Contains(string(b), want) {
			t.Fatalf("config misses %q:\n%s", want, b)
		}
	}
	if !h.users["kiln-valkey-sessions"] || !exists(filepath.Join(root, "/etc/systemd/system/valkey-server@kiln-sessions.service.d/50-kiln.conf")) {
		t.Fatal("valkey user / drop-in")
	}
	if !f.Ran("systemctl start valkey-server@kiln-sessions.service") || !f.Ran("valkey-cli -h 127.0.0.1 -p 6380 --no-auth-warning") {
		t.Fatal(f.Lines())
	}

	k, _ := kvEngineFor("valkey")
	long := strings.Repeat("a", 41)
	if u := k.user(long); len(u) > 32 || !strings.HasPrefix(u, "kiln-valkey-aaaa") || u == k.user(strings.Repeat("a", 40)+"b") {
		t.Fatal(u)
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

func TestRedisReadyWaitsWhileLoadingAndFailsWithoutPong(t *testing.T) {
	old, oldPoll := RedisReadyTimeout, redisPoll
	RedisReadyTimeout, redisPoll = 30*time.Millisecond, 20*time.Millisecond
	defer func() { RedisReadyTimeout, redisPoll = old, oldPoll }()

	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	h.loading = 4 // longer than RedisReadyTimeout: LOADING extends the wait
	applyOK(t, db, redisPayload())

	// Nothing answers on the port (rules match in order: this one comes before the fake host's).
	f2 := &runnertest.Fake{}
	f2.On("journalctl", runner.Result{Stdout: []byte("Fatal error loading the DB\n")})
	f2.OnFunc("redis-cli", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: 1, Stderr: []byte("Could not connect to Redis at 127.0.0.1:6380: Connection refused")}, nil
	})
	db2, root2 := newDB(t, f2, nil)
	newRedisHost(t, f2, root2)
	_, err := db2.RedisApply(context.Background(), redisPayload(), st)
	if err == nil || !strings.Contains(err.Error(), "did not answer PING") || !strings.Contains(err.Error(), "Fatal error loading the DB") {
		t.Fatal(err)
	}
}

func TestRedisApplyRejectsBadPayloadsAndLinks(t *testing.T) {
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

	// A data directory that is a symlink is refused, never chmodded or followed.
	os.MkdirAll(filepath.Join(root, "/var/lib/kiln-redis"), 0o755)
	os.MkdirAll(filepath.Join(root, "/elsewhere"), 0o755)
	os.Symlink(filepath.Join(root, "/elsewhere"), filepath.Join(root, "/var/lib/kiln-redis/cache"))
	if _, err := db.RedisApply(context.Background(), redisPayload(), st); err == nil || !strings.Contains(err.Error(), "is not a directory") {
		t.Fatal(err)
	}
	if fi, _ := os.Stat(filepath.Join(root, "/elsewhere")); fi.Mode().Perm() != 0o755 {
		t.Fatal("chmod followed the link")
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
	applyOK(t, db, redisPayload())
	os.WriteFile(filepath.Join(root, "/var/lib/kiln-redis/cache/dump.rdb"), []byte("REDIS0011"), 0o600)
	f.Reset()

	r, err := db.RedisRemove(context.Background(), RedisRemovePayload{Engine: "redis", Name: "cache"}, st)
	if err != nil || !r.(ChangedResult).Changed {
		t.Fatal(r, err)
	}
	if !f.Ran("systemctl disable --now --quiet redis-server@kiln-cache.service") || !f.Ran("systemctl daemon-reload") || !f.Ran("userdel kiln-redis-cache") || h.users["kiln-redis-cache"] {
		t.Fatal(f.Lines())
	}
	for _, p := range []string{"/etc/kiln-redis/cache.conf", "/var/lib/kiln-redis/cache", "/etc/systemd/system/redis-server@kiln-cache.service.d", "/var/lib/kiln/db/redis/redis-cache.json"} {
		if exists(filepath.Join(root, p)) {
			t.Fatalf("%s still there", p)
		}
	}
	if r, _ := db.RedisRemove(context.Background(), RedisRemovePayload{Engine: "redis", Name: "cache"}, st); r.(ChangedResult).Changed {
		t.Fatal("remove not idempotent")
	}
	if _, err := db.RedisRemove(context.Background(), RedisRemovePayload{Engine: "redis", Name: "a/../../b"}, st); !commands.IsPayloadError(err) {
		t.Fatal(err)
	}
}

func TestParseRedisConfRoundTrips(t *testing.T) {
	k, _ := kvEngineFor("redis")
	p := redisPayload()
	for _, persistence := range redisPersist {
		p.Persistence = persistence
		c := parseRedisConf([]byte(renderRedisConf(k, p, []string{"127.0.0.1", "10.0.0.5"}, "kiln-config-0123456789abcdef0123456789abcdef", persistence == "aof")))
		if c.port != 6380 || c.password != p.Password || c.persistence != persistence || c.configName != "kiln-config-0123456789abcdef0123456789abcdef" || len(c.bind) != 2 {
			t.Fatalf("%s: %+v", persistence, c)
		}
	}
	var re *RedisError
	if !errors.As(error(&RedisError{Reply: "LOADING"}), &re) {
		t.Fatal()
	}
}
