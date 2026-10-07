package dbhelper

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeRunner records commands and answers them through handle.
type fakeRunner struct {
	mu     sync.Mutex
	calls  []Cmd
	stdins map[string]string
	handle func(c Cmd) (string, error)
}

func (f *fakeRunner) Run(_ context.Context, c Cmd) error {
	var in string
	if c.Stdin != nil {
		b, _ := io.ReadAll(c.Stdin)
		in = string(b)
	}
	f.mu.Lock()
	f.calls = append(f.calls, c)
	if f.stdins == nil {
		f.stdins = map[string]string{}
	}
	f.stdins[c.Name] = in
	f.mu.Unlock()
	out, err := "", error(nil)
	if f.handle != nil {
		out, err = f.handle(c)
	}
	if c.Stdout != nil {
		io.WriteString(c.Stdout, out)
	}
	return err
}

func (f *fakeRunner) call(name string) (Cmd, bool) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, c := range f.calls {
		if c.Name == name {
			return c, true
		}
	}
	return Cmd{}, false
}

type testHelper struct {
	*Helper
	run    *fakeRunner
	out    *bytes.Buffer
	errOut *bytes.Buffer
	env    map[string]string
}

func newTestHelper(t *testing.T, e Engine) *testHelper {
	t.Helper()
	root := t.TempDir()
	env := map[string]string{"FALAK_DB_ENGINE": string(e)}
	pw := filepath.Join(root, "run", "secrets", "password")
	os.MkdirAll(filepath.Dir(pw), 0o755)
	os.WriteFile(pw, []byte("s3cr\"et\\pw\n"), 0o600)
	switch e {
	case Postgres:
		env["POSTGRES_PASSWORD_FILE"] = pw
	case MySQL:
		env["MYSQL_ROOT_PASSWORD_FILE"] = pw
	case MariaDB:
		env["MARIADB_ROOT_PASSWORD_FILE"] = pw
	default:
		env["FALAK_DB_PASSWORD_FILE"] = pw
	}
	th := &testHelper{run: &fakeRunner{}, out: &bytes.Buffer{}, errOut: &bytes.Buffer{}, env: env}
	th.Helper = &Helper{
		Engine: e,
		Env:    func(k string) string { return env[k] },
		Run:    th.run,
		Stdin:  strings.NewReader(""),
		Stdout: th.out,
		Stderr: th.errOut,
		Root:   root,
		Now:    func() time.Time { return time.Date(2026, 10, 7, 12, 0, 0, 0, time.UTC) },
		Sleep:  func(time.Duration) {},
	}
	return th
}

func decode(t *testing.T, s string) map[string]any {
	t.Helper()
	var m map[string]any
	if err := json.Unmarshal([]byte(strings.TrimSpace(s)), &m); err != nil {
		t.Fatalf("not one JSON object: %q (%v)", s, err)
	}
	return m
}

func streamResultOf(t *testing.T, stderr string) map[string]any {
	t.Helper()
	for _, l := range strings.Split(stderr, "\n") {
		if strings.HasPrefix(l, ResultPrefix) {
			return decode(t, strings.TrimPrefix(l, ResultPrefix))
		}
	}
	t.Fatalf("no result line in %q", stderr)
	return nil
}

