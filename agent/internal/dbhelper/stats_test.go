package dbhelper

import (
	"context"
	"strings"
	"testing"
)

func TestStats(t *testing.T) {
	for _, c := range []struct {
		engine Engine
		answer func(Cmd) string
		want   string
	}{
		{Postgres, func(Cmd) string { return "42|100\n" }, `{"connections":42,"max_connections":100}`},
		{MySQL, func(Cmd) string { return "151\t151\n" }, `{"connections":151,"max_connections":151}`},
		{MariaDB, func(Cmd) string { return "3\t500\n" }, `{"connections":3,"max_connections":500}`},
		{Redis, func(c Cmd) string {
			if strings.Contains(strings.Join(c.Args, " "), "maxclients") {
				return "maxclients\n10000\n"
			}
			return "# Clients\r\nconnected_clients:7\r\nblocked_clients:0\r\n"
		}, `{"connections":7,"max_connections":10000}`},
	} {
		th := newTestHelper(t, c.engine)
		th.run.handle = func(cmd Cmd) (string, error) { return c.answer(cmd), nil }
		if err := th.Stats(context.Background()); err != nil {
			t.Fatalf("%s: %v", c.engine, err)
		}
		if got := strings.TrimSpace(th.out.String()); got != c.want {
			t.Errorf("%s: %s, want %s", c.engine, got, c.want)
		}
	}
}

func TestStatsQueries(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.run.handle = func(Cmd) (string, error) { return "1|100", nil }
	if err := th.Stats(context.Background()); err != nil {
		t.Fatal(err)
	}
	if in := th.run.stdins["psql"]; !strings.Contains(in, "pg_stat_activity") || !strings.Contains(in, "max_connections") {
		t.Errorf("postgres query %q", in)
	}

	th = newTestHelper(t, MySQL)
	th.run.handle = func(Cmd) (string, error) { return "1\t100", nil }
	if err := th.Stats(context.Background()); err != nil {
		t.Fatal(err)
	}
	if in := th.run.stdins["mysql"]; !strings.Contains(in, "Threads_connected") || !strings.Contains(in, "@@max_connections") {
		t.Errorf("mysql query %q", in)
	}
}

func TestStatsRejectsGarbage(t *testing.T) {
	th := newTestHelper(t, Postgres)
	th.run.handle = func(Cmd) (string, error) { return "ERROR: nope", nil }
	if err := th.Stats(context.Background()); err == nil {
		t.Fatal("garbage accepted")
	}
	if code := Main(context.Background(), th.Helper, []string{"stats", "extra"}); code != ExitUsage {
		t.Errorf("stats with an argument: exit %d", code)
	}
}
