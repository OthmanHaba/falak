package dbhelper

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/version"
)

const usageText = `usage: falak-db <command> [flags]

Runs inside a Falak database image. The engine comes from FALAK_DB_ENGINE (or --engine).

commands:
  init [-- server args]                 entrypoint: render the config, then start the server via the official entrypoint
  config render [--memory-bytes N] [--settings JSON]
                                        write the engine config tuned to the memory limit
  health                                liveness probe (the image's HEALTHCHECK)
  backup logical --database DB --out -  dump to stdout (postgres, mysql, mariadb; redis/valkey: RDB, no --database)
  backup physical --out -               base backup to stdout (postgres tar, mysql/mariadb xbstream)
  restore logical [--database DB] --in - [--clean]
  restore physical --in -               unpack (and prepare) a base backup into the empty data directory
  recover [--target-time T] (--wal-dir DIR [--action promote|pause|shutdown] | --binlog-dir DIR)
  promote                               postgres: end a paused recovery, open for writes
  wal-push PATH                         postgres archive_command: spool a WAL segment
  wal-fetch NAME DEST --from DIR        postgres restore_command
  binlog-rotate [--no-flush] [--restart] mysql/mariadb: flush binary logs, spool the closed ones (exit 4 on gaps)
  database create --name N [--charset C] [--collation X] [--owner O]
  database drop --name N                postgres, mysql, mariadb
  user apply --spec FILE                converge a user, its password and grants (JSON spec file)
  password set --file FILE              the superuser's / root's / default user's new password
  version

Results are one JSON line on stdout; for commands streaming data on stdout, the result is the stderr line starting
with "falak-db-result: ". Exit codes: 0 ok, 1 failed, 2 usage, 3 unsupported for the engine, 4 conflict, 5 not found.
`

// Main runs one falak-db command and returns the exit code.
func Main(ctx context.Context, h *Helper, args []string) int {
	err := dispatch(ctx, h, args)
	if errors.Is(err, flag.ErrHelp) {
		return ExitUsage
	}
	if err != nil {
		fmt.Fprintf(h.Stderr, "falak-db: %v\n", err)
	}
	return ExitCode(err)
}

// parse parses flags that may come before, between or after positional arguments.
func parse(fs *flag.FlagSet, args []string) ([]string, error) {
	var pos []string
	for {
		if err := fs.Parse(args); err != nil {
			if errors.Is(err, flag.ErrHelp) {
				return nil, err
			}
			return nil, usageErr("%v", err)
		}
		if fs.NArg() == 0 {
			return pos, nil
		}
		pos = append(pos, fs.Arg(0))
		args = fs.Args()[1:]
	}
}

