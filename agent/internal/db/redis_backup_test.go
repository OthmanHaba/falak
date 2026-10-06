package db

import (
	"bytes"
	"compress/gzip"
	"context"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestParseRDBHeader(t *testing.T) {
	for in, want := range map[string]string{
		"REDIS0009\xfa\x09redis-ver": "REDIS0009",
		"REDIS0011":                  "REDIS0011",
		"REDIS0012\xfe\x00":          "REDIS0012",
		"VALKEY080\xfa":              "VALKEY080",
	} {
		h, err := parseRDBHeader([]byte(in))
		if err != nil || h.String() != want {
			t.Errorf("%q: %v %v", in, h, err)
		}
	}
	for _, in := range []string{"", "REDIS", "REDIS00", "redis0011", "REDIS00x1", "REDIS0000", "VALKEY08", "-- MySQL dump", "\x1f\x8b\x08\x00gzip", "REDIS+011", "VALKEY-80"} {
		if _, err := parseRDBHeader([]byte(in)); err == nil {
			t.Errorf("%q accepted", in)
		}
	}
}

func TestRDBLoadable(t *testing.T) {
	redis, _ := kvEngineFor("redis")
	valkey, _ := kvEngineFor("valkey")
	hdr := func(s string) rdbHeader {
		h, err := parseRDBHeader([]byte(s))
		if err != nil {
			t.Fatal(err)
		}
		return h
	}
	for _, c := range []struct {
		k       kvEngine
		version string
		rdb     string
		refuse  string // "" = loadable
	}{
		{redis, "6.0.16", "REDIS0009", ""},
		{redis, "6.0.16", "REDIS0010", "Redis than 6.0.16, which loads RDB version 9"},
		{redis, "7.0.15", "REDIS0010", ""},
		{redis, "7.0.15", "REDIS0011", "loads RDB version 10"}, // a Valkey 8 / Redis 7.2 snapshot on Ubuntu 24.04's Redis
		{redis, "7.2.4", "REDIS0011", ""},
		{redis, "8.0.2", "REDIS0012", ""},
		{redis, "8.0.2", "REDIS0013", ""}, // unknown upper bound: Redis itself decides (rollback)
		{redis, "", "REDIS0012", ""},
		{redis, "8.0.2", "VALKEY080", "Redis can't load"},
		{valkey, "7.2.13", "REDIS0011", ""},
		{valkey, "9.0.0", "REDIS0010", ""},
		{valkey, "8.1.1", "REDIS0012", "comes from Redis 7.4 or newer"},
		{valkey, "9.0.0", "REDIS0012", "Restore it into a Redis instance"},
		{valkey, "", "REDIS0012", "Valkey only loads snapshots of Redis 7.2 and older"},
		{valkey, "8.1.1", "VALKEY080", "Valkey 8.1.1 can't load"},
		{valkey, "9.0.0", "VALKEY080", ""},
	} {
		err := rdbLoadable(c.k, c.version, hdr(c.rdb))
		if c.refuse == "" && err != nil || c.refuse != "" && (err == nil || !strings.Contains(err.Error(), c.refuse)) {
			t.Errorf("%s %s ← %s: %v (want %q)", c.k.label, c.version, c.rdb, err, c.refuse)
		}
	}
}

func gunzipFile(t *testing.T, path string) string {
	t.Helper()
	f, err := os.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := io.ReadAll(gz)
	return string(b)
}

func writeGzip(t *testing.T, path, content string) {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	gz.Write([]byte(content))
	gz.Close()
	os.MkdirAll(filepath.Dir(path), 0o755)
	if err := os.WriteFile(path, buf.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestRedisBackupSnapshotsTheRunningInstance(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	applyOK(t, db, p)
	f.Reset()

	r, err := db.Backup(context.Background(), BackupPayload{Engine: "redis", Database: "cache", Compression: "gzip", Destination: Location{Kind: "local", Path: "/backups/cache.rdb.gz"}}, st)
	if err != nil {
		t.Fatal(err)
	}
	res := r.(BackupResult)
	if res.RDB != "REDIS0011" || res.SHA256 == "" || res.SizeBytes == 0 || res.Location != "/backups/cache.rdb.gz" || res.UncompressedBytes != int64(len("REDIS0011 snapshot of redis-server@falak-cache.service")) {
		t.Fatalf("%+v", res)
	}
	if got := gunzipFile(t, filepath.Join(root, "/backups/cache.rdb.gz")); got != "REDIS0011 snapshot of redis-server@falak-cache.service" {
		t.Fatalf("backup holds %q", got)
	}
	// redis-cli --rdb on the instance's port, the password in the environment only; nothing restarted, no file left.
	var snap runnertest.Call
	for _, c := range f.Calls() {
		if strings.Contains(c.Line, "--rdb") {
			snap = c
		}
	}
	if !strings.HasPrefix(snap.Line, "redis-cli -h 127.0.0.1 -p 6380 --no-auth-warning --rdb ") || !slicesContain(snap.Env, "REDISCLI_AUTH="+p.Password) {
		t.Fatalf("%q %v", snap.Line, snap.Env)
	}
	if exists(snap.Args[len(snap.Args)-1]) {
		t.Fatal("snapshot file left behind")
	}
	if f.Ran("systemctl stop") || f.Ran("systemctl start") || len(h.procs) != 1 {
		t.Fatal(f.Lines())
	}
	noSecretsOnCommandLines(t, f, p.Password, readState(t, db, "redis", "cache").ConfigName)

	// Uncompressed: the RDB file as is.
	if _, err := db.Backup(context.Background(), BackupPayload{Engine: "redis", Database: "cache", Compression: "none", Destination: Location{Kind: "local", Path: "/backups/cache.rdb"}}, st); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "/backups/cache.rdb")); !strings.HasPrefix(string(b), "REDIS0011") {
		t.Fatalf("%q", b)
	}

	// Whatever redis-cli wrote must be a snapshot.
	h.rdb = "-ERR something"
	if _, err := db.Backup(context.Background(), BackupPayload{Engine: "redis", Database: "cache", Destination: Location{Kind: "local", Path: "/backups/bad.rdb.gz"}}, st); err == nil || !strings.Contains(err.Error(), "no REDIS or VALKEY header") {
		t.Fatal(err)
	}
	if exists(filepath.Join(root, "/backups/bad.rdb.gz")) {
		t.Fatal("bad backup stored")
	}

	// A stopped or unknown instance can't be backed up; names are checked.
	delete(h.procs, "redis-server@falak-cache.service")
	if _, err := db.Backup(context.Background(), BackupPayload{Engine: "redis", Database: "cache", Destination: Location{Kind: "local", Path: "/backups/x.rdb.gz"}}, st); err == nil || !strings.Contains(err.Error(), "is not running") {
		t.Fatal(err)
	}
	if _, err := db.Backup(context.Background(), BackupPayload{Engine: "valkey", Database: "cache", Destination: Location{Kind: "local", Path: "/backups/x.rdb.gz"}}, st); err == nil || !strings.Contains(err.Error(), "does not exist") {
		t.Fatal(err)
	}
	if _, err := db.Backup(context.Background(), BackupPayload{Engine: "redis", Database: "Cache!", Destination: Location{Kind: "local", Path: "/backups/x.rdb.gz"}}, st); err == nil {
		t.Fatal("bad name accepted")
	}
}

func TestRedisBackupFailureIsRedacted(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	newRedisHost(t, f, root)
	p := redisPayload()
	applyOK(t, db, p)
	// An earlier rule wins: this redis-cli --rdb fails echoing the password.
	f2 := &runnertest.Fake{}
	f2.OnFunc("redis-cli -h 127.0.0.1 -p 6380 --no-auth-warning --rdb", func(runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: 1, Stderr: []byte("AUTH failed: WRONGPASS " + p.Password + "\n")}, nil
	})
	db.d.Runner = chain{f2, f}
	_, err := db.Backup(context.Background(), BackupPayload{Engine: "redis", Database: "cache", Destination: Location{Kind: "local", Path: "/backups/x.rdb.gz"}}, st)
	if err == nil || strings.Contains(err.Error(), p.Password) || !strings.Contains(err.Error(), "AUTH failed") {
		t.Fatal(err)
	}
}

