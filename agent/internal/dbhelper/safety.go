package dbhelper

import (
	"context"
	"fmt"
	"strconv"
	"strings"
)

// The commands the agent uses to move data without losing writes or ownership: read-only mode around a major
// upgrade's copy, per-table row counts to check the copy, ownership handed back to the application's user after a
// restore (pg_restore --no-owner leaves everything to the superuser), and PostgreSQL restores into a scratch
// database swapped in only once they succeeded.

// systemSchemas are left alone by the ownership hand-over and the row counts.
const pgUserSchemas = "n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema'"

// ReadOnly is `falak-db readonly on|off`. PostgreSQL: every database defaults to read-only transactions and the
// sessions already open are ended (they would keep writing); MySQL/MariaDB: super_read_only (MySQL) / read_only
// (MariaDB), which blocks every account but replication.
func (h *Helper) ReadOnly(ctx context.Context, on bool) error {
	if h.Engine.kv() {
		return unsupported(h.Engine, "readonly")
	}
	flag := "off"
	if on {
		flag = "on"
	}
	if h.Engine == Postgres {
		dbs, err := h.pgExec(ctx, "postgres", "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY 1;")
		if err != nil {
			return err
		}
		var sql strings.Builder
		for _, d := range lines(dbs) {
			sql.WriteString("ALTER DATABASE " + pgIdent(d) + " SET default_transaction_read_only = " + flag + ";\n")
		}
		if on {
			// Sessions keep their settings: end them, apps reconnect read-only.
			sql.WriteString("SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE pid <> pg_backend_pid() " +
				"AND backend_type = 'client backend' AND usename <> " + pgLit(h.pgUser()) + ";\n")
		}
		if _, err := h.pgExec(ctx, "postgres", sql.String()); err != nil {
			return err
		}
		return h.result(map[string]any{"read_only": on})
	}
	v := map[bool]string{true: "ON", false: "OFF"}[on]
	q := "SET GLOBAL read_only = " + v + ";"
	if h.Engine == MySQL {
		q = "SET GLOBAL super_read_only = " + v + ";"
		if !on {
			q += " SET GLOBAL read_only = OFF;"
		}
	}
	if _, err := h.myExec(ctx, q); err != nil {
		return err
	}
	return h.result(map[string]any{"read_only": on})
}