func TestHealthCommands(t *testing.T) {
	for _, c := range []struct {
		engine Engine
		tool   string
		want   []string
	}{
		{Postgres, "pg_isready", []string{"-h", "127.0.0.1", "-p", "5432"}},
		{MySQL, "mysqladmin", []string{"--protocol=TCP", "--get-server-public-key", "ping"}},
		{MariaDB, "mariadb-admin", []string{"--protocol=TCP", "--skip-ssl", "ping"}},
		{Redis, "redis-cli", []string{"-s", kvSocket, "PING"}},
		{Valkey, "valkey-cli", []string{"-s", kvSocket, "PING"}},
	} {
		th := newTestHelper(t, c.engine)
		th.run.handle = func(Cmd) (string, error) { return "PONG\n", nil }
		if err := th.Health(context.Background()); err != nil {
			t.Fatalf("%s: %v", c.engine, err)
		}
		cmd, ok := th.run.call(c.tool)
		if !ok {
			t.Fatalf("%s: %s not run (%v)", c.engine, c.tool, th.run.calls)
		}
		args := strings.Join(cmd.Args, " ")
		for _, w := range c.want {
			if !strings.Contains(args, w) {
				t.Errorf("%s: %s %s lacks %s", c.engine, c.tool, args, w)
			}
		}
		if strings.Contains(args, "s3cr") {
			t.Errorf("%s: the password is in argv", c.engine)
		}
		if decode(t, th.out.String())["status"] != "healthy" {
			t.Errorf("%s: %s", c.engine, th.out)
		}
	}

	th := newTestHelper(t, Redis)
	th.run.handle = func(Cmd) (string, error) { return "LOADING Redis is loading the dataset in memory\n", nil }
	if err := th.Health(context.Background()); err == nil {
		t.Error("LOADING is healthy")
	}
	th = newTestHelper(t, Postgres)
	th.run.handle = func(Cmd) (string, error) { return "", errors.New("exit status 2") }
	if err := th.Health(context.Background()); err == nil || th.out.Len() != 0 {
		t.Errorf("failed probe: %v, stdout %q", err, th.out)
	}
}

func TestKVCredentialsTravelInTheEnvironment(t *testing.T) {
	th := newTestHelper(t, Valkey)
	th.run.handle = func(Cmd) (string, error) { return "PONG", nil }
	if err := th.Health(context.Background()); err != nil {
		t.Fatal(err)
	}
	cmd, _ := th.run.call("valkey-cli")
	if len(cmd.Env) != 1 || cmd.Env[0] != "REDISCLI_AUTH=s3cr\"et\\pw" {
		t.Errorf("env %q", cmd.Env)
	}
}

func TestMySQLCredentialsTravelOnFd3(t *testing.T) {
	th := newTestHelper(t, MariaDB)
	if err := th.Health(context.Background()); err != nil {
		t.Fatal(err)
	}
	cmd, _ := th.run.call("mariadb-admin")
	if cmd.Args[0] != "--defaults-extra-file=/dev/fd/3" {
		t.Errorf("first argument %q", cmd.Args[0])
	}
	content := string(cmd.Fd3)
	if !strings.Contains(content, "[client]\nuser=root\npassword=\"s3cr\\\"et\\\\pw\"\n") || !strings.Contains(content, "[mariadb-backup]") {
		t.Errorf("option file:\n%s", content)
	}
	for _, a := range cmd.Args {
		if strings.Contains(a, "s3cr") {
			t.Errorf("password in argv: %q", a)
		}
	}
	tmp, _ := os.ReadDir(os.TempDir())
	for _, e := range tmp {
		if strings.HasPrefix(e.Name(), "falak-db-") && !e.IsDir() {
			t.Errorf("credentials file in %s: %s", os.TempDir(), e.Name())
		}
	}
}

func TestMySQLSafeClientArgs(t *testing.T) {
	for _, c := range []struct {
		version string
		want    string
		ok      bool
	}{
		{"mysql  Ver 8.4.11 for Linux on aarch64 (MySQL Community Server - GPL)", "--binary-mode --system-command=OFF", true},
		{"mysql  Ver 8.0.46 for Linux on x86_64", "--binary-mode --system-command=OFF", true},
		{"mysql  Ver 8.0.39 for Linux on x86_64", "", false},
		{"mysql  Ver 8.4.2 for Linux on x86_64", "", false},
		{"mysql  Ver 8.3.0 for Linux on x86_64", "", false},
		{"mysql  Ver 9.1.0 for Linux on x86_64", "--binary-mode --system-command=OFF", true},
		{"garbage", "", false},
	} {
		th := newTestHelper(t, MySQL)
		th.run.handle = func(Cmd) (string, error) { return c.version, nil }
		args, err := th.mysqlSafeClientArgs(context.Background())
		if (err == nil) != c.ok || strings.Join(args, " ") != c.want {
			t.Errorf("%q: %v, %v", c.version, args, err)
		}
	}
	th := newTestHelper(t, MariaDB)
	if args, err := th.mysqlSafeClientArgs(context.Background()); err != nil || strings.Join(args, " ") != "--binary-mode --sandbox" {
		t.Errorf("mariadb: %v, %v", args, err)
	}
	if len(th.run.calls) != 0 {
		t.Error("mariadb ran a version probe")
	}
}

