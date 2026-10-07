package dbhelper

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// recordSQL answers every psql / mysql call with answer(sql) and records the SQL in order.
func recordSQL(th *testHelper, answer func(sql string) (string, error)) *[]string {
	var sqls []string
	th.run.handle = func(c Cmd) (string, error) {
		sql := th.run.stdins[c.Name]
		if c.Name == "pg_restore" {
			sqls = append(sqls, "pg_restore "+strings.Join(c.Args, " "))
			return "", nil
		}
		sqls = append(sqls, sql)
		return answer(sql)
	}
	return &sqls
}

func TestReadOnlyPostgresEndsSessions(t *testing.T) {
	th := newTestHelper(t, Postgres)
	sqls := recordSQL(th, func(sql string) (string, error) {
		if strings.HasPrefix(sql, "SELECT datname") {
			return "app\nshop\n", nil
		}
		return "", nil
	})
	if err := th.ReadOnly(context.Background(), true); err != nil {
		t.Fatal(err)
	}
	last := (*sqls)[len(*sqls)-1]
	for _, want := range []string{`ALTER DATABASE "app" SET default_transaction_read_only = on;`, `ALTER DATABASE "shop" SET default_transaction_read_only = on;`, "pg_terminate_backend"} {
		if !strings.Contains(last, want) {
			t.Errorf("missing %q in %q", want, last)
		}
	}
	th = newTestHelper(t, Postgres)
	sqls = recordSQL(th, func(sql string) (string, error) { return "app\n", nil })
	if err := th.ReadOnly(context.Background(), false); err != nil {
		t.Fatal(err)
	}
	if last := (*sqls)[len(*sqls)-1]; !strings.Contains(last, "= off") || strings.Contains(last, "pg_terminate_backend") {
		t.Errorf("off: %q", last)
	}
}

func TestReadOnlyMySQL(t *testing.T) {
	th := newTestHelper(t, MySQL)
	sqls := recordSQL(th, func(string) (string, error) { return "", nil })
	if err := th.ReadOnly(context.Background(), true); err != nil {
		t.Fatal(err)
	}
	if (*sqls)[0] != "SET GLOBAL super_read_only = ON;" {
		t.Errorf("sql %q", *sqls)
	}
	th = newTestHelper(t, MariaDB)
	sqls = recordSQL(th, func(string) (string, error) { return "", nil })
	_ = th.ReadOnly(context.Background(), true)
	if (*sqls)[0] != "SET GLOBAL read_only = ON;" {
		t.Errorf("mariadb sql %q", *sqls)
	}
	if err := newTestHelper(t, Redis).ReadOnly(context.Background(), true); ExitCode(err) != ExitUnsupported {
		t.Errorf("redis: %v", err)
	}
}

func TestTableCounts(t *testing.T) {
	th := newTestHelper(t, Postgres)
	recordSQL(th, func(string) (string, error) { return "public.users|42\npublic.orders|7\n", nil })
	if err := th.TableCounts(context.Background(), "app"); err != nil {
		t.Fatal(err)
	}
	tables := decode(t, th.out.String())["tables"].(map[string]any)
	if tables["public.users"] != float64(42) || tables["public.orders"] != float64(7) {
		t.Errorf("tables %v", tables)
	}

	th = newTestHelper(t, MariaDB)
	recordSQL(th, func(sql string) (string, error) {
		if strings.HasPrefix(sql, "SELECT TABLE_NAME") {
			return "users\n", nil
		}
		return "5\n", nil
	})
	if err := th.TableCounts(context.Background(), "shop"); err != nil {
		t.Fatal(err)
	}
	if tables := decode(t, th.out.String())["tables"].(map[string]any); tables["shop.users"] != float64(5) {
		t.Errorf("tables %v", tables)
	}
}

func TestRestoreSwapLoadsAsideAndRenamesIn(t *testing.T) {
	th := newTestHelper(t, Postgres)
	sqls := recordSQL(th, func(sql string) (string, error) {
		if strings.HasPrefix(sql, "SELECT count(*) FROM pg_database") {
			return "1", nil
		}
		return "", nil
	})
	if err := th.RestoreSwap(context.Background(), "app", "-", "app"); err != nil {
		t.Fatal(err)
	}
	all := strings.Join(*sqls, "\n")
	order := []string{
		`CREATE DATABASE "app__falak_restore"`,
		"pg_restore",
		"-d app__falak_restore",
		`ALTER DATABASE "app__falak_restore" OWNER TO "app"`,
		`ALTER DATABASE "app" RENAME TO "app__falak_previous"`,
		`ALTER DATABASE "app__falak_restore" RENAME TO "app"`,
		`DROP DATABASE IF EXISTS "app__falak_previous" WITH (FORCE);`,
	}
	at := 0
	for _, want := range order {
		i := strings.Index(all[at:], want)
		if i < 0 {
			t.Fatalf("missing (in order) %q in\n%s", want, all)
		}
		at += i
	}
	if decode(t, th.out.String())["swapped"] != true {
		t.Errorf("result %s", th.out)
	}
}