// chain runs a command on the first fake that has a rule for it.
type chain []*runnertest.Fake

func (c chain) Run(ctx context.Context, cmd runner.Cmd) (runner.Result, error) {
	line := cmd.String()
	if strings.HasPrefix(line, "redis-cli -h 127.0.0.1 -p 6380 --no-auth-warning --rdb") {
		return c[0].Run(ctx, cmd)
	}
	return c[1].Run(ctx, cmd)
}

func restorePayload(engine, file string) RestorePayload {
	return RestorePayload{Engine: engine, Database: "cache", Compression: "gzip", Source: Location{Kind: "local", Path: file}}
}

func TestRedisRestoreReplacesTheDumpAndKeepsTheOldOne(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	f.On(filepath.Join(root, "/usr/bin/redis-server")+" --version", runner.Result{Stdout: []byte("Redis server v=7.2.4 sha=00000000:0 malloc=jemalloc-5.3.0 bits=64 build=1\n")})
	redisNow = func() time.Time { return time.Date(2026, 10, 6, 12, 0, 0, 0, time.UTC) }
	defer func() { redisNow = time.Now }()
	applyOK(t, db, redisPayload())
	conf, _ := os.ReadFile(filepath.Join(root, "/etc/falak-redis/cache.conf"))
	state := readState(t, db, "redis", "cache")
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011 restored data")
	data := filepath.Join(root, "/var/lib/falak-redis/cache")
	f.Reset()

	r, err := db.Restore(context.Background(), restorePayload("redis", "/backups/b.rdb.gz"), st)
	if err != nil {
		t.Fatal(err)
	}
	res := r.(RestoreResult)
	if res.RDB != "REDIS0011" || res.Bytes != int64(len("REDIS0011 restored data")) || strings.Join(res.MovedAside, ",") != "dump.rdb.falak-20261006T120000Z" {
		t.Fatalf("%+v", res)
	}
	// Stopped (its final snapshot is the one kept), the snapshot installed as dump.rdb (0600) and loaded by the start.
	proc := h.procs["redis-server@falak-cache.service"]
	if proc == nil || proc.loadedFrom != "dump.rdb" || proc.loaded != "REDIS0011 restored data" {
		t.Fatalf("%+v", proc)
	}
	if b, _ := os.ReadFile(filepath.Join(data, "dump.rdb.falak-20261006T120000Z")); string(b) != "REDIS0009 at stop" {
		t.Fatalf("old dump: %q", b)
	}
	if fi, err := os.Stat(filepath.Join(data, "dump.rdb")); err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatal(fi, err)
	}
	if exists(filepath.Join(data, ".falak-restore.rdb")) {
		t.Fatal("staging file left")
	}
	if !f.Ran("systemctl stop redis-server@falak-cache.service") || !f.Ran("systemctl start redis-server@falak-cache.service") {
		t.Fatal(f.Lines())
	}
	// Config and state untouched: the next apply is a no-op.
	if b, _ := os.ReadFile(filepath.Join(root, "/etc/falak-redis/cache.conf")); string(b) != string(conf) || readState(t, db, "redis", "cache").Applied != state.Applied {
		t.Fatal("config or state changed")
	}
	if r := applyOK(t, db, redisPayload()); r.Changed {
		t.Fatal("apply after restore not a no-op")
	}
}