// TableCounts is `falak-db table-counts --database D`: the exact row count of every table ({"tables": {"schema.table":
// n}}), to compare a copy with its source.
func (h *Helper) TableCounts(ctx context.Context, database string) error {
	if h.Engine.kv() {
		return unsupported(h.Engine, "table-counts")
	}
	if err := checkName("--database", database); err != nil {
		return err
	}
	counts := map[string]int64{}
	if h.Engine == Postgres {
		out, err := h.pgExec(ctx, database, "SELECT n.nspname || '.' || c.relname || '|' || "+
			"(xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM %I.%I', n.nspname, c.relname), false, true, '')))[1]::text "+
			"FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind IN ('r', 'p') AND "+pgUserSchemas+" ORDER BY 1;")
		if err != nil {
			return err
		}
		for _, l := range lines(out) {
			name, n, _ := strings.Cut(l, "|")
			v, err := strconv.ParseInt(n, 10, 64)
			if err != nil {
				return fmt.Errorf("count of %s: %q", name, n)
			}
			counts[name] = v
		}
		return h.result(map[string]any{"database": database, "tables": counts})
	}
	tables, err := h.myExec(ctx, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA="+myLit(database)+" AND TABLE_TYPE='BASE TABLE' ORDER BY 1;")
	if err != nil {
		return err
	}
	for _, t := range lines(tables) {
		n, err := h.myExec(ctx, "SELECT COUNT(*) FROM "+myIdent(database)+"."+myIdent(t)+";")
		if err != nil {
			return err
		}
		v, err := strconv.ParseInt(n, 10, 64)
		if err != nil {
			return fmt.Errorf("count of %s: %q", t, n)
		}
		counts[database+"."+t] = v
	}
	return h.result(map[string]any{"database": database, "tables": counts})
}

// pgOwnershipSQL makes owner the owner of the database, its schemas and everything in them (tables, partitions,
// views, materialized views, sequences, foreign tables, functions, procedures, aggregates, types and domains), except
// what an extension owns. A plain REASSIGN OWNED BY the superuser is refused by PostgreSQL (system objects).
func pgOwnershipSQL(database, owner string) string {
	o := pgLit(owner)
	return "ALTER DATABASE " + pgIdent(database) + " OWNER TO " + pgIdent(owner) + ";\n" +
		"DO $falak$ DECLARE r record; o text := " + o + "; BEGIN\n" +
		"FOR r IN SELECT n.nspname FROM pg_namespace n WHERE " + pgUserSchemas + " AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = n.oid AND d.deptype = 'e') LOOP\n" +
		"  EXECUTE format('ALTER SCHEMA %I OWNER TO %I', r.nspname, o); END LOOP;\n" +
		"FOR r IN SELECT n.nspname, c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace\n" +
		"  WHERE " + pgUserSchemas + " AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')\n" +
		"  AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype IN ('e', 'a', 'i')) LOOP\n" +
		"  EXECUTE format('ALTER %s %I.%I OWNER TO %I', CASE r.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'S' THEN 'SEQUENCE' WHEN 'f' THEN 'FOREIGN TABLE' ELSE 'TABLE' END, r.nspname, r.relname, o); END LOOP;\n" +
		"FOR r IN SELECT p.oid::regprocedure AS sig, p.prokind FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace\n" +
		"  WHERE " + pgUserSchemas + " AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e') LOOP\n" +
		"  EXECUTE format('ALTER %s %s OWNER TO %I', CASE r.prokind WHEN 'p' THEN 'PROCEDURE' WHEN 'a' THEN 'AGGREGATE' ELSE 'FUNCTION' END, r.sig, o); END LOOP;\n" +
		"FOR r IN SELECT n.nspname, t.typname FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace\n" +
		"  WHERE " + pgUserSchemas + " AND t.typtype IN ('e', 'd', 'r', 'm')\n" +
		"  AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = t.oid AND d.deptype = 'e') LOOP\n" +
		"  EXECUTE format('ALTER TYPE %I.%I OWNER TO %I', r.nspname, r.typname, o); END LOOP;\n" +
		"END $falak$;\n"
}

// ReassignOwner is `falak-db reassign --database D --owner U` (postgres; the role must exist).
func (h *Helper) ReassignOwner(ctx context.Context, database, owner string) error {
	if h.Engine != Postgres {
		return unsupported(h.Engine, "reassign")
	}
	if err := h.reassign(ctx, database, owner); err != nil {
		return err
	}
	return h.result(map[string]any{"database": database, "owner": owner})
}

func (h *Helper) reassign(ctx context.Context, database, owner string) error {
	if err := checkName("--database", database); err != nil {
		return err
	}
	if err := checkName("--owner", owner); err != nil {
		return err
	}
	_, err := h.pgExec(ctx, database, pgOwnershipSQL(database, owner))
	return err
}

// RestoreOwned is `falak-db restore logical --database D --in - --owner U` without --swap (postgres, an empty
// database such as a major upgrade's target): pg_restore into it, then ownership to U.
func (h *Helper) RestoreOwned(ctx context.Context, database, in, owner string) error {
	if h.Engine != Postgres {
		return usageErr("--owner is for postgres")
	}
	if err := checkStdio(in, "--in"); err != nil {
		return err
	}
	if err := checkName("--database", database); err != nil {
		return err
	}
	args := []string{"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-d", database, "--no-owner", "--no-acl", "--exit-on-error"}
	if err := h.Run.Run(ctx, Cmd{Name: "pg_restore", Args: args, Stdin: h.Stdin, Stderr: h.Stderr}); err != nil {
		return err
	}
	if err := h.reassign(ctx, database, owner); err != nil {
		return err
	}
	return h.result(map[string]any{"engine": h.Engine, "kind": "logical", "database": database, "restored": true, "owner": owner})
}

// restoreScratch is the database a swapped restore loads into ("<db>__falak_restore", within 63 bytes).
func restoreScratch(database, suffix string) string {
	if len(database)+len(suffix) > 63 {
		database = database[:63-len(suffix)]
	}
	return database + suffix
}

// RestoreSwap is `falak-db restore logical --database D --in - --swap [--owner U]` for PostgreSQL: the dump loads into
// a scratch database; only when that succeeded (and ownership went to U) is it renamed into place, the old database
// moved aside first and dropped last. A failed restore leaves the database as it was.
func (h *Helper) RestoreSwap(ctx context.Context, database, in, owner string) error {
	if h.Engine != Postgres {
		return usageErr("--swap is for postgres")
	}
	if err := checkStdio(in, "--in"); err != nil {
		return err
	}
	if err := checkName("--database", database); err != nil {
		return err
	}
	scratch := restoreScratch(database, "__falak_restore")
	aside := restoreScratch(database, "__falak_previous")
	if _, err := h.pgExec(ctx, "postgres", "DROP DATABASE IF EXISTS "+pgIdent(scratch)+" WITH (FORCE);\n"+
		"CREATE DATABASE "+pgIdent(scratch)+" ENCODING 'UTF8' TEMPLATE template0;"); err != nil {
		return err
	}
	drop := func() {
		_, _ = h.pgExec(context.WithoutCancel(ctx), "postgres", "DROP DATABASE IF EXISTS "+pgIdent(scratch)+" WITH (FORCE);")
	}
	args := []string{"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-d", scratch, "--no-owner", "--no-acl", "--exit-on-error"}
	if err := h.Run.Run(ctx, Cmd{Name: "pg_restore", Args: args, Stdin: h.Stdin, Stderr: h.Stderr}); err != nil {
		drop()
		return err
	}
	if owner != "" {
		if err := h.reassign(ctx, scratch, owner); err != nil {
			drop()
			return err
		}
	}
	exists, err := h.pgExec(ctx, "postgres", "SELECT count(*) FROM pg_database WHERE datname="+pgLit(database)+";")
	if err != nil {
		drop()
		return err
	}
	swap := "DROP DATABASE IF EXISTS " + pgIdent(aside) + " WITH (FORCE);\n"
	if exists == "1" {
		// Sessions on the database would block the rename (apps reconnect to the restored one).
		swap += "SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE datname = " + pgLit(database) + " AND pid <> pg_backend_pid();\n" +
			"ALTER DATABASE " + pgIdent(database) + " RENAME TO " + pgIdent(aside) + ";\n"
	}
	swap += "ALTER DATABASE " + pgIdent(scratch) + " RENAME TO " + pgIdent(database) + ";\n"
	if _, err := h.pgExec(ctx, "postgres", swap); err != nil {
		// Put the old one back if it was moved aside.
		if cur, _ := h.pgExec(context.WithoutCancel(ctx), "postgres", "SELECT count(*) FROM pg_database WHERE datname="+pgLit(database)+";"); cur == "0" {
			_, _ = h.pgExec(context.WithoutCancel(ctx), "postgres", "ALTER DATABASE "+pgIdent(aside)+" RENAME TO "+pgIdent(database)+";")
		}
		drop()
		return fmt.Errorf("swapping the restored database in: %w", err)
	}
	if exists == "1" {
		if _, err := h.pgExec(ctx, "postgres", "DROP DATABASE IF EXISTS "+pgIdent(aside)+" WITH (FORCE);"); err != nil {
			fmt.Fprintf(h.Stderr, "falak-db: the previous database is kept as %s: %v\n", aside, err)
		}
	}
	return h.result(map[string]any{"engine": h.Engine, "kind": "logical", "database": database, "restored": true, "swapped": true, "owner": owner})
}