func TestRestoreLogicalMySQLIsSandboxed(t *testing.T) {
	th := newTestHelper(t, MySQL)
	th.run.handle = func(c Cmd) (string, error) {
		if len(c.Args) == 1 && c.Args[0] == "--version" {
			return "mysql  Ver 8.4.11 for Linux", nil
		}
		return "", nil
	}
	th.Stdin = strings.NewReader("\\! id\n")
	if err := th.RestoreLogical(context.Background(), "app", "-", false); err != nil {
		t.Fatal(err)
	}
	last := th.run.calls[len(th.run.calls)-1]
	if got := strings.Join(last.Args[1:], " "); got != "--binary-mode --system-command=OFF --default-character-set=utf8mb4 app" {
		t.Errorf("mysql %s", got)
	}

	th = newTestHelper(t, MySQL)
	th.run.handle = func(Cmd) (string, error) { return "mysql  Ver 8.0.30 for Linux", nil }
	if err := th.RestoreLogical(context.Background(), "app", "-", false); err == nil {
		t.Error("restored with a client that cannot disable system commands")
	}

	th = newTestHelper(t, MariaDB)
	if err := th.RestoreLogical(context.Background(), "app", "-", false); err != nil {
		t.Fatal(err)
	}
	cmd, _ := th.run.call("mariadb")
	if got := strings.Join(cmd.Args[1:], " "); got != "--binary-mode --sandbox --default-character-set=utf8mb4 app" {
		t.Errorf("mariadb %s", got)
	}
}

func TestBackupLogical(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.run.handle = func(c Cmd) (string, error) { return "PGDMP-data", nil }
	if err := th.BackupLogical(context.Background(), "app", "-"); err != nil {
		t.Fatal(err)
	}
	if th.out.String() != "PGDMP-data" {
		t.Errorf("stdout %q", th.out)
	}
	cmd, _ := th.run.call("pg_dump")
	if got := strings.Join(cmd.Args, " "); got != "-h /var/run/postgresql -p 5432 -U postgres -Fc -d app" {
		t.Errorf("pg_dump %s", got)
	}
	res := streamResultOf(t, th.errOut.String())
	sum := sha256.Sum256([]byte("PGDMP-data"))
	if res["sha256"] != hex.EncodeToString(sum[:]) || res["bytes"] != float64(10) || res["format"] != "pg_dump-custom" {
		t.Errorf("result %v", res)
	}

	th = newTestHelper(t, MySQL)
	if err := th.BackupLogical(context.Background(), "shop", "-"); err != nil {
		t.Fatal(err)
	}
	cmd, _ = th.run.call("mysqldump")
	got := strings.Join(cmd.Args[1:], " ")
	if got != "--single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --set-gtid-purged=OFF shop" {
		t.Errorf("mysqldump %s", got)
	}

	th = newTestHelper(t, MariaDB)
	if err := th.BackupLogical(context.Background(), "shop", "-"); err != nil {
		t.Fatal(err)
	}
	if cmd, ok := th.run.call("mariadb-dump"); !ok || strings.Contains(strings.Join(cmd.Args, " "), "gtid") {
		t.Errorf("mariadb-dump %v", cmd.Args)
	}

	for _, bad := range []struct {
		engine   Engine
		db, out  string
		wantCode int
	}{
		{Postgres, "", "-", ExitUsage},
		{Postgres, "host=evil", "-", ExitUsage},
		{MySQL, "-e", "-", ExitUsage},
		{MySQL, "app", "/tmp/x", ExitUsage},
		{Redis, "app", "-", ExitUsage},
	} {
		th := newTestHelper(t, bad.engine)
		if err := th.BackupLogical(context.Background(), bad.db, bad.out); ExitCode(err) != bad.wantCode {
			t.Errorf("%s %q %q: %v", bad.engine, bad.db, bad.out, err)
		}
	}
}