func TestRedisRestoreWithAOFStartsFromTheSnapshotThenSwitchesAOFBackOn(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	redisNow = func() time.Time { return time.Date(2026, 10, 6, 12, 0, 0, 0, time.UTC) }
	defer func() { redisNow = time.Now }()
	p := redisPayload()
	p.Engine, p.Persistence = "valkey", "aof"
	applyOK(t, db, p)
	conf, _ := os.ReadFile(filepath.Join(root, "/etc/falak-valkey/cache.conf"))
	state := readState(t, db, "valkey", "cache")
	data := filepath.Join(root, "/var/lib/falak-valkey/cache")
	if !exists(filepath.Join(data, "appendonlydir", "appendonly.aof.manifest")) {
		t.Fatal("no AOF to start with")
	}
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011 restored data")
	h.redisCmds = nil

	r, err := db.Restore(context.Background(), restorePayload("valkey", "/backups/b.rdb.gz"), st)
	if err != nil {
		t.Fatal(err)
	}
	proc := h.procs["valkey-server@falak-cache.service"]
	// Started without AOF from the snapshot, then AOF on live (the rewrite) through the renamed CONFIG.
	if proc.loadedFrom != "dump.rdb" || proc.loaded != "REDIS0011 restored data" || !proc.appendonly || proc.save != "" {
		t.Fatalf("%+v", proc)
	}
	if !slicesContain(h.redisCmds, state.ConfigName+" SET appendonly yes") {
		t.Fatal(h.redisCmds)
	}
	moved := strings.Join(r.(RestoreResult).MovedAside, ",")
	if moved != "appendonlydir.falak-20261006T120000Z" { // AOF: no save points, the stop writes no dump.rdb
		t.Fatal(moved)
	}
	if !exists(filepath.Join(data, "appendonlydir.falak-20261006T120000Z", "appendonly.aof.manifest")) || !exists(filepath.Join(data, "appendonlydir", "appendonly.aof.manifest")) {
		t.Fatal("AOF not moved aside / not rewritten")
	}
	// The config file says AOF again and the state matches it.
	if b, _ := os.ReadFile(filepath.Join(root, "/etc/falak-valkey/cache.conf")); string(b) != string(conf) || !reflect.DeepEqual(readState(t, db, "valkey", "cache"), state) {
		t.Fatal("config / state not put back")
	}
	if r := applyOK(t, db, p); r.Changed {
		t.Fatal("apply after restore not a no-op")
	}
}

