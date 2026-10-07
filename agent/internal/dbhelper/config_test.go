package dbhelper

import (
	"flag"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

var update = flag.Bool("update", false, "rewrite testdata/golden")

func ptr[T any](v T) *T { return &v }

const testHash = "5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8" // sha256("password")

func TestRenderGolden(t *testing.T) {
	cases := []struct {
		name string
		in   RenderInput
	}{
		{"postgres-1g", RenderInput{Engine: Postgres, MemoryBytes: 1 << 30, DataDir: "/var/lib/postgresql/data", TLSCA: true}},
		{"postgres-256m-notls", RenderInput{Engine: Postgres, MemoryBytes: 256 << 20, DataDir: "/var/lib/postgresql/18/docker",
			Settings: Settings{MaxConnections: 50, TLS: ptr(false), SlowQueryMS: ptr(0)}}},
		{"postgres-16g-require-tls", RenderInput{Engine: Postgres, MemoryBytes: 16 << 30, DataDir: "/var/lib/postgresql/data",
			Settings: Settings{MaxConnections: 400, RequireTLS: true, SlowQueryMS: ptr(250)}}},
		{"mysql-512m", RenderInput{Engine: MySQL, MemoryBytes: 512 << 20, TLSCA: true}},
		{"mysql-8g", RenderInput{Engine: MySQL, MemoryBytes: 8 << 30,
			Settings: Settings{MaxConnections: 500, ServerID: 7, RequireTLS: true, BinlogRetentionHours: 72}}},
		{"mysql-1g-notls", RenderInput{Engine: MySQL, MemoryBytes: 1 << 30, Settings: Settings{TLS: ptr(false), SlowQueryMS: ptr(0)}}},
		{"mariadb-2g", RenderInput{Engine: MariaDB, MemoryBytes: 2 << 30, Settings: Settings{ServerID: 3}}},
		{"mariadb-1g-notls", RenderInput{Engine: MariaDB, MemoryBytes: 1 << 30, Settings: Settings{TLS: ptr(false)}}},
		{"redis-512m", RenderInput{Engine: Redis, MemoryBytes: 512 << 20, PasswordSHA256: testHash, TLSCA: true}},
		{"redis-512m-require-tls", RenderInput{Engine: Redis, MemoryBytes: 512 << 20, PasswordSHA256: testHash, TLSCA: true,
			Settings: Settings{RequireTLS: true}}},
		{"redis-1g-aof-notls", RenderInput{Engine: Redis, MemoryBytes: 1 << 30, PasswordSHA256: testHash,
			Settings: Settings{TLS: ptr(false), Persistence: "aof", Eviction: "allkeys-lru", Bind: []string{"0.0.0.0"}}}},
		{"valkey-256m-none", RenderInput{Engine: Valkey, MemoryBytes: 256 << 20, PasswordSHA256: testHash,
			Settings: Settings{Persistence: "none", SlowQueryMS: ptr(0)}}},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			files, tuning, err := Render(c.in)
			if err != nil {
				t.Fatal(err)
			}
			var b strings.Builder
			for _, f := range files {
				b.WriteString("==> " + f.Path + " (" + f.Mode.String() + ")\n")
				b.WriteString(f.Content)
			}
			b.WriteString("==> tuning\n")
			for _, k := range sortedKeys(tuning) {
				b.WriteString(k + " = " + tuning[k] + "\n")
			}
			path := filepath.Join("testdata", "golden", c.name+".txt")
			if *update {
				if err := os.WriteFile(path, []byte(b.String()), 0o644); err != nil {
					t.Fatal(err)
				}
			}
			want, err := os.ReadFile(path)
			if err != nil {
				t.Fatalf("%v (run go test -update)", err)
			}
			if got := b.String(); got != string(want) {
				t.Errorf("%s differs from the golden file (run go test -update and review the diff):\n%s", c.name, got)
			}
		})
	}
}

func sortedKeys(m Tuning) []string {
	var keys []string
	for k := range m {
		keys = append(keys, k)
	}
	for i := range keys {
		for j := i + 1; j < len(keys); j++ {
			if keys[j] < keys[i] {
				keys[i], keys[j] = keys[j], keys[i]
			}
		}
	}
	return keys
}

func TestRenderTuning(t *testing.T) {
	cases := []struct {
		engine Engine
		mem    int64
		key    string
		want   string
	}{
		{Postgres, 512 << 20, "shared_buffers", "128MB"},
		{Postgres, 512 << 20, "effective_cache_size", "384MB"},
		{Postgres, 4 << 30, "shared_buffers", "1024MB"},
		{Postgres, 64 << 20, "work_mem", "4MB"},
		{Postgres, 64 << 30, "work_mem", "122MB"},
		{Postgres, 64 << 30, "maintenance_work_mem", "2048MB"},
		{MySQL, 512 << 20, "innodb_buffer_pool_size", "281M"},
		// 4 GiB: 55% = 2252 MiB, rounded down to whole 2 instances x 128 MiB chunks. MariaDB sizes chunks itself.
		{MySQL, 4 << 30, "innodb_buffer_pool_size", "2048M"},
		{MariaDB, 4 << 30, "innodb_buffer_pool_size", "2252M"},
		{Redis, 1 << 30, "maxmemory", "858993459"},
	}
	for _, c := range cases {
		in := RenderInput{Engine: c.engine, MemoryBytes: c.mem, DataDir: "/d", PasswordSHA256: testHash}
		_, tuning, err := Render(in)
		if err != nil {
			t.Fatal(err)
		}
		if tuning[c.key] != c.want {
			t.Errorf("%s %d MiB: %s = %s, want %s", c.engine, c.mem>>20, c.key, tuning[c.key], c.want)
		}
	}
}