func TestKVBackupWaitsForANewSave(t *testing.T) {
	th := newTestHelper(t, Redis)
	data := filepath.Join(th.Root, "data")
	os.MkdirAll(data, 0o755)
	os.WriteFile(filepath.Join(data, "dump.rdb"), []byte("REDIS0011-new"), 0o600)
	infos := []string{
		"# Persistence\r\nrdb_saves:4\r\nrdb_bgsave_in_progress:0\r\nrdb_last_bgsave_status:ok\r\n",
		"rdb_saves:4\r\nrdb_bgsave_in_progress:1\r\nrdb_last_bgsave_status:ok\r\n",
		"rdb_saves:5\r\nrdb_bgsave_in_progress:0\r\nrdb_last_bgsave_status:ok\r\n",
	}
	bgsaves := 0
	th.run.handle = func(c Cmd) (string, error) {
		switch c.Args[len(c.Args)-1] {
		case "persistence":
			out := infos[0]
			if len(infos) > 1 {
				infos = infos[1:]
			}
			return out, nil
		case "BGSAVE":
			bgsaves++
			if bgsaves == 1 {
				return "ERR Background save already in progress", nil
			}
			return "Background saving started", nil
		}
		return "", errors.New("unexpected " + c.String())
	}
	if err := th.BackupLogical(context.Background(), "", "-"); err != nil {
		t.Fatal(err)
	}
	if bgsaves != 2 || th.out.String() != "REDIS0011-new" {
		t.Errorf("bgsaves %d, stdout %q", bgsaves, th.out)
	}
	if streamResultOf(t, th.errOut.String())["format"] != "rdb" {
		t.Error(th.errOut)
	}
}

func TestKVRestoreOffline(t *testing.T) {
	th := newTestHelper(t, Valkey)
	data := filepath.Join(th.Root, "data")
	os.MkdirAll(filepath.Join(data, "appendonlydir"), 0o755)
	th.Stdin = strings.NewReader("REDIS0011restored")
	if err := th.RestoreLogical(context.Background(), "", "-", false); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(data, "dump.rdb")); string(b) != "REDIS0011restored" {
		t.Errorf("dump.rdb %q", b)
	}
	if _, err := os.Stat(filepath.Join(data, "appendonlydir.before-restore-20261007T120000Z")); err != nil {
		t.Errorf("the AOF was not moved aside: %v", err)
	}

	th.Stdin = strings.NewReader("PGDMP not an rdb")
	if err := th.RestoreLogical(context.Background(), "", "-", false); ExitCode(err) != ExitUsage {
		t.Errorf("non-RDB input: %v", err)
	}
}

func TestRestoreLogical(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.Stdin = strings.NewReader("PGDMP")
	if err := th.RestoreLogical(context.Background(), "app", "-", true); err != nil {
		t.Fatal(err)
	}
	cmd, _ := th.run.call("pg_restore")
	if got := strings.Join(cmd.Args, " "); !strings.HasSuffix(got, "-d app --no-owner --no-acl --exit-on-error --clean --if-exists") {
		t.Errorf("pg_restore %s", got)
	}
	if th.run.stdins["pg_restore"] != "PGDMP" {
		t.Errorf("stdin %q", th.run.stdins["pg_restore"])
	}

	th = newTestHelper(t, MySQL)
	if err := th.RestoreLogical(context.Background(), "app", "-", true); ExitCode(err) != ExitUsage {
		t.Errorf("--clean on mysql: %v", err)
	}
}