func TestRedisRestoreRollsBackWhenTheStartFails(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	f.On("journalctl -u redis-server@falak-cache.service", runner.Result{Stdout: []byte("# Short read or OOM loading DB. Unrecoverable error, aborting now.\n")})
	redisNow = func() time.Time { return time.Date(2026, 10, 6, 12, 0, 0, 0, time.UTC) }
	defer func() { redisNow = time.Now }()
	p := redisPayload()
	p.Persistence = "aof"
	applyOK(t, db, p)
	conf, _ := os.ReadFile(filepath.Join(root, "/etc/falak-redis/cache.conf"))
	state := readState(t, db, "redis", "cache")
	data := filepath.Join(root, "/var/lib/falak-redis/cache")
	manifest, _ := os.ReadFile(filepath.Join(data, "appendonlydir", "appendonly.aof.manifest"))
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011 truncated")
	h.badDump = "truncated"

	_, err := db.Restore(context.Background(), restorePayload("redis", "/backups/b.rdb.gz"), st)
	if err == nil || !strings.Contains(err.Error(), "the earlier data is back") || !strings.Contains(err.Error(), "Short read or OOM loading DB") {
		t.Fatal(err)
	}
	// The earlier AOF is back in place and running again with the AOF config; the restored snapshot is gone.
	proc := h.procs["redis-server@falak-cache.service"]
	if proc == nil || proc.loadedFrom != "aof" || !proc.appendonly {
		t.Fatalf("%+v", proc)
	}
	if b, _ := os.ReadFile(filepath.Join(data, "appendonlydir", "appendonly.aof.manifest")); string(b) != string(manifest) {
		t.Fatal("AOF not put back")
	}
	if b, _ := os.ReadFile(filepath.Join(data, "dump.rdb")); strings.Contains(string(b), "truncated") {
		t.Fatal("restored snapshot left in place")
	}
	if exists(filepath.Join(data, "appendonlydir.falak-20261006T120000Z")) {
		t.Fatal("moved-aside AOF still aside")
	}
	if b, _ := os.ReadFile(filepath.Join(root, "/etc/falak-redis/cache.conf")); string(b) != string(conf) || !reflect.DeepEqual(readState(t, db, "redis", "cache"), state) {
		t.Fatal("config / state not put back")
	}
}

