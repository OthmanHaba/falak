package dbhelper

import (
	"context"
	"fmt"
	"io"
	"regexp"
	"strconv"
	"strings"
)

// Restore drills (docs/BACKUPS.md) check a backup restored into a throwaway instance: its row counts (table-counts,
// and the key count of Redis / Valkey) against those recorded when it was taken, and an optional check query of the
// user's, run read-only.

// kvKeyCounts is the key count of every logical database of a Redis / Valkey server (`INFO keyspace`).
func (h *Helper) kvKeyCounts(ctx context.Context) (map[string]int64, error) {
	out, err := h.kvCmd(ctx, "INFO", "keyspace")
	if err != nil {
		return nil, err
	}
	counts := map[string]int64{}
	for _, l := range lines(out) {
		name, rest, ok := strings.Cut(l, ":")
		if !ok || !strings.HasPrefix(name, "db") {
			continue
		}
		for _, kv := range strings.Split(rest, ",") {
			if v, ok := strings.CutPrefix(kv, "keys="); ok {
				n, err := strconv.ParseInt(v, 10, 64)
				if err != nil {
					return nil, fmt.Errorf("INFO keyspace: %q", l)
				}
				counts[name] = n
			}
		}
	}
	return counts, nil
}

// MaxCheckQuery is the longest check query accepted.
const MaxCheckQuery = 4000

var checkQueryStart = regexp.MustCompile(`(?i)^(select|with)[\s(]`)

// CheckQuery validates a drill's check query: one SELECT (or WITH … SELECT) statement, no statement separator,
// comments or backslashes (nothing that could end the read-only transaction it runs in or reach the mysql client's
// commands). Line breaks become spaces. It returns the query to embed.
func CheckQuery(sql string) (string, error) {
	q := strings.TrimSpace(sql)
	q = strings.TrimSpace(strings.TrimSuffix(q, ";"))
	switch {
	case q == "":
		return "", usageErr("the check query is empty")
	case len(q) > MaxCheckQuery:
		return "", usageErr("the check query is longer than %d characters", MaxCheckQuery)
	case strings.ContainsAny(q, ";\\\x00"):
		return "", usageErr("the check query must be a single statement without ; or \\")
	case strings.Contains(q, "--") || strings.Contains(q, "/*") || strings.Contains(q, "#"):
		return "", usageErr("the check query must not contain comments (--, /*, #)")
	case !checkQueryStart.MatchString(q):
		return "", usageErr("the check query must be a SELECT")
	}
	return strings.Join(strings.Fields(q), " "), nil
}

// Query is `falak-db query --database DB` with a check query on stdin (CheckQuery): it runs as
// `SELECT count(*) FROM (<query>)` in a read-only transaction (60 s at most) and prints {"database","rows"}.
func (h *Helper) Query(ctx context.Context, database string) error {
	if h.Engine.kv() {
		return unsupported(h.Engine, "query")
	}
	if err := checkName("--database", database); err != nil {
		return err
	}
	b, err := io.ReadAll(io.LimitReader(h.Stdin, MaxCheckQuery+2))
	if err != nil {
		return err
	}
	q, err := CheckQuery(string(b))
	if err != nil {
		return err
	}
	var out string
	if h.Engine == Postgres {
		out, err = h.pgExec(ctx, database, "BEGIN TRANSACTION READ ONLY;\nSET LOCAL statement_timeout = '60s';\n"+
			"SELECT count(*) FROM ("+q+") AS falak_check;\nROLLBACK;\n")
	} else {
		var safe []string
		if safe, err = h.mysqlSafeClientArgs(ctx); err != nil {
			return err
		}
		var creds []byte
		if creds, err = h.mysqlDefaults(); err != nil {
			return err
		}
		c := myCmd(creds, h.Engine.client("mysql"), append([]string{"-N", "-B", "--default-character-set=utf8mb4"}, safe...)...)
		timeout := "SET SESSION max_execution_time = 60000;\n"
		if h.Engine == MariaDB {
			timeout = "SET SESSION max_statement_time = 60;\n"
		}
		c.Stdin = strings.NewReader("USE " + myIdent(database) + ";\n" + timeout +
			"START TRANSACTION READ ONLY;\nSELECT COUNT(*) FROM (" + q + ") AS falak_check;\nROLLBACK;\n")
		out, err = output(ctx, h.Run, c)
		out = strings.TrimSpace(out)
	}
	if err != nil {
		return fmt.Errorf("check query: %w", err)
	}
	n, err := strconv.ParseInt(strings.TrimSpace(out), 10, 64)
	if err != nil {
		return fmt.Errorf("check query: unexpected output %q", firstLine(out))
	}
	return h.result(map[string]any{"database": database, "rows": n})
}