func TestInnodbPool(t *testing.T) {
	for _, c := range []struct{ mem, pool, instances, chunk int64 }{
		{64, 35, 1, 35},
		{512, 281, 1, 281},
		{1862, 1024, 1, 1024},
		{4096, 2048, 2, 128},
		{65536, 35840, 8, 128},
	} {
		pool, instances, chunk := innodbPool(c.mem)
		if pool != c.pool || instances != c.instances || chunk != c.chunk {
			t.Errorf("innodbPool(%d) = %d, %d, %d; want %d, %d, %d", c.mem, pool, instances, chunk, c.pool, c.instances, c.chunk)
		}
		if pool%(instances*chunk) != 0 {
			t.Errorf("innodbPool(%d): pool %d is not a multiple of %d x %d", c.mem, pool, instances, chunk)
		}
	}
}

func TestRenderRefuses(t *testing.T) {
	cases := []struct {
		name string
		in   RenderInput
	}{
		{"tiny memory", RenderInput{Engine: Postgres, MemoryBytes: 32 << 20, DataDir: "/d"}},
		{"postgres without data dir", RenderInput{Engine: Postgres, MemoryBytes: 1 << 30}},
		{"redis without password", RenderInput{Engine: Redis, MemoryBytes: 1 << 30}},
		{"eviction on postgres", RenderInput{Engine: Postgres, MemoryBytes: 1 << 30, DataDir: "/d", Settings: Settings{Eviction: "allkeys-lru"}}},
		{"server_id on postgres", RenderInput{Engine: Postgres, MemoryBytes: 1 << 30, DataDir: "/d", Settings: Settings{ServerID: 2}}},
		{"max_connections on redis", RenderInput{Engine: Redis, MemoryBytes: 1 << 30, PasswordSHA256: testHash, Settings: Settings{MaxConnections: 10}}},
		{"unknown eviction", RenderInput{Engine: Redis, MemoryBytes: 1 << 30, PasswordSHA256: testHash, Settings: Settings{Eviction: "lru"}}},
		{"unknown persistence", RenderInput{Engine: Valkey, MemoryBytes: 1 << 30, PasswordSHA256: testHash, Settings: Settings{Persistence: "both"}}},
		{"bind injection", RenderInput{Engine: Redis, MemoryBytes: 1 << 30, PasswordSHA256: testHash, Settings: Settings{Bind: []string{"0.0.0.0\nrename-command"}}}},
		{"require_tls without tls", RenderInput{Engine: MySQL, MemoryBytes: 1 << 30, Settings: Settings{TLS: ptr(false), RequireTLS: true}}},
		{"negative slow log", RenderInput{Engine: MySQL, MemoryBytes: 1 << 30, Settings: Settings{SlowQueryMS: ptr(-1)}}},
		{"unknown engine", RenderInput{Engine: "oracle", MemoryBytes: 1 << 30}},
	}
	for _, c := range cases {
		if _, _, err := Render(c.in); err == nil {
			t.Errorf("%s: rendered", c.name)
		} else if ExitCode(err) != ExitUsage {
			t.Errorf("%s: exit code %d, want %d (%v)", c.name, ExitCode(err), ExitUsage, err)
		}
	}
}

func TestParseSettings(t *testing.T) {
	s, err := ParseSettings([]byte(`{"max_connections": 20, "tls": false, "slow_query_ms": 0}`))
	if err != nil {
		t.Fatal(err)
	}
	if s.MaxConnections != 20 || s.tls() || s.slowMS(Postgres) != 0 {
		t.Errorf("parsed %+v", s)
	}
	if s, err := ParseSettings(nil); err != nil || !s.tls() || s.slowMS(Postgres) != 1000 || s.slowMS(Redis) != 10 {
		t.Errorf("defaults: %+v, %v", s, err)
	}
	for _, bad := range []string{`{"max_connection": 1}`, `{"tls": "yes"}`, `{} {}`, `[`} {
		if _, err := ParseSettings([]byte(bad)); ExitCode(err) != ExitUsage {
			t.Errorf("%s: err = %v", bad, err)
		}
	}
}

func TestPostgresArchiveCommandIsFixed(t *testing.T) {
	files, _, err := Render(RenderInput{Engine: Postgres, MemoryBytes: 1 << 30, DataDir: "/var/lib/postgresql/data"})
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"archive_mode = on\n", "archive_command = 'falak-db wal-push %p'\n", "archive_timeout = 60\n", "wal_level = replica\n",
		"include_if_exists = '/var/lib/postgresql/data/falak-recovery.conf'\n",
	} {
		if !strings.Contains(files[0].Content, want) {
			t.Errorf("postgresql.conf lacks %q", want)
		}
	}
}

func TestKVACLHoldsOnlyTheHash(t *testing.T) {
	files, _, err := Render(RenderInput{Engine: Valkey, MemoryBytes: 1 << 30, PasswordSHA256: testHash})
	if err != nil {
		t.Fatal(err)
	}
	if files[1].Path != kvACLPath || files[1].Mode != 0o600 || !strings.Contains(files[1].Content, "#"+testHash) {
		t.Errorf("acl file: %+v", files[1])
	}
	if strings.Contains(files[0].Content, "requirepass") {
		t.Error("server.conf has requirepass")
	}
}