func TestRedisRestoreRefusesBeforeChangingAnything(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	newRedisHost(t, f, root) // valkey-server --version: 8.1.1
	p := redisPayload()
	p.Engine = "valkey"
	applyOK(t, db, p)
	f.Reset()
	for content, want := range map[string]string{
		"REDIS0012 from Redis 8": "comes from Redis 7.4 or newer",
		"VALKEY080 from 9.0":     "Valkey 8.1.1 can't load",
		"-- PostgreSQL dump":     "no REDIS or VALKEY header",
	} {
		writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), content)
		if _, err := db.Restore(context.Background(), restorePayload("valkey", "/backups/b.rdb.gz"), st); err == nil || !strings.Contains(err.Error(), want) {
			t.Fatalf("%q: %v", content, err)
		}
	}
	// Not gzip although the backup says so.
	os.WriteFile(filepath.Join(root, "/backups/plain.rdb"), []byte("REDIS0011"), 0o600)
	if _, err := db.Restore(context.Background(), restorePayload("valkey", "/backups/plain.rdb"), st); err == nil || !strings.Contains(err.Error(), "gunzip") {
		t.Fatal(err)
	}
	if f.Ran("systemctl stop") || f.Ran("systemctl start") || exists(filepath.Join(root, "/var/lib/falak-valkey/cache/.falak-restore.rdb")) {
		t.Fatal(f.Lines())
	}
	// An instance that doesn't exist; a checksum that doesn't match.
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011")
	q := restorePayload("valkey", "/backups/b.rdb.gz")
	q.Database = "sessions"
	if _, err := db.Restore(context.Background(), q, st); err == nil || !strings.Contains(err.Error(), `instance "sessions" does not exist`) {
		t.Fatal(err)
	}
	q = restorePayload("valkey", "/backups/b.rdb.gz")
	q.SHA256 = strings.Repeat("a", 64)
	if _, err := db.Restore(context.Background(), q, st); err == nil || !strings.Contains(err.Error(), "sha256 mismatch") {
		t.Fatal(err)
	}
}

func TestRedisRestoreWithoutPersistenceKeepsNothingOnDisk(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	p.Persistence = "none"
	applyOK(t, db, p)
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0010 data")
	if _, err := db.Restore(context.Background(), restorePayload("redis", "/backups/b.rdb.gz"), st); err != nil {
		t.Fatal(err)
	}
	if proc := h.procs["redis-server@falak-cache.service"]; proc.loaded != "REDIS0010 data" {
		t.Fatalf("%+v", proc)
	}
	if exists(filepath.Join(root, "/var/lib/falak-redis/cache/dump.rdb")) {
		t.Fatal("dump.rdb left for an instance without persistence")
	}
}

// A real redis-server's snapshot through redis-cli --rdb has the header the agent checks (skipped without one on PATH).
func TestRedisSnapshotOfARealServer(t *testing.T) {
	server, err1 := exec.LookPath("redis-server")
	cli, err2 := exec.LookPath("redis-cli")
	if err1 != nil || err2 != nil {
		t.Skip("redis-server / redis-cli not installed")
	}
	dir := t.TempDir()
	port := 30000 + int(time.Now().UnixNano()%5000)
	cmd := exec.Command(server, "--port", strconv.Itoa(port), "--bind", "127.0.0.1", "--save", "", "--dir", dir, "--requirepass", "Xk3pQ9vR2mT7wL4nB8cF6hJ1")
	if err := cmd.Start(); err != nil {
		t.Skip(err)
	}
	defer cmd.Process.Kill()
	k, _ := kvEngineFor("redis")
	db := New(Deps{Runner: runner.Exec{}, FS: hostfs.FS{Root: dir}, TempDir: t.TempDir()})
	c := conn{db: db, k: k, port: port, password: "Xk3pQ9vR2mT7wL4nB8cF6hJ1"}
	if err := c.ready(context.Background()); err != nil {
		t.Skip("redis-server did not come up: ", err)
	}
	if _, err := c.do(context.Background(), "SET", "a", "1"); err != nil {
		t.Fatal(err)
	}
	out := filepath.Join(t.TempDir(), "snap.rdb")
	res, err := db.d.Runner.Run(context.Background(), runner.Cmd{Name: cli, Args: []string{"-h", "127.0.0.1", "-p", strconv.Itoa(port), "--no-auth-warning", "--rdb", out}, Env: []string{"REDISCLI_AUTH=Xk3pQ9vR2mT7wL4nB8cF6hJ1"}})
	if err != nil || res.ExitCode != 0 {
		t.Fatalf("%v %s", err, res.Stderr)
	}
	h, err := readRDBHeader(out)
	if err != nil || h.magic != "REDIS" || h.version < 9 {
		t.Fatalf("%v %v", h, err)
	}
	t.Logf("%s writes %s", strings.TrimSpace(string(must(exec.Command(server, "--version").Output()))), h)
}