func dispatch(ctx context.Context, h *Helper, args []string) error {
	if len(args) == 0 {
		fmt.Fprint(h.Stderr, usageText)
		return usageErr("no command")
	}
	cmd, args := args[0], args[1:]
	switch cmd {
	case "help", "-h", "--help":
		fmt.Fprint(h.Stdout, usageText)
		return nil
	case "version", "--version":
		return h.result(map[string]any{"version": version.Version, "engine": h.Engine})
	case "config", "backup", "restore", "database", "user", "password":
		if len(args) == 0 {
			return usageErr("%s needs a subcommand", cmd)
		}
		cmd, args = cmd+" "+args[0], args[1:]
	}

	fs := flag.NewFlagSet("falak-db "+cmd, flag.ContinueOnError)
	fs.SetOutput(h.Stderr)
	engine := fs.String("engine", string(h.Engine), "engine (default: FALAK_DB_ENGINE)")
	var (
		memory   = new(int64)
		settings = new(string)
		database = new(string)
		out, in  = new(string), new(string)
		clean    = new(bool)
		ro       RecoverOptions
		from     = new(string)
		noFlush  = new(bool)
		restart  = new(bool)
		dbo      DatabaseOptions
		file     = new(string)
	)
	var extra []string
	switch cmd {
	case "init":
		// Everything after "--" goes to the server.
		for i, a := range args {
			if a == "--" {
				extra, args = args[i+1:], args[:i]
				break
			}
		}
	case "config render":
		fs.Int64Var(memory, "memory-bytes", 0, "memory limit in bytes (default: detected)")
		fs.StringVar(settings, "settings", h.Env("FALAK_DB_SETTINGS"), "settings JSON (default: FALAK_DB_SETTINGS)")
	case "backup logical":
		fs.StringVar(database, "database", "", "database to dump")
		fs.StringVar(out, "out", "", "output (-: stdout)")
	case "backup physical":
		fs.StringVar(out, "out", "", "output (-: stdout)")
	case "restore logical":
		fs.StringVar(database, "database", "", "target database (must exist)")
		fs.StringVar(in, "in", "", "input (-: stdin)")
		fs.BoolVar(clean, "clean", false, "postgres: drop objects before recreating them")
	case "restore physical":
		fs.StringVar(in, "in", "", "input (-: stdin)")
	case "recover":
		fs.StringVar(&ro.TargetTime, "target-time", "", "recover up to this time (RFC 3339); empty: everything available")
		fs.StringVar(&ro.WALDir, "wal-dir", "", "postgres: directory holding the WAL segments to replay")
		fs.StringVar(&ro.Action, "action", "", "postgres: at the target, promote (default), pause (read-only; holds in-flight locks) or shutdown")
		fs.StringVar(&ro.BinlogDir, "binlog-dir", "", "mysql/mariadb: directory holding the binlogs to replay")
	case "wal-fetch":
		fs.StringVar(from, "from", "", "directory holding archived WAL")
	case "binlog-rotate":
		fs.BoolVar(noFlush, "no-flush", false, "only spool binlogs that are already closed")
		fs.BoolVar(restart, "restart", false, "after a reset (exit 4, gap kind \"reset\") and a new base backup: forget the last spooled name")
	case "database create":
		fs.StringVar(&dbo.Name, "name", "", "database name")
		fs.StringVar(&dbo.Charset, "charset", "", "mysql/mariadb: character set (default utf8mb4)")
		fs.StringVar(&dbo.Collation, "collation", "", "mysql/mariadb: collation")
		fs.StringVar(&dbo.Owner, "owner", "", "postgres: owner role")
	case "database drop":
		fs.StringVar(&dbo.Name, "name", "", "database name")
	case "user apply":
		fs.StringVar(file, "spec", "", "JSON user spec (holds the password: a file in the secrets directory)")
	case "password set":
		fs.StringVar(file, "file", "", "file holding the new password")
	case "health", "promote", "wal-push":
	default:
		fmt.Fprint(h.Stderr, usageText)
		return usageErr("unknown command %q", cmd)
	}
	pos, err := parse(fs, args)
	if err != nil {
		return err
	}
	if *engine == "" {
		return usageErr("no engine: set FALAK_DB_ENGINE or pass --engine")
	}
	if h.Engine, err = ParseEngine(*engine); err != nil {
		return err
	}
	wantArgs := map[string]int{"wal-push": 1, "wal-fetch": 2}[cmd]
	if len(pos) != wantArgs {
		return usageErr("%s takes %d argument(s), got %d (%s)", cmd, wantArgs, len(pos), strings.Join(pos, " "))
	}

	switch cmd {
	case "init":
		return h.Init(extra)
	case "config render":
		return h.ConfigRender(*memory, *settings)
	case "health":
		ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
		defer cancel()
		return h.Health(ctx)
	case "backup logical":
		return h.BackupLogical(ctx, *database, *out)
	case "backup physical":
		return h.BackupPhysical(ctx, *out)
	case "restore logical":
		return h.RestoreLogical(ctx, *database, *in, *clean)
	case "restore physical":
		return h.RestorePhysical(ctx, *in)
	case "recover":
		return h.Recover(ctx, ro)
	case "promote":
		return h.Promote(ctx)
	case "wal-push":
		return h.WALPush(pos[0])
	case "wal-fetch":
		return h.WALFetch(pos[0], pos[1], *from)
	case "binlog-rotate":
		return h.BinlogRotate(ctx, *noFlush, *restart)
	case "database create":
		return h.DatabaseCreate(ctx, dbo)
	case "database drop":
		return h.DatabaseDrop(ctx, dbo.Name)
	case "user apply":
		return h.UserApply(ctx, *file)
	case "password set":
		return h.PasswordSet(ctx, *file)
	}
	return nil
}