// pgTar builds a small pg_basebackup-like tar.
func pgTar(t *testing.T, extra ...tar.Header) []byte {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	add := func(name, content string) {
		tw.WriteHeader(&tar.Header{Name: name, Mode: 0o600, Size: int64(len(content)), Typeflag: tar.TypeReg})
		tw.Write([]byte(content))
	}
	tw.WriteHeader(&tar.Header{Name: "base/", Mode: 0o700, Typeflag: tar.TypeDir})
	add("backup_label", "START WAL LOCATION: 0/3000028 (file 000000010000000000000003)\nCHECKPOINT LOCATION: 0/3000080\nLABEL: falak\n")
	add("base/1/1259", strings.Repeat("x", 100))
	add("PG_VERSION", "17\n")
	for _, h := range extra {
		h := h
		tw.WriteHeader(&h)
	}
	add("backup_manifest", `{ "PostgreSQL-Backup-Manifest-Version": 1,
"Files": [],
"WAL-Ranges": [
{ "Timeline": 1, "Start-LSN": "0/3000028", "End-LSN": "1/2A000120" }
],
"Manifest-Checksum": "abc"}
`)
	tw.Close()
	return buf.Bytes()
}

func TestBackupPhysicalPostgres(t *testing.T) {
	th := newTestHelper(t, Postgres)
	archive := pgTar(t)
	th.run.handle = func(c Cmd) (string, error) {
		if c.Name == "psql" {
			return "16777216\n", nil
		}
		return string(archive), nil
	}
	if err := th.BackupPhysical(context.Background(), "-"); err != nil {
		t.Fatal(err)
	}
	cmd, _ := th.run.call("pg_basebackup")
	if got := strings.Join(cmd.Args, " "); !strings.Contains(got, "-D - -Ft -X none --checkpoint=fast -l falak-20261007T120000Z") {
		t.Errorf("pg_basebackup %s", got)
	}
	if !bytes.Equal(th.out.Bytes(), archive) {
		t.Error("the tar was not passed through unchanged")
	}
	res := streamResultOf(t, th.errOut.String())
	if res["start_wal"] != "000000010000000000000003" || res["stop_wal"] != "00000001000000010000002A" {
		t.Errorf("result %v", res)
	}
}

func TestWALFileName(t *testing.T) {
	for _, c := range []struct {
		tli  uint32
		lsn  uint64
		size int64
		want string
	}{
		{1, 0x3000120, 16 << 20, "000000010000000000000003"},
		{2, 0x1_2A000000, 16 << 20, "00000002000000010000002A"},
		{1, 0x1_FFFFFFFF, 16 << 20, "0000000100000001000000FF"},
		{1, 0x50000000, 64 << 20, "000000010000000000000014"},
	} {
		got, err := walFileName(c.tli, c.lsn, c.size)
		if err != nil || got != c.want {
			t.Errorf("walFileName(%d, %X, %d) = %s, %v; want %s", c.tli, c.lsn, c.size, got, err, c.want)
		}
	}
	if _, err := walFileName(1, 1, 1000); err == nil {
		t.Error("a segment size that is not a power of two")
	}
	if _, err := manifestStopWAL([]byte(`{"Files": []}`), 16<<20); err == nil {
		t.Error("a manifest without WAL-Ranges")
	}
}

func TestRestorePhysicalPostgres(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.Stdin = bytes.NewReader(pgTar(t))
	if err := th.RestorePhysical(context.Background(), "-"); err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(th.Root, "var/lib/postgresql/data")
	if b, _ := os.ReadFile(filepath.Join(dir, "base/1/1259")); len(b) != 100 {
		t.Errorf("base/1/1259: %d bytes", len(b))
	}
	if st, _ := os.Stat(dir); st.Mode().Perm() != 0o700 {
		t.Errorf("data dir mode %v", st.Mode())
	}
	res := decode(t, th.out.String())
	if res["start_wal"] != "000000010000000000000003" || res["files"] != float64(4) {
		t.Errorf("result %v", res)
	}

	// Not into a data directory that has anything in it.
	th.Stdin = bytes.NewReader(pgTar(t))
	if err := th.RestorePhysical(context.Background(), "-"); ExitCode(err) != ExitConflict {
		t.Errorf("non-empty data dir: %v", err)
	}
}

