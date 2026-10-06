package dbhelper

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fakeEntrypoint puts a docker-entrypoint.sh on PATH for exec.LookPath.
func fakeEntrypoint(t *testing.T) string {
	t.Helper()
	bin := t.TempDir()
	p := filepath.Join(bin, "docker-entrypoint.sh")
	os.WriteFile(p, []byte("#!/bin/sh\n"), 0o755)
	t.Setenv("PATH", bin)
	return p
}

func withTLS(t *testing.T, th *testHelper, ca bool) {
	t.Helper()
	dir := filepath.Join(th.Root, defaultTLS)
	os.MkdirAll(dir, 0o755)
	os.WriteFile(filepath.Join(dir, "server.crt"), []byte("CERT"), 0o644)
	os.WriteFile(filepath.Join(dir, "server.key"), []byte("KEY"), 0o644)
	if ca {
		os.WriteFile(filepath.Join(dir, "ca.crt"), []byte("CA"), 0o644)
	}
}

func TestInitPostgres(t *testing.T) {
	entry := fakeEntrypoint(t)
	th := newTestHelper(t, Postgres)
	th.env["FALAK_DB_MEMORY_BYTES"] = "1073741824"
	withTLS(t, th, true)
	var argv0 string
	var argv []string
	th.Exec = func(a0 string, a []string, _ []string) error { argv0, argv = a0, a; return nil }

	if err := th.Init([]string{"-c", "log_statement=all"}); err != nil {
		t.Fatal(err)
	}
	if argv0 != entry {
		t.Errorf("exec %s", argv0)
	}
	if got := strings.Join(argv, " "); got != "docker-entrypoint.sh postgres -c config_file=/etc/falak/db/postgresql.conf -c log_statement=all" {
		t.Errorf("argv %s", got)
	}
	conf, err := os.ReadFile(filepath.Join(th.Root, pgConfPath))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(conf), "shared_buffers = 256MB") || !strings.Contains(string(conf), "ssl_ca_file") {
		t.Errorf("postgresql.conf:\n%s", conf)
	}
	key := filepath.Join(th.Root, tlsDir, "server.key")
	if st, err := os.Stat(key); err != nil || st.Mode().Perm() != 0o600 {
		t.Errorf("installed key: %v %v", st, err)
	}
	if st, err := os.Stat(filepath.Join(th.Root, ConfigDir)); err != nil || st.Mode().Perm() != 0o755 {
		t.Errorf("config dir: %v %v", st, err)
	}
	if st, err := os.Stat(filepath.Join(th.Root, defaultSpool, "wal")); err != nil || st.Mode().Perm() != 0o700 {
		t.Errorf("spool: %v %v", st, err)
	}
	if st, err := os.Stat(filepath.Dir(filepath.Join(th.Root, defaultSpool))); err != nil || st.Mode().Perm() != 0o755 {
		t.Errorf("spool parent: %v %v", st, err)
	}
	if !strings.Contains(th.errOut.String(), "memory 1024 MiB (env)") {
		t.Errorf("stderr %q", th.errOut)
	}
}

func TestInitKV(t *testing.T) {
	fakeEntrypoint(t)
	th := newTestHelper(t, Redis)
	th.env["FALAK_DB_MEMORY_BYTES"] = "536870912"
	th.env["FALAK_DB_SETTINGS"] = `{"tls": false, "persistence": "aof"}`
	var argv []string
	th.Exec = func(_ string, a []string, _ []string) error { argv = a; return nil }
	if err := th.Init(nil); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(argv, " "); got != "docker-entrypoint.sh redis-server "+kvConfPath {
		t.Errorf("argv %s", got)
	}
	acl, _ := os.ReadFile(filepath.Join(th.Root, kvACLPath))
	if strings.Contains(string(acl), "s3cr") || !strings.Contains(string(acl), "user default on #") {
		t.Errorf("acl:\n%s", acl)
	}
	conf, _ := os.ReadFile(filepath.Join(th.Root, kvConfPath))
	if !strings.Contains(string(conf), "appendonly yes") || strings.Contains(string(conf), "tls-port") {
		t.Errorf("server.conf:\n%s", conf)
	}
	if _, err := os.Stat(filepath.Join(th.Root, runDir)); err != nil {
		t.Error(err)
	}
}

