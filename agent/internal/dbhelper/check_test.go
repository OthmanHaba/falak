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

func TestQueryPostgresReadOnly(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.Stdin = strings.NewReader("SELECT id FROM users WHERE admin;\n")
	sqls := recordSQL(th, func(string) (string, error) { return "3\n", nil })
	if err := th.Query(context.Background(), "app"); err != nil {
		t.Fatal(err)
	}
	sql := (*sqls)[0]
	if !strings.HasPrefix(sql, "BEGIN TRANSACTION READ ONLY;") || !strings.Contains(sql, "SELECT count(*) FROM (SELECT id FROM users WHERE admin) AS falak_check;") || !strings.HasSuffix(sql, "ROLLBACK;\n") {
		t.Errorf("sql %q", sql)
	}
	c, _ := th.run.call("psql")
	if !slices.Contains(c.Args, "app") {
		t.Errorf("psql args %v", c.Args)
	}
	if m := decode(t, th.out.String()); m["rows"] != float64(3) {
		t.Errorf("result %v", m)
	}
}

func TestQueryMySQLSandboxed(t *testing.T) {
	th := newTestHelper(t, MySQL)
	th.Stdin = strings.NewReader("SELECT 1 FROM orders")
	var stdin string
	th.run.handle = func(c Cmd) (string, error) {
		if slices.Contains(c.Args, "--version") {
			return "mysql  Ver 8.4.11 for Linux on x86_64", nil
		}
		stdin = th.run.stdins[c.Name]
		if !slices.Contains(c.Args, "--system-command=OFF") {
			t.Errorf("args %v", c.Args)
		}
		return "0\n", nil
	}
	if err := th.Query(context.Background(), "shop"); err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(stdin, "USE `shop`;") || !strings.Contains(stdin, "START TRANSACTION READ ONLY;") || !strings.Contains(stdin, "max_execution_time") {
		t.Errorf("stdin %q", stdin)
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
