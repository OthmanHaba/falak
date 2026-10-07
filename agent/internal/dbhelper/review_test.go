package dbhelper

import (
	"context"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"
)

func TestBinlogGaps(t *testing.T) {
	for _, c := range []struct {
		name  string
		logs  []string
		last  string
		kinds string
	}{
		{"first run", []string{"binlog.000001", "binlog.000002"}, "", ""},
		{"contiguous", []string{"binlog.000003", "binlog.000004", "binlog.000005"}, "binlog.000003", ""},
		{"only the current", []string{"binlog.000006"}, "binlog.000005", ""},
		{"expired before spooled", []string{"binlog.000008", "binlog.000009"}, "binlog.000005", "missing"},
		{"hole in the middle", []string{"binlog.000006", "binlog.000008", "binlog.000009"}, "binlog.000005", "missing"},
		{"numbering reset", []string{"binlog.000001", "binlog.000002"}, "binlog.000040", "reset"},
		{"current equals last", []string{"binlog.000040"}, "binlog.000040", "reset"},
		{"renamed series", []string{"mysql-bin.000041"}, "binlog.000040", "reset"},
	} {
		gaps, _ := binlogGaps(c.logs, c.last)
		var kinds []string
		for _, g := range gaps {
			kinds = append(kinds, g.Kind)
			if g.Detail == "" {
				t.Errorf("%s: gap without detail", c.name)
			}
		}
		if strings.Join(kinds, ",") != c.kinds {
			t.Errorf("%s: gaps %+v, want %s", c.name, gaps, c.kinds)
		}
	}
	gaps, _ := binlogGaps([]string{"binlog.000008", "binlog.000009"}, "binlog.000005")
	if gaps[0].From != "binlog.000006" || gaps[0].To != "binlog.000007" {
		t.Errorf("missing range %+v", gaps[0])
	}
}

func binlogHelper(t *testing.T, logs *string) *testHelper {
	th := newTestHelper(t, MySQL)
	th.run.handle = func(c Cmd) (string, error) {
		switch c.Args[len(c.Args)-1] {
		case "SHOW BINARY LOGS":
			return *logs, nil
		case "SELECT @@log_bin_basename":
			return "/var/lib/mysql/binlog\n", nil
		}
		return "", nil
	}
	os.MkdirAll(filepath.Join(th.Root, "var/lib/mysql"), 0o700)
	return th
}

func TestBinlogRotateReportsGaps(t *testing.T) {
	logs := ""
	th := binlogHelper(t, &logs)
	data := filepath.Join(th.Root, "var/lib/mysql")
	spool := filepath.Join(th.Root, defaultSpool)
	os.MkdirAll(spool, 0o700)
	os.WriteFile(filepath.Join(spool, binlogPositionFile), []byte("binlog.000005\n"), 0o600)

	// 6 and 7 expired before they were spooled: 8 is still spooled, exit 4, gaps in the result.
	writeTemp(t, data, "binlog.000008", "eight")
	logs = "binlog.000008\t1\tNo\nbinlog.000009\t1\tNo\n"
	err := th.BinlogRotate(context.Background(), true, false)
	if ExitCode(err) != ExitConflict {
		t.Fatalf("exit %d (%v)", ExitCode(err), err)
	}
	res := decode(t, th.out.String())
	if len(res["gaps"].([]any)) != 1 || len(res["spooled"].([]any)) != 1 {
		t.Errorf("result %v", res)
	}
	// Reported once: the next run continues from 8.
	th.out.Reset()
	if err := th.BinlogRotate(context.Background(), true, false); err != nil {
		t.Errorf("after the gap was reported: %v", err)
	}

	// Reset: nothing spooled, exit 4, until --restart.
	writeTemp(t, data, "binlog.000001", "new one")
	logs = "binlog.000001\t1\tNo\nbinlog.000002\t1\tNo\n"
	th.out.Reset()
	if err := th.BinlogRotate(context.Background(), true, false); ExitCode(err) != ExitConflict {
		t.Fatalf("reset: %v", err)
	}
	res = decode(t, th.out.String())
	if res["gaps"].([]any)[0].(map[string]any)["kind"] != "reset" || len(res["spooled"].([]any)) != 0 {
		t.Errorf("reset result %v", res)
	}
	th.out.Reset()
	if err := th.BinlogRotate(context.Background(), true, true); err != nil {
		t.Fatalf("--restart: %v", err)
	}
	if res := decode(t, th.out.String()); len(res["spooled"].([]any)) != 1 || len(res["gaps"].([]any)) != 0 {
		t.Errorf("restart result %v", res)
	}
}