func must(b []byte, _ error) []byte { return b }

// A unit waiting for its automatic restart is not active (is-active says "activating"), yet it must be stopped: the
// stop cancels the pending restart, which would otherwise start the instance in the middle of the file swap.
func TestRedisRestoreAlwaysStopsTheUnit(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	applyOK(t, db, redisPayload())
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011 restored data")
	delete(h.procs, "redis-server@falak-cache.service") // crashed, restart pending
	f.Reset()

	if _, err := db.Restore(context.Background(), restorePayload("redis", "/backups/b.rdb.gz"), st); err != nil {
		t.Fatal(err)
	}
	lines := strings.Join(f.Lines(), "\n")
	stop, start := strings.Index(lines, "systemctl stop redis-server@falak-cache.service"), strings.Index(lines, "systemctl start redis-server@falak-cache.service")
	if stop < 0 || start < stop {
		t.Fatal(lines)
	}
	if proc := h.procs["redis-server@falak-cache.service"]; proc == nil || proc.loaded != "REDIS0011 restored data" {
		t.Fatalf("%+v", proc)
	}
}

// A failed stop changes nothing and starts whatever it left again.
func TestRedisRestoreFailedStopStartsTheInstanceAgain(t *testing.T) {
	f := &runnertest.Fake{}
	stops := 0
	f.OnFunc("systemctl stop", func(runnertest.Call) (runner.Result, error) {
		stops++
		return runner.Result{ExitCode: 1, Stderr: []byte("Job for redis-server@falak-cache.service canceled")}, nil
	})
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	applyOK(t, db, redisPayload())
	data := filepath.Join(root, "/var/lib/falak-redis/cache")
	os.WriteFile(filepath.Join(data, "dump.rdb"), []byte("REDIS0009 current"), 0o600)
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011 restored data")
	f.Reset()

	_, err := db.Restore(context.Background(), restorePayload("redis", "/backups/b.rdb.gz"), st)
	if err == nil || !strings.Contains(err.Error(), "stop redis-server@falak-cache.service") || !strings.Contains(err.Error(), "nothing was changed") {
		t.Fatal(err)
	}
	if stops != 1 || !f.Ran("systemctl start redis-server@falak-cache.service") || h.procs["redis-server@falak-cache.service"] == nil {
		t.Fatal(f.Lines())
	}
	if b, _ := os.ReadFile(filepath.Join(data, "dump.rdb")); string(b) != "REDIS0009 current" {
		t.Fatalf("dump.rdb changed: %q", b)
	}
	if entries, _ := os.ReadDir(data); len(entries) != 1 {
		t.Fatal(entries)
	}
}