func TestRestorePhysicalRejectsUnsafeArchives(t *testing.T) {
	for name, h := range map[string]tar.Header{
		"symlink":   {Name: "pg_tblspc/16384", Linkname: "/etc", Typeflag: tar.TypeSymlink},
		"hard link": {Name: "x", Linkname: "/etc/passwd", Typeflag: tar.TypeLink},
		"traversal": {Name: "../../etc/cron.d/x", Mode: 0o600, Typeflag: tar.TypeReg},
		"absolute":  {Name: "/etc/x", Mode: 0o600, Typeflag: tar.TypeReg},
		"device":    {Name: "dev", Typeflag: tar.TypeChar},
	} {
		th := newTestHelper(t, Postgres)
		th.Stdin = bytes.NewReader(pgTar(t, h))
		if err := th.RestorePhysical(context.Background(), "-"); err == nil {
			t.Errorf("%s: restored", name)
		}
	}
}

func TestRestorePhysicalMySQL(t *testing.T) {
	th := newTestHelper(t, MariaDB)
	if err := th.RestorePhysical(context.Background(), "-"); err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, c := range th.run.calls {
		names = append(names, c.Name+" "+strings.Join(c.Args, " "))
	}
	dir := filepath.Join(th.Root, "var/lib/mysql")
	want := []string{"mbstream -x -C " + dir, "mariadb-backup --prepare --target-dir=" + dir}
	if strings.Join(names, "|") != strings.Join(want, "|") {
		t.Errorf("commands %q", names)
	}
	if th := newTestHelper(t, Redis); ExitCode(th.RestorePhysical(context.Background(), "-")) != ExitUnsupported {
		t.Error("redis physical restore")
	}
}

func TestPostgresRecover(t *testing.T) {
	th := newTestHelper(t, Postgres)
	dir := filepath.Join(th.Root, "var/lib/postgresql/data")
	os.MkdirAll(dir, 0o700)
	os.MkdirAll(filepath.Join(th.Root, "replay/wal"), 0o700)

	if err := th.Recover(context.Background(), RecoverOptions{WALDir: "/replay/wal"}); ExitCode(err) != ExitConflict {
		t.Errorf("without a restored base: %v", err)
	}
	os.WriteFile(filepath.Join(dir, "backup_label"), []byte("START WAL LOCATION"), 0o600)

	for _, bad := range []RecoverOptions{
		{WALDir: "relative"},
		{WALDir: "/replay/wal; rm -rf /"},
		{WALDir: "/replay/wal", Action: "explode"},
		{WALDir: "/replay/wal", TargetTime: "yesterday"},
		{WALDir: "/replay/wal", BinlogDir: "/x"},
	} {
		if err := th.Recover(context.Background(), bad); ExitCode(err) != ExitUsage {
			t.Errorf("%+v: %v", bad, err)
		}
	}

	if err := th.Recover(context.Background(), RecoverOptions{WALDir: "/replay/wal", TargetTime: "2026-10-07T14:30:15.25+02:00"}); err != nil {
		t.Fatal(err)
	}
	conf, _ := os.ReadFile(filepath.Join(dir, recoveryConf))
	for _, want := range []string{
		"restore_command = 'falak-db wal-fetch %f %p --from /replay/wal'\n",
		"recovery_target_time = '2026-10-07 12:30:15.25+00'\n",
		"recovery_target_action = 'promote'\n",
	} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("%s lacks %q:\n%s", recoveryConf, want, conf)
		}
	}
	if _, err := os.Stat(filepath.Join(dir, "recovery.signal")); err != nil {
		t.Error(err)
	}
	if res := decode(t, th.out.String()); res["target_time"] != "2026-10-07T12:30:15.25Z" || res["action"] != "promote" {
		t.Errorf("result %v", res)
	}
}