func TestRecoverWALDirIsRestricted(t *testing.T) {
	th := newTestHelper(t, Postgres)
	dir := filepath.Join(th.Root, "var/lib/postgresql/data")
	os.MkdirAll(dir, 0o700)
	os.WriteFile(filepath.Join(dir, "backup_label"), []byte("x"), 0o600)
	for _, d := range []string{"/", "/etc", "/var/lib/postgresql/data", "/replay/../etc", "/replayx", "/replay/", "/var/lib/falak/db"} {
		os.MkdirAll(filepath.Join(th.Root, d), 0o700)
		if err := th.Recover(context.Background(), RecoverOptions{WALDir: d}); ExitCode(err) != ExitUsage {
			t.Errorf("--wal-dir %s: %v", d, err)
		}
	}
	for _, d := range []string{"/replay", "/replay/wal", defaultSpool + "/wal"} {
		os.MkdirAll(filepath.Join(th.Root, d), 0o700)
		if err := th.Recover(context.Background(), RecoverOptions{WALDir: d}); err != nil {
			t.Errorf("--wal-dir %s: %v", d, err)
		}
	}
	// Recovery happens on the next start: a target-less recovery takes no action.
	if err := th.Recover(context.Background(), RecoverOptions{WALDir: "/replay", Action: "pause"}); ExitCode(err) != ExitUsage {
		t.Errorf("--action without --target-time: %v", err)
	}

	th = newTestHelper(t, MySQL)
	if err := th.Recover(context.Background(), RecoverOptions{BinlogDir: "/etc"}); ExitCode(err) != ExitUsage {
		t.Errorf("--binlog-dir /etc: %v", err)
	}
}

func TestRecoverChownsOnlyWALFiles(t *testing.T) {
	th := newTestHelper(t, Postgres)
	dir := filepath.Join(th.Root, "var/lib/postgresql/data")
	os.MkdirAll(dir, 0o700)
	os.WriteFile(filepath.Join(dir, "backup_label"), []byte("x"), 0o600)
	wal := filepath.Join(th.Root, "replay/wal")
	os.MkdirAll(filepath.Join(wal, "sub"), 0o700)
	for _, n := range []string{"000000010000000000000003", "00000002.history", "000000010000000000000003.00000028.backup",
		"000000010000000000000004.partial", "notes.txt", "sub/000000010000000000000005"} {
		writeTemp(t, wal, n, "x")
	}
	os.Symlink("/etc/passwd", filepath.Join(wal, "000000010000000000000006"))
	var chowned []string
	th.Chown = func(p string, _, _ int) error {
		rel, _ := filepath.Rel(wal, p)
		chowned = append(chowned, rel)
		return nil
	}
	th.LookupUser = func(string) (int, int, error) { return 999, 999, nil }
	if err := th.Recover(context.Background(), RecoverOptions{WALDir: "/replay/wal"}); err != nil {
		t.Fatal(err)
	}
	var walOnes []string
	for _, c := range chowned {
		if !strings.HasPrefix(c, "..") {
			walOnes = append(walOnes, c)
		}
	}
	sort.Strings(walOnes)
	want := ". 000000010000000000000003 000000010000000000000003.00000028.backup 000000010000000000000004.partial 00000002.history"
	if strings.Join(walOnes, " ") != want {
		t.Errorf("chowned %v", walOnes)
	}
}