// The staged snapshot is a new file, never written through a link, its mode set on the descriptor.
func TestStageSnapshotNeverFollowsALink(t *testing.T) {
	dir := t.TempDir()
	src := filepath.Join(dir, "b.rdb.gz")
	writeGzip(t, src, "REDIS0011 data")
	victim := filepath.Join(dir, "victim")
	os.WriteFile(victim, []byte("keep"), 0o644)
	staged := filepath.Join(dir, ".falak-restore.rdb")
	os.Symlink(victim, staged)
	if _, _, _, err := stageSnapshot(src, "gzip", staged, fileOwner{uid: -1, gid: -1}, stageLimits{}); err == nil {
		t.Fatal("wrote through a symlink")
	}
	if b, _ := os.ReadFile(victim); string(b) != "keep" {
		t.Fatalf("%q", b)
	}
	if fi, _ := os.Stat(victim); fi.Mode().Perm() != 0o644 {
		t.Fatal(fi.Mode())
	}
	os.Remove(staged)
	n, h, fi, err := stageSnapshot(src, "gzip", staged, fileOwner{uid: -1, gid: -1}, stageLimits{})
	if err != nil || n != int64(len("REDIS0011 data")) || h.String() != "REDIS0011" || fi.Mode().Perm() != 0o600 {
		t.Fatal(n, h, fi, err)
	}
}

// A staged snapshot swapped for a link while the instance stops is not installed: nothing is chmodded through it and
// the earlier data comes back.
func TestRedisRestoreRefusesAReplacedStagingFile(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	data := filepath.Join(root, "/var/lib/falak-redis/cache")
	victim := filepath.Join(root, "victim")
	os.WriteFile(victim, []byte("REDIS0011 not yours"), 0o644)
	var h *fakeRedisHost
	f.OnFunc("systemctl stop", func(c runnertest.Call) (runner.Result, error) {
		if p := h.procs[c.Args[len(c.Args)-1]]; p != nil && p.save != "" {
			os.WriteFile(filepath.Join(p.dir, "dump.rdb"), []byte("REDIS0009 at stop"), 0o600)
		}
		delete(h.procs, c.Args[len(c.Args)-1])
		staged := filepath.Join(data, ".falak-restore.rdb")
		if exists(staged) {
			os.Remove(staged)
			os.Symlink(victim, staged)
		}
		return runner.Result{}, nil
	})
	h = newRedisHost(t, f, root)
	applyOK(t, db, redisPayload())
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011 restored data")

	_, err := db.Restore(context.Background(), restorePayload("redis", "/backups/b.rdb.gz"), st)
	if err == nil || !strings.Contains(err.Error(), "was replaced") || !strings.Contains(err.Error(), "the earlier data is back") {
		t.Fatal(err)
	}
	if fi, _ := os.Stat(victim); fi.Mode().Perm() != 0o644 {
		t.Fatal(fi.Mode())
	}
	if b, _ := os.ReadFile(filepath.Join(data, "dump.rdb")); string(b) != "REDIS0009 at stop" {
		t.Fatalf("%q", b)
	}
	if proc := h.procs["redis-server@falak-cache.service"]; proc == nil || proc.loaded != "REDIS0009 at stop" {
		t.Fatalf("%+v", proc)
	}
}

func fakeFreeBytes(t *testing.T, free func(string) int64) {
	t.Helper()
	old := freeBytes
	freeBytes = func(path string) (int64, error) { return free(path), nil }
	t.Cleanup(func() { freeBytes = old })
}

// A disk without room for the gunzipped snapshot (plus the AOF rewrite, plus headroom) is refused before anything
// is written or stopped.
func TestRedisRestoreChecksTheFreeSpaceFirst(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	h := newRedisHost(t, f, root)
	p := redisPayload()
	p.Persistence = "aof"
	applyOK(t, db, p)
	content := "REDIS0011 " + strings.Repeat("x", 1000)
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), content)
	data := filepath.Join(root, "/var/lib/falak-redis/cache")
	var asked string
	free := RedisRestoreHeadroom + 2*int64(len(content)) - 1 // one byte short: AOF needs the snapshot twice
	fakeFreeBytes(t, func(path string) int64 { asked = path; return free })
	f.Reset()

	q := restorePayload("redis", "/backups/b.rdb.gz")
	_, err := db.Restore(context.Background(), q, st)
	if err == nil || !strings.Contains(err.Error(), "not enough free space in /var/lib/falak-redis/cache") || !strings.Contains(err.Error(), "as much again for the AOF rewrite") || !strings.Contains(err.Error(), "nothing was changed") {
		t.Fatal(err)
	}
	if asked != data || f.Ran("systemctl stop") || exists(filepath.Join(data, ".falak-restore.rdb")) || h.procs["redis-server@falak-cache.service"] == nil {
		t.Fatal(asked, f.Lines())
	}
	// The recorded size wins over the trailer.
	free++
	q.UncompressedBytes = int64(len(content)) + 1
	if _, err := db.Restore(context.Background(), q, st); err == nil || !strings.Contains(err.Error(), "not enough free space") {
		t.Fatal(err)
	}
	q.UncompressedBytes = 0
	if _, err := db.Restore(context.Background(), q, st); err != nil {
		t.Fatal(err)
	}
}