func TestBinlogsFrom(t *testing.T) {
	dir := t.TempDir()
	for _, n := range []string{"binlog.000003", "binlog.000004", "binlog.000005", "binlog.index", "other.000004", ".binlog-last"} {
		writeTemp(t, dir, n, "x")
	}
	got, err := binlogsFrom(dir, "binlog.000004")
	if err != nil || strings.Join(got, " ") != "binlog.000004 binlog.000005" {
		t.Errorf("binlogsFrom = %v, %v", got, err)
	}
	if _, err := binlogsFrom(dir, "binlog.000002"); err == nil {
		t.Error("start missing")
	}
	os.Remove(filepath.Join(dir, "binlog.000004"))
	if _, err := binlogsFrom(dir, "binlog.000003"); err == nil || !strings.Contains(err.Error(), "binlog.000004 is missing") {
		t.Errorf("gap: %v", err)
	}
}

func TestMySQLRecover(t *testing.T) {
	th := newTestHelper(t, MySQL)
	data := filepath.Join(th.Root, "var/lib/mysql")
	os.MkdirAll(data, 0o700)
	binlogs := filepath.Join(th.Root, "replay")
	os.MkdirAll(binlogs, 0o700)
	writeTemp(t, binlogs, "binlog.000007", "a")
	writeTemp(t, binlogs, "binlog.000008", "b")

	if err := th.Recover(context.Background(), RecoverOptions{BinlogDir: "/replay"}); ExitCode(err) != ExitConflict {
		t.Errorf("without binlog info: %v", err)
	}
	writeTemp(t, data, "xtrabackup_binlog_info", "binlog.000007\t1234\t3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5\n")
	th.run.handle = func(c Cmd) (string, error) {
		if c.Name == "mysqlbinlog" {
			return "BINLOG-SQL", nil
		}
		if len(c.Args) == 1 && c.Args[0] == "--version" {
			return "mysql  Ver 8.0.46 for Linux", nil
		}
		return "", nil
	}
	if err := th.Recover(context.Background(), RecoverOptions{BinlogDir: "/replay", TargetTime: "2026-10-07T12:00:00+03:00"}); err != nil {
		t.Fatal(err)
	}
	cmd, _ := th.run.call("mysqlbinlog")
	want := "--start-position=1234 --stop-datetime=2026-10-07 09:00:00 " + filepath.Join(binlogs, "binlog.000007") + " " + filepath.Join(binlogs, "binlog.000008")
	if got := strings.Join(cmd.Args, " "); got != want {
		t.Errorf("mysqlbinlog %s\nwant %s", got, want)
	}
	if len(cmd.Env) != 1 || cmd.Env[0] != "TZ=UTC" {
		t.Errorf("mysqlbinlog env %v", cmd.Env)
	}
	if th.run.stdins["mysql"] != "BINLOG-SQL" {
		t.Errorf("mysql read %q", th.run.stdins["mysql"])
	}
	if err := th.Recover(context.Background(), RecoverOptions{BinlogDir: "/replay", WALDir: "/x"}); ExitCode(err) != ExitUsage {
		t.Errorf("--wal-dir on mysql: %v", err)
	}
}