func TestMySQLRecoverRoundsTheTargetDown(t *testing.T) {
	th := newTestHelper(t, MariaDB)
	data := filepath.Join(th.Root, "var/lib/mysql")
	os.MkdirAll(data, 0o700)
	writeTemp(t, data, "mariadb_backup_binlog_info", "binlog.000002\t379\t0-1-5\n")
	os.MkdirAll(filepath.Join(th.Root, "replay"), 0o700)
	writeTemp(t, filepath.Join(th.Root, "replay"), "binlog.000002", "x")
	if err := th.Recover(context.Background(), RecoverOptions{BinlogDir: "/replay", TargetTime: "2026-10-07T12:00:05.999Z"}); err != nil {
		t.Fatal(err)
	}
	cmd, _ := th.run.call("mariadb-binlog")
	if !strings.Contains(strings.Join(cmd.Args, " "), "--stop-datetime=2026-10-07 12:00:05 ") {
		t.Errorf("mariadb-binlog %v", cmd.Args)
	}
	apply, _ := th.run.call("mariadb")
	if strings.Join(apply.Args[1:], " ") != "--binary-mode --sandbox" {
		t.Errorf("mariadb %v", apply.Args)
	}
	if res := decode(t, th.out.String()); res["target_time"] != "2026-10-07T12:00:05Z" {
		t.Errorf("result %v", res)
	}
}

func TestManifestStopWALOnASegmentBoundary(t *testing.T) {
	m := func(end string) []byte {
		return []byte(`"WAL-Ranges": [ { "Timeline": 1, "Start-LSN": "0/3000028", "End-LSN": "` + end + `" } ]`)
	}
	for end, want := range map[string]string{
		"0/4000000":  "000000010000000000000003",
		"0/4000001":  "000000010000000000000004",
		"0/3000120":  "000000010000000000000003",
		"1/00000000": "0000000100000000000000FF",
	} {
		if got, err := manifestStopWAL(m(end), 16<<20); err != nil || got != want {
			t.Errorf("end %s: %s, %v; want %s", end, got, err, want)
		}
	}
}

