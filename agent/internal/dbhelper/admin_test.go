package dbhelper

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// sqlRunner answers psql / mysql by the SQL on their stdin.
func sqlRunner(th *testHelper, answer func(sql string) string) {
	th.run.handle = func(c Cmd) (string, error) {
		return answer(th.run.stdins[c.Name]), nil
	}
}

func TestDatabaseCreatePostgresSQLOnStdin(t *testing.T) {
	th := newTestHelper(t, Postgres)
	var sqls []string
	th.run.handle = func(c Cmd) (string, error) {
		sql := th.run.stdins[c.Name]
		sqls = append(sqls, sql)
		if strings.Contains(strings.Join(c.Args, " "), "-c") {
			t.Errorf("SQL in argv: %v", c.Args)
		}
		return "", nil // no such database yet
	}
	if err := th.DatabaseCreate(context.Background(), DatabaseOptions{Name: "app", Owner: "app"}); err != nil {
		t.Fatal(err)
	}
	if len(sqls) != 2 || sqls[1] != `CREATE DATABASE "app" ENCODING 'UTF8' TEMPLATE template0 OWNER "app";` {
		t.Errorf("sql %q", sqls)
	}
	if decode(t, th.out.String())["changed"] != true {
		t.Errorf("result %s", th.out)
	}
	if err := newTestHelper(t, Postgres).DatabaseCreate(context.Background(), DatabaseOptions{Name: "a;b"}); ExitCode(err) != ExitUsage {
		t.Errorf("bad name: %v", err)
	}
	if err := newTestHelper(t, Redis).DatabaseCreate(context.Background(), DatabaseOptions{Name: "a"}); ExitCode(err) != ExitUnsupported {
		t.Errorf("redis: %v", err)
	}
}

func TestDatabaseDropMySQLWhenPresent(t *testing.T) {
	th := newTestHelper(t, MariaDB)
	sqlRunner(th, func(sql string) string {
		if strings.HasPrefix(sql, "SELECT COUNT(*)") {
			return "1\n"
		}
		return ""
	})
	if err := th.DatabaseDrop(context.Background(), "shop"); err != nil {
		t.Fatal(err)
	}
	c, _ := th.run.call("mariadb")
	if th.run.stdins["mariadb"] != "DROP DATABASE IF EXISTS `shop`;" || strings.Contains(strings.Join(c.Args, " "), "s3cr") {
		t.Errorf("last sql %q args %v", th.run.stdins["mariadb"], c.Args)
	}
}

func TestUserApplyMySQLPasswordNeverInArgv(t *testing.T) {
	th := newTestHelper(t, MySQL)
	spec := filepath.Join(th.Root, "spec.json")
	os.WriteFile(spec, []byte(`{"username":"app","password":"us'er-pw","grants":[{"database":"app","privileges":["SELECT"]}],"state":"present"}`), 0o400)
	var all []string
	th.run.handle = func(c Cmd) (string, error) {
		sql := th.run.stdins[c.Name]
		all = append(all, sql)
		if strings.Contains(strings.Join(c.Args, " "), "us'er") {
			t.Errorf("password in argv %v", c.Args)
		}
		switch {
		case strings.HasPrefix(sql, "SELECT COUNT(*)"):
			return "0\n", nil
		case strings.HasPrefix(sql, "SELECT DISTINCT TABLE_SCHEMA"):
			return "old\n", nil
		}
		return "", nil
	}
	if err := th.UserApply(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(all, "\n")
	for _, want := range []string{
		"CREATE USER 'app'@'%' IDENTIFIED BY 'us''er-pw';",
		"REVOKE ALL PRIVILEGES ON `old`.* FROM 'app'@'%';",
		"GRANT SELECT ON `app`.* TO 'app'@'%';",
	} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in\n%s", want, joined)
		}
	}
	if strings.Contains(th.out.String(), "us'er") || strings.Contains(th.errOut.String(), "us'er") {
		t.Error("password in output")
	}
}

func TestUserApplyRefusesTheEngineAccounts(t *testing.T) {
	th := newTestHelper(t, Postgres)
	spec := filepath.Join(th.Root, "spec.json")
	os.WriteFile(spec, []byte(`{"username":"postgres","password":"x","state":"present"}`), 0o400)
	if err := th.UserApply(context.Background(), spec); ExitCode(err) != ExitUsage {
		t.Errorf("superuser accepted: %v", err)
	}
	os.WriteFile(spec, []byte(`{"username":"a","password":"x","extra":1}`), 0o400)
	if err := th.UserApply(context.Background(), spec); ExitCode(err) != ExitUsage {
		t.Errorf("unknown field accepted: %v", err)
	}
}

func TestPasswordSetPerEngine(t *testing.T) {
	ctx := context.Background()
	write := func(th *testHelper) string {
		f := filepath.Join(th.Root, "new")
		os.WriteFile(f, []byte("n3w-pw\n"), 0o400)
		return f
	}

	th := newTestHelper(t, Postgres)
	if err := th.PasswordSet(ctx, write(th), false); err != nil {
		t.Fatal(err)
	}
	if th.run.stdins["psql"] != `ALTER ROLE "postgres" WITH PASSWORD 'n3w-pw';` {
		t.Errorf("postgres sql %q", th.run.stdins["psql"])
	}

	th = newTestHelper(t, MySQL)
	sqlRunner(th, func(sql string) string {
		if strings.HasPrefix(sql, "SELECT Host") {
			return "%\nlocalhost\n"
		}
		return ""
	})
	if err := th.PasswordSet(ctx, write(th), false); err != nil {
		t.Fatal(err)
	}
	if got := th.run.stdins["mysql"]; got != "ALTER USER 'root'@'%' IDENTIFIED BY 'n3w-pw';\nALTER USER 'root'@'localhost' IDENTIFIED BY 'n3w-pw';\n" {
		t.Errorf("mysql sql %q", got)
	}

	th = newTestHelper(t, Valkey)
	os.MkdirAll(filepath.Join(th.Root, filepath.Dir(kvACLPath)), 0o700)
	th.run.handle = func(Cmd) (string, error) { return "OK", nil }
	if err := th.PasswordSet(ctx, write(th), false); err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256([]byte("n3w-pw"))
	c, _ := th.run.call("valkey-cli")
	args := strings.Join(c.Args, " ")
	if !strings.Contains(args, "ACL SETUSER default resetpass #"+hex.EncodeToString(sum[:])) || strings.Contains(args, "n3w-pw") {
		t.Errorf("valkey args %q", args)
	}
	acl, _ := os.ReadFile(filepath.Join(th.Root, kvACLPath))
	if !strings.Contains(string(acl), hex.EncodeToString(sum[:])) || strings.Contains(string(acl), "n3w-pw") {
		t.Errorf("acl %q", acl)
	}
}