func TestRestoreSwapFailureKeepsTheDatabase(t *testing.T) {
	th := newTestHelper(t, Postgres)
	sqls := &[]string{}
	th.run.handle = func(c Cmd) (string, error) {
		if c.Name == "pg_restore" {
			return "", errors.New("pg_restore: error")
		}
		*sqls = append(*sqls, th.run.stdins[c.Name])
		return "", nil
	}
	if err := th.RestoreSwap(context.Background(), "app", "-", ""); err == nil {
		t.Fatal("restore error swallowed")
	}
	all := strings.Join(*sqls, "\n")
	if strings.Contains(all, "RENAME") || !strings.Contains(all, `DROP DATABASE IF EXISTS "app__falak_restore" WITH (FORCE);`) {
		t.Errorf("sql %s", all)
	}
}

func TestOwnershipSQLCoversEveryObjectKind(t *testing.T) {
	sql := pgOwnershipSQL("app", "o'wner")
	for _, want := range []string{`ALTER DATABASE "app" OWNER TO "o'wner"`, "o text := 'o''wner'", "ALTER SCHEMA", "MATERIALIZED VIEW", "SEQUENCE", "FOREIGN TABLE", "PROCEDURE", "AGGREGATE", "ALTER TYPE", "deptype = 'e'"} {
		if !strings.Contains(sql, want) {
			t.Errorf("missing %q", want)
		}
	}
}

func TestSchemaGrantsCoverEverySchema(t *testing.T) {
	sql := pgSchemaGrants("app", "ALL", "ALL", true)
	if strings.Contains(sql, "SCHEMA public") || !strings.Contains(sql, "FOR r IN SELECT n.nspname FROM pg_namespace n WHERE") || !strings.Contains(sql, "ALL SEQUENCES") {
		t.Errorf("sql %q", sql)
	}
}

func TestPasswordSetKeepsTheCurrentOneDuringAnOverlap(t *testing.T) {
	th := newTestHelper(t, Redis)
	os.MkdirAll(filepath.Join(th.Root, filepath.Dir(kvACLPath)), 0o700)
	th.run.handle = func(Cmd) (string, error) { return "OK", nil }
	f := filepath.Join(th.Root, "new")
	os.WriteFile(f, []byte("n3w-pw\n"), 0o400)
	if err := th.PasswordSet(context.Background(), f, true); err != nil {
		t.Fatal(err)
	}
	sum := func(s string) string { h := sha256.Sum256([]byte(s)); return hex.EncodeToString(h[:]) }
	c, _ := th.run.call("redis-cli")
	if args := strings.Join(c.Args, " "); !strings.Contains(args, "ACL SETUSER default #"+sum("n3w-pw")) || strings.Contains(args, "resetpass") {
		t.Errorf("args %q", args)
	}
	acl, _ := os.ReadFile(filepath.Join(th.Root, kvACLPath))
	if !strings.Contains(string(acl), "#"+sum(`s3cr"et\pw`)+" #"+sum("n3w-pw")) {
		t.Errorf("acl %q", acl)
	}
	if err := newTestHelper(t, Postgres).PasswordSet(context.Background(), f, true); ExitCode(err) != ExitUsage {
		t.Errorf("postgres keep: %v", err)
	}
}

func TestPasswordSetMySQLRetryConnectsWithTheNewPassword(t *testing.T) {
	th := newTestHelper(t, MySQL)
	f := filepath.Join(th.Root, "new")
	os.WriteFile(f, []byte("n3w-pw"), 0o400)
	calls := 0
	th.run.handle = func(c Cmd) (string, error) {
		calls++
		if calls == 1 {
			return "", errors.New("ERROR 1045 (28000): Access denied")
		}
		if !strings.Contains(string(c.Fd3), "n3w-pw") {
			t.Errorf("retry without the new password")
		}
		if strings.HasPrefix(th.run.stdins["mysql"], "SELECT Host") {
			return "%\n", nil
		}
		return "", nil
	}
	if err := th.PasswordSet(context.Background(), f, false); err != nil {
		t.Fatal(err)
	}
}

func TestRenderKeepsThePreviousPasswordWhileTheRotationOverlaps(t *testing.T) {
	th := newTestHelper(t, Valkey)
	prev := filepath.Join(th.Root, "run", "secrets", "password.previous")
	os.WriteFile(prev, []byte("old-pw\n"), 0o400)
	th.env["FALAK_DB_PREVIOUS_PASSWORD_FILE"] = prev
	in, err := th.renderInput(256<<20, Settings{})
	if err != nil {
		t.Fatal(err)
	}
	h := sha256.Sum256([]byte("old-pw"))
	if in.PreviousPasswordSHA256 != hex.EncodeToString(h[:]) {
		t.Fatalf("previous %q", in.PreviousPasswordSHA256)
	}
	files, _, err := Render(in)
	if err != nil {
		t.Fatal(err)
	}
	for _, f := range files {
		if f.Path == kvACLPath && !strings.Contains(f.Content, "#"+in.PreviousPasswordSHA256+" #"+in.PasswordSHA256) {
			t.Errorf("acl %q", f.Content)
		}
	}
}
