package dbhelper

import (
	"context"
	"slices"
	"strings"
	"testing"
)

func TestCheckQuery(t *testing.T) {
	ok := map[string]string{
		"SELECT 1":                                 "SELECT 1",
		"select id from users where active;":       "select id from users where active",
		"  WITH t AS (SELECT 1) SELECT * FROM t  ": "WITH t AS (SELECT 1) SELECT * FROM t",
		"SELECT *\nFROM orders\n\tWHERE total > 0": "SELECT * FROM orders WHERE total > 0",
		"select(1)": "select(1)",
	}
	for in, want := range ok {
		got, err := CheckQuery(in)
		if err != nil || got != want {
			t.Errorf("%q: %q %v", in, got, err)
		}
	}
	bad := []string{
		"",
		";",
		"DELETE FROM users",
		"SELECT 1; DROP TABLE users",
		"SELECT 1) q; COMMIT; DROP TABLE users; SELECT (1",
		"SELECT 1 -- comment",
		"SELECT /* x */ 1",
		"SELECT 1 # mysql comment",
		"SELECT '\\! rm -rf /'",
		"selection",
		"UPDATE x SET y = 1 WHERE (SELECT 1)",
		strings.Repeat("SELECT 1 ", MaxCheckQuery),
	}
	for _, in := range bad {
		if _, err := CheckQuery(in); err == nil || ExitCode(err) != ExitUsage {
			t.Errorf("%q accepted (%v)", in, err)
		}
	}
}

func TestQueryPostgresAsATemporaryReadOnlyRole(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.Stdin = strings.NewReader("SELECT id FROM users WHERE admin;\n")
	var calls []Cmd
	var stdins []string
	th.run.handle = func(c Cmd) (string, error) {
		calls = append(calls, c)
		stdins = append(stdins, th.run.stdins[c.Name])
		if strings.Contains(th.run.stdins[c.Name], "falak_check") && strings.Contains(th.run.stdins[c.Name], "SELECT count(*)") {
			return "3\n", nil
		}
		return "", nil
	}
	if err := th.Query(context.Background(), "app"); err != nil {
		t.Fatal(err)
	}
	if len(calls) != 3 {
		t.Fatalf("calls %d", len(calls))
	}
	create, run, drop := stdins[0], stdins[1], stdins[2]
	role := strings.Fields(create)[2]
	if !strings.HasPrefix(role, `"falak_check_`) || !strings.Contains(create, "NOSUPERUSER") || !strings.Contains(create, "GRANT pg_read_all_data") ||
		!strings.Contains(create, "statement_timeout = '60s'") || !strings.Contains(create, "default_transaction_read_only = on") {
		t.Errorf("create %q", create)
	}
	// The check connects as the role, not the superuser, to the database.
	if u := calls[1].Args[slices.Index(calls[1].Args, "-U")+1]; `"`+u+`"` != role || !slices.Contains(calls[1].Args, "app") {
		t.Errorf("psql args %v (role %s)", calls[1].Args, role)
	}
	if !strings.HasPrefix(run, "BEGIN TRANSACTION READ ONLY;") || !strings.Contains(run, "SELECT count(*) FROM (SELECT id FROM users WHERE admin) AS falak_check;") {
		t.Errorf("sql %q", run)
	}
	if !strings.Contains(drop, "DROP ROLE IF EXISTS "+role) {
		t.Errorf("drop %q", drop)
	}
	if m := decode(t, th.out.String()); m["rows"] != float64(3) {
		t.Errorf("result %v", m)
	}
}

func TestQueryMySQLAsASelectOnlyUser(t *testing.T) {
	th := newTestHelper(t, MySQL)
	th.Stdin = strings.NewReader("SELECT 1 FROM orders")
	var stdins []string
	var check Cmd
	th.run.handle = func(c Cmd) (string, error) {
		if slices.Contains(c.Args, "--version") {
			return "mysql  Ver 8.4.11 for Linux on x86_64", nil
		}
		in := th.run.stdins[c.Name]
		stdins = append(stdins, in)
		if strings.Contains(in, "COUNT(*)") {
			check = c
			return "0\n", nil
		}
		return "", nil
	}
	if err := th.Query(context.Background(), "shop"); err != nil {
		t.Fatal(err)
	}
	if len(stdins) != 3 || !strings.Contains(stdins[0], "CREATE USER 'falak_check_") || !strings.Contains(stdins[0], "GRANT SELECT ON `shop`.*") ||
		!strings.HasPrefix(stdins[2], "DROP USER IF EXISTS 'falak_check_") {
		t.Fatalf("stdins %q", stdins)
	}
	run := stdins[1]
	if !strings.HasPrefix(run, "USE `shop`;") || !strings.Contains(run, "START TRANSACTION READ ONLY;") || !strings.Contains(run, "max_execution_time") {
		t.Errorf("stdin %q", run)
	}
	// The check's credentials are the temporary user's, never root's, and the client is sandboxed.
	if !slices.Contains(check.Args, "--system-command=OFF") || !strings.Contains(string(check.Fd3), "user=falak_check_") || strings.Contains(string(check.Fd3), "user=root") {
		t.Errorf("check cmd %v creds %q", check.Args, check.Fd3)
	}
	if m := decode(t, th.out.String()); m["rows"] != float64(0) {
		t.Errorf("result %v", m)
	}

	th = newTestHelper(t, MySQL)
	th.Stdin = strings.NewReader("SELECT 1; DROP TABLE x")
	if err := th.Query(context.Background(), "shop"); ExitCode(err) != ExitUsage || len(th.run.calls) != 0 {
		t.Errorf("unsafe query: %v, %d calls", err, len(th.run.calls))
	}
	if err := newTestHelper(t, Redis).Query(context.Background(), "x"); ExitCode(err) != ExitUnsupported {
		t.Errorf("redis: %v", err)
	}
}

func TestTableCountsKeyValue(t *testing.T) {
	th := newTestHelper(t, Valkey)
	th.run.handle = func(c Cmd) (string, error) {
		if !slices.Contains(c.Args, "keyspace") {
			t.Errorf("args %v", c.Args)
		}
		return "# Keyspace\r\ndb0:keys=12,expires=1,avg_ttl=0,subexpiry=0\r\ndb3:keys=4,expires=0,avg_ttl=0\r\n", nil
	}
	if err := th.TableCounts(context.Background(), ""); err != nil {
		t.Fatal(err)
	}
	tables := decode(t, th.out.String())["tables"].(map[string]any)
	if tables["db0"] != float64(12) || tables["db3"] != float64(4) || len(tables) != 2 {
		t.Errorf("tables %v", tables)
	}
}