func TestBinlogRotate(t *testing.T) {
	th := newTestHelper(t, MySQL)
	data := filepath.Join(th.Root, "var/lib/mysql")
	os.MkdirAll(data, 0o700)
	for _, n := range []string{"binlog.000001", "binlog.000002", "binlog.000003"} {
		writeTemp(t, data, n, "content of "+n)
	}
	logs := "binlog.000001\t180\tNo\nbinlog.000002\t500\tNo\nbinlog.000003\t157\tNo\n"
	var queries []string
	th.run.handle = func(c Cmd) (string, error) {
		q := c.Args[len(c.Args)-1]
		queries = append(queries, q)
		switch q {
		case "SHOW BINARY LOGS":
			return logs, nil
		case "SELECT @@log_bin_basename":
			return "/var/lib/mysql/binlog\n", nil
		}
		return "", nil
	}
	if err := th.BinlogRotate(context.Background(), false, false); err != nil {
		t.Fatal(err)
	}
	res := decode(t, th.out.String())
	if res["current"] != "binlog.000003" || len(res["spooled"].([]any)) != 2 || queries[0] != "FLUSH BINARY LOGS" {
		t.Errorf("result %v, queries %v", res, queries)
	}
	spool := filepath.Join(th.Root, defaultSpool)
	if b, _ := os.ReadFile(filepath.Join(spool, "binlog", "binlog.000002")); string(b) != "content of binlog.000002" {
		t.Errorf("spooled %q", b)
	}

	// The agent shipped (and removed) the spooled files: they are not spooled again.
	os.RemoveAll(filepath.Join(spool, "binlog"))
	th.out.Reset()
	queries = nil
	if err := th.BinlogRotate(context.Background(), true, false); err != nil {
		t.Fatal(err)
	}
	if res := decode(t, th.out.String()); len(res["spooled"].([]any)) != 0 || queries[0] == "FLUSH BINARY LOGS" {
		t.Errorf("second run: %v, %v", res, queries)
	}

	// A new closed binlog is spooled.
	writeTemp(t, data, "binlog.000004", "four")
	logs += "binlog.000004\t157\tNo\n"
	th.out.Reset()
	if err := th.BinlogRotate(context.Background(), true, false); err != nil {
		t.Fatal(err)
	}
	if res := decode(t, th.out.String()); len(res["spooled"].([]any)) != 1 {
		t.Errorf("third run: %v", res)
	}

	if th := newTestHelper(t, Postgres); ExitCode(th.BinlogRotate(context.Background(), false, false)) != ExitUnsupported {
		t.Error("binlog-rotate on postgres")
	}
}

func TestPromote(t *testing.T) {
	th := newTestHelper(t, Postgres)
	dir := filepath.Join(th.Root, "var/lib/postgresql/data")
	os.MkdirAll(dir, 0o700)
	writeTemp(t, dir, recoveryConf, "restore_command = 'x'")
	th.run.handle = func(Cmd) (string, error) { return "t\n", nil }
	if err := th.Promote(context.Background()); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, recoveryConf)); !os.IsNotExist(err) {
		t.Error("recovery settings left in place")
	}
	th.run.handle = func(Cmd) (string, error) { return "f\n", nil }
	if err := th.Promote(context.Background()); err == nil {
		t.Error("an unfinished promotion succeeded")
	}
	if th := newTestHelper(t, MySQL); ExitCode(th.Promote(context.Background())) != ExitUnsupported {
		t.Error("promote on mysql")
	}
}

func TestWALPushAndFetch(t *testing.T) {
	th := newTestHelper(t, Postgres)
	pgwal := filepath.Join(t.TempDir(), "pg_wal")
	os.MkdirAll(pgwal, 0o700)
	seg := writeTemp(t, pgwal, "000000010000000000000009", "wal 9")
	if err := th.WALPush(seg); err != nil {
		t.Fatal(err)
	}
	if th.out.Len() != 0 {
		t.Errorf("wal-push printed %q", th.out)
	}
	spooled := filepath.Join(th.Root, defaultSpool, "wal", "000000010000000000000009")
	if b, _ := os.ReadFile(spooled); string(b) != "wal 9" {
		t.Errorf("spooled %q", b)
	}
	dst := filepath.Join(pgwal, "RECOVERYXLOG")
	if err := th.WALFetch("000000010000000000000009", dst, defaultSpool+"/wal"); err != nil {
		t.Fatal(err)
	}
	if err := th.WALFetch("00000002.history", dst, defaultSpool+"/wal"); ExitCode(err) != ExitNotFound {
		t.Errorf("missing: %v", err)
	}
	if th := newTestHelper(t, MySQL); ExitCode(th.WALPush(seg)) != ExitUnsupported {
		t.Error("wal-push on mysql")
	}
}