// A backup that gunzips to more than its recorded size is refused before the instance is touched.
func TestRedisRestoreCapsTheGunzippedCopy(t *testing.T) {
	f := &runnertest.Fake{}
	db, root := newDB(t, f, nil)
	newRedisHost(t, f, root)
	applyOK(t, db, redisPayload())
	fakeFreeBytes(t, func(string) int64 { return 1 << 40 })
	writeGzip(t, filepath.Join(root, "/backups/b.rdb.gz"), "REDIS0011"+strings.Repeat("\x00", 3<<20))
	f.Reset()
	q := restorePayload("redis", "/backups/b.rdb.gz")
	q.UncompressedBytes = 100
	if _, err := db.Restore(context.Background(), q, st); err == nil || !strings.Contains(err.Error(), "gunzips to more than") {
		t.Fatal(err)
	}
	if f.Ran("systemctl stop") || exists(filepath.Join(root, "/var/lib/falak-redis/cache/.falak-restore.rdb")) {
		t.Fatal(f.Lines())
	}
}

func TestSnapshotSize(t *testing.T) {
	dir := t.TempDir()
	gz := filepath.Join(dir, "b.rdb.gz")
	writeGzip(t, gz, "REDIS0011 twelve")
	if n, exact, err := snapshotSize(gz, "gzip", 0); n != 16 || !exact || err != nil {
		t.Fatal(n, exact, err)
	}
	if n, exact, _ := snapshotSize(gz, "gzip", 5000); n != 5000 || !exact {
		t.Fatal(n, exact)
	}
	plain := filepath.Join(dir, "b.rdb")
	os.WriteFile(plain, []byte("REDIS0011"), 0o600)
	if n, exact, _ := snapshotSize(plain, "none", 0); n != 9 || !exact {
		t.Fatal(n, exact)
	}
	if _, _, err := snapshotSize(plain, "gzip", 0); err == nil || !strings.Contains(err.Error(), "gunzip") {
		t.Fatal(err)
	}
	// Too big for the trailer to be trusted: ISIZE 10 on a 5 MB file is 4 GiB + 10 at least, and only a lower bound.
	big := filepath.Join(dir, "big.rdb.gz")
	b := make([]byte, 5_000_000)
	copy(b[len(b)-4:], []byte{10, 0, 0, 0})
	os.WriteFile(big, b, 0o600)
	if n, exact, _ := snapshotSize(big, "gzip", 0); n != 1<<32+10 || exact {
		t.Fatal(n, exact)
	}
	copy(b[len(b)-4:], []byte{0, 0, 0x60, 0}) // 6 MiB: plausible as is
	os.WriteFile(big, b, 0o600)
	if n, exact, _ := snapshotSize(big, "gzip", 0); n != 6<<20 || exact {
		t.Fatal(n, exact)
	}
}

// The copy stops once the disk runs low, whatever the size check expected.
func TestSpaceGuardStopsTheCopyOnALowDisk(t *testing.T) {
	free := int64(1 << 40)
	fakeFreeBytes(t, func(string) int64 { return free })
	var out bytes.Buffer
	g := &spaceGuard{w: &out, dir: "/data"}
	chunk := make([]byte, 32<<20)
	if _, err := g.Write(chunk); err != nil {
		t.Fatal(err)
	}
	free = RedisRestoreHeadroom - 1
	if _, err := g.Write(chunk); err == nil || !strings.Contains(err.Error(), "nearly full") {
		t.Fatal(err)
	}
	if out.Len() != 32<<20 {
		t.Fatal(out.Len())
	}
}