func TestInitRefuses(t *testing.T) {
	fakeEntrypoint(t)
	exec := func(string, []string, []string) error { t.Fatal("exec'd"); return nil }

	th := newTestHelper(t, MySQL)
	th.Exec = exec
	th.env["MYSQL_ROOT_PASSWORD"] = "plain"
	if err := th.Init(nil); ExitCode(err) != ExitUsage || strings.Contains(err.Error(), "plain") {
		t.Errorf("password in env: %v", err)
	}

	th = newTestHelper(t, Postgres)
	th.Exec = exec
	delete(th.env, "POSTGRES_PASSWORD_FILE")
	if err := th.Init(nil); ExitCode(err) != ExitUsage {
		t.Errorf("no password file: %v", err)
	}

	th = newTestHelper(t, MariaDB)
	th.Exec = exec
	th.env["FALAK_DB_MEMORY_BYTES"] = "1073741824"
	if err := th.Init(nil); err == nil || !strings.Contains(err.Error(), "server.crt is missing") {
		t.Errorf("tls without a certificate: %v", err)
	}

	th = newTestHelper(t, Valkey)
	th.Exec = exec
	th.env["FALAK_DB_SETTINGS"] = `{"tls": false, "nope": 1}`
	if err := th.Init(nil); ExitCode(err) != ExitUsage {
		t.Errorf("unknown setting: %v", err)
	}
}

func TestDetectMemory(t *testing.T) {
	th := newTestHelper(t, Postgres)
	write := func(p, s string) {
		p = filepath.Join(th.Root, p)
		os.MkdirAll(filepath.Dir(p), 0o755)
		os.WriteFile(p, []byte(s), 0o644)
	}
	write("/proc/meminfo", "MemTotal:        8048576 kB\nMemFree: 1 kB\n")
	if n, src, err := th.detectMemory(); err != nil || n != 8048576<<10 || src != "host" {
		t.Errorf("meminfo: %d %s %v", n, src, err)
	}
	write("/sys/fs/cgroup/memory/memory.limit_in_bytes", "9223372036854771712\n")
	if _, src, _ := th.detectMemory(); src != "host" {
		t.Errorf("v1 unlimited counted as a limit (%s)", src)
	}
	write("/sys/fs/cgroup/memory/memory.limit_in_bytes", "268435456\n")
	if n, src, _ := th.detectMemory(); n != 256<<20 || src != "cgroup" {
		t.Errorf("v1: %d %s", n, src)
	}
	write("/sys/fs/cgroup/memory.max", "max\n")
	if n, _, _ := th.detectMemory(); n != 256<<20 {
		t.Errorf("v2 max should fall through: %d", n)
	}
	write("/sys/fs/cgroup/memory.max", "536870912\n")
	if n, src, _ := th.detectMemory(); n != 512<<20 || src != "cgroup" {
		t.Errorf("v2: %d %s", n, src)
	}
	th.env["FALAK_DB_MEMORY_BYTES"] = "1073741824"
	if n, src, _ := th.detectMemory(); n != 1<<30 || src != "env" {
		t.Errorf("env: %d %s", n, src)
	}
	th.env["FALAK_DB_MEMORY_BYTES"] = "lots"
	if _, _, err := th.detectMemory(); ExitCode(err) != ExitUsage {
		t.Errorf("bad env: %v", err)
	}
}

func TestMain_CLI(t *testing.T) {
	for _, c := range []struct {
		args []string
		code int
		out  string
	}{
		{nil, ExitUsage, ""},
		{[]string{"frobnicate"}, ExitUsage, ""},
		{[]string{"backup"}, ExitUsage, ""},
		{[]string{"version"}, ExitOK, `"engine":"postgres"`},
		{[]string{"health", "--engine", "oracle"}, ExitUsage, ""},
		{[]string{"wal-push"}, ExitUsage, ""},
		{[]string{"wal-fetch", "000000010000000000000001", "pg_wal/RECOVERYXLOG", "--from", "/nowhere"}, ExitNotFound, ""},
		{[]string{"backup", "physical", "--out", "-", "--engine", "redis"}, ExitUnsupported, ""},
		{[]string{"recover", "--wal-dir", "/x", "--engine", "valkey"}, ExitUnsupported, ""},
		{[]string{"config", "render", "--memory-bytes", "1073741824", "--settings", `{"max_connections": 20}`}, ExitOK, `"max_connections":"20"`},
		{[]string{"config", "render", "--memory-bytes", "1"}, ExitUsage, ""},
	} {
		th := newTestHelper(t, Postgres)
		code := Main(context.Background(), th.Helper, c.args)
		if code != c.code {
			t.Errorf("%v: exit %d, want %d (stderr %q)", c.args, code, c.code, th.errOut)
		}
		if !strings.Contains(th.out.String(), c.out) {
			t.Errorf("%v: stdout %q lacks %q", c.args, th.out, c.out)
		}
	}

	th := newTestHelper(t, "")
	if code := Main(context.Background(), th.Helper, []string{"health"}); code != ExitUsage || !strings.Contains(th.errOut.String(), "FALAK_DB_ENGINE") {
		t.Errorf("no engine: %d %q", code, th.errOut)
	}
}