func TestReadPasswordRefusesControlCharacters(t *testing.T) {
	dir := t.TempDir()
	for name, content := range map[string]string{
		"inner newline": "abc\ndef\n", "carriage return": "abc\rdef", "nul": "abc\x00def", "only newlines": "\n\n",
	} {
		p := writeTemp(t, dir, strings.ReplaceAll(name, " ", "-"), content)
		env := Env(func(k string) string {
			if k == "FALAK_DB_PASSWORD_FILE" {
				return p
			}
			return ""
		})
		if _, err := readPassword(Redis, env); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	p := writeTemp(t, dir, "ok", "fine pass\r\n")
	if pw, err := readPassword(Redis, func(k string) string {
		if k == "FALAK_DB_PASSWORD_FILE" {
			return p
		}
		return ""
	}); err != nil || pw != "fine pass" {
		t.Errorf("trailing CRLF: %q, %v", pw, err)
	}
}

func TestInitForbidsPasswordHashes(t *testing.T) {
	for _, k := range []string{"MARIADB_ROOT_PASSWORD_HASH", "MARIADB_PASSWORD_HASH"} {
		th := newTestHelper(t, MariaDB)
		th.env[k] = "*ABC"
		if err := th.Init(nil); ExitCode(err) != ExitUsage {
			t.Errorf("%s: %v", k, err)
		}
	}
}

func TestInitMapsFalakPasswordFile(t *testing.T) {
	for _, e := range []Engine{Postgres, MySQL, MariaDB} {
		fakeEntrypoint(t)
		th := newTestHelper(t, e)
		pw := th.env[entrypointVar[e]]
		delete(th.env, entrypointVar[e])
		th.env["FALAK_DB_PASSWORD_FILE"] = pw
		th.env["FALAK_DB_SETTINGS"] = `{"tls": false}`
		th.env["FALAK_DB_MEMORY_BYTES"] = "1073741824"
		var env []string
		th.Exec = func(_ string, _ []string, e []string) error { env = e; return nil }
		if err := th.Init(nil); err != nil {
			t.Fatalf("%s: %v", e, err)
		}
		if env[len(env)-1] != entrypointVar[e]+"="+pw {
			t.Errorf("%s: entrypoint env ends with %q", e, env[len(env)-1])
		}
	}
	// Set explicitly: left alone.
	th := newTestHelper(t, Postgres)
	th.env["FALAK_DB_PASSWORD_FILE"] = "/other"
	if got := th.entrypointEnv([]string{"A=1"}); len(got) != 1 {
		t.Errorf("env %v", got)
	}
}

func TestInitDropsAFinishedRecovery(t *testing.T) {
	fakeEntrypoint(t)
	th := newTestHelper(t, Postgres)
	th.env["FALAK_DB_SETTINGS"] = `{"tls": false}`
	th.env["FALAK_DB_MEMORY_BYTES"] = "1073741824"
	th.Exec = func(string, []string, []string) error { return nil }
	dir := filepath.Join(th.Root, "var/lib/postgresql/data")
	os.MkdirAll(dir, 0o700)
	conf := writeTemp(t, dir, recoveryConf, "restore_command = 'x'")
	signal := writeTemp(t, dir, "recovery.signal", "")

	// Still recovering: kept.
	if err := th.Init(nil); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(conf); err != nil {
		t.Error("removed during recovery")
	}
	// Promoted (postgres removed the signal): dropped.
	os.Remove(signal)
	if err := th.Init(nil); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(conf); !os.IsNotExist(err) {
		t.Error("left after recovery")
	}
}

func TestKVDataDir(t *testing.T) {
	files, _, err := Render(RenderInput{Engine: Redis, MemoryBytes: 1 << 30, PasswordSHA256: testHash, DataDir: "/srv/redis"})
	if err != nil || !strings.Contains(files[0].Content, "\ndir /srv/redis\n") {
		t.Errorf("dir: %v\n%s", err, files[0].Content)
	}
	if _, _, err := Render(RenderInput{Engine: Redis, MemoryBytes: 1 << 30, PasswordSHA256: testHash, DataDir: "/x y"}); ExitCode(err) != ExitUsage {
		t.Errorf("unsafe dir: %v", err)
	}
}

func TestBackupToolCredentialsFile(t *testing.T) {
	shm := t.TempDir()
	old := shmDir
	shmDir = shm
	defer func() { shmDir = old }()

	th := newTestHelper(t, MySQL)
	var content string
	var mode, dirMode os.FileMode
	th.run.handle = func(c Cmd) (string, error) {
		f := strings.TrimPrefix(c.Args[0], "--defaults-extra-file=")
		if !strings.HasPrefix(f, shm+"/") {
			t.Errorf("credentials file %s outside the tmpfs", f)
		}
		b, _ := os.ReadFile(f)
		st, _ := os.Stat(f)
		dst, _ := os.Stat(filepath.Dir(f))
		content, mode, dirMode = string(b), st.Mode().Perm(), dst.Mode().Perm()
		return "", nil
	}
	if err := th.BackupPhysical(context.Background(), "-"); err != nil {
		t.Fatal(err)
	}
	if mode != 0o600 || dirMode != 0o700 || !strings.Contains(content, "[xtrabackup]\nuser=root") {
		t.Errorf("mode %v dir %v content:\n%s", mode, dirMode, content)
	}
	if left, _ := os.ReadDir(shm); len(left) != 0 {
		t.Errorf("left behind: %v", left)
	}
}

func TestExecFd3(t *testing.T) {
	out, err := output(context.Background(), ExecRunner{}, Cmd{Name: "sh", Args: []string{"-c", "cat /dev/fd/3"}, Fd3: []byte("secret-ini")})
	if err != nil || out != "secret-ini" {
		t.Errorf("fd 3: %q, %v", out, err)
	}
	// A tool that never reads fd 3 does not hang.
	done := make(chan error, 1)
	go func() { done <- ExecRunner{}.Run(context.Background(), Cmd{Name: "true", Fd3: []byte("x")}) }()
	select {
	case err := <-done:
		if err != nil {
			t.Error(err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("hung")
	}
}
