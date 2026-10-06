package db

import (
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Backups and restores of Redis / Valkey instances: db.backup / db.restore with engine redis | valkey and the instance's
// name as `database` (feature db.redis.backup). The agent knows each instance's port and password (its state, else its
// config file), so the payloads carry no secret.
//
// Backup: `redis-cli --rdb` against the running instance (a consistent snapshot through the replication handshake, no
// restart, nothing written in the data directory), the password in REDISCLI_AUTH; the file's RDB header is checked, then
// it is gzipped and uploaded like a SQL dump.
//
// Restore: the snapshot is downloaded (sha256 checked), gunzipped into the instance's data directory and its header
// checked against the installed server (a Redis 7.4+ snapshot can't load into Valkey, a Valkey 9 one into Redis, …)
// before anything changes. Then the unit is stopped (always: that also cancels an automatic restart pending; a failed
// stop starts it again and changes nothing), the current dump.rdb / appendonlydir / appendonly.aof are moved
// aside (<file>.falak-<UTC time>), the snapshot becomes dump.rdb (0600, the instance user) and the unit starts. An
// instance with AOF starts from a config with `appendonly no` (Redis ignores dump.rdb while AOF is on), then switches AOF
// on live (CONFIG SET appendonly yes rewrites the AOF from memory) and its config file is put back. If the start, PING
// or the AOF switch fails, the restored files are removed, the earlier ones put back, the earlier config written and the
// instance started again; the restore fails with the unit's log tail.

// rdbHeader is a snapshot's first 9 bytes: REDIS + 4 digits (Redis, Valkey up to 8.1) or VALKEY + 3 digits (Valkey 9+).
type rdbHeader struct {
	magic   string
	version int
}

func (h rdbHeader) String() string {
	if h.magic == "VALKEY" {
		return fmt.Sprintf("VALKEY%03d", h.version)
	}
	return fmt.Sprintf("REDIS%04d", h.version)
}

var errNotRDB = errors.New("not a Redis / Valkey snapshot (no REDIS or VALKEY header)")

func parseRDBHeader(b []byte) (rdbHeader, error) {
	if len(b) < 9 {
		return rdbHeader{}, errNotRDB
	}
	var h rdbHeader
	var digits []byte
	switch {
	case string(b[:5]) == "REDIS":
		h.magic, digits = "REDIS", b[5:9]
	case string(b[:6]) == "VALKEY":
		h.magic, digits = "VALKEY", b[6:9]
	default:
		return rdbHeader{}, errNotRDB
	}
	for _, d := range digits {
		if d < '0' || d > '9' {
			return rdbHeader{}, errNotRDB
		}
	}
	h.version, _ = strconv.Atoi(string(digits))
	if h.version < 1 {
		return rdbHeader{}, errNotRDB
	}
	return h, nil
}

// readRDBHeader reads and checks a snapshot file's header.
func readRDBHeader(path string) (rdbHeader, error) {
	f, err := os.Open(path)
	if err != nil {
		return rdbHeader{}, err
	}
	defer f.Close()
	b := make([]byte, 9)
	n, _ := io.ReadFull(f, b)
	return parseRDBHeader(b[:n])
}

// rdbLoadable tells whether the installed server (version "" = unknown: not checked) loads the snapshot. RDB versions:
// Redis 6.x 9, 7.0 10, 7.2 11, 7.4 / 8.x 12; Valkey 7.2–8.1 write 11, Valkey 9 writes VALKEY080. Valkey refuses Redis'
// versions 12–79 (rdb-version-check strict, its default), Redis anything with the VALKEY magic.
func rdbLoadable(k kvEngine, version string, h rdbHeader) error {
	if h.magic == "VALKEY" {
		if k.name == "redis" {
			return fmt.Errorf("this snapshot (%s) comes from Valkey 9 or newer, which Redis can't load; restore it into a Valkey 9+ instance", h)
		}
		if version != "" && versionLess(version, "9.0") {
			return fmt.Errorf("this snapshot (%s) comes from Valkey 9 or newer, which Valkey %s can't load; restore it into a Valkey 9+ instance", h, version)
		}
		return nil
	}
	if k.name == "valkey" {
		if h.version > 11 {
			return fmt.Errorf("this snapshot (%s, RDB version %d) comes from Redis 7.4 or newer; Valkey only loads snapshots of Redis 7.2 and older (RDB version 11 or lower). Restore it into a Redis instance", h, h.version)
		}
		return nil
	}
	max := 0
	switch {
	case version == "":
	case versionLess(version, "7.0"):
		max = 9
	case versionLess(version, "7.2"):
		max = 10
	case versionLess(version, "7.4"):
		max = 11
	}
	if max > 0 && h.version > max {
		return fmt.Errorf("this snapshot (%s, RDB version %d) comes from a newer Redis than %s, which loads RDB version %d at most; restore it into an instance of a newer Redis", h, h.version, version, max)
	}
	return nil
}

// kvInstance is what the agent knows of an existing instance: how to reach the running process, and the config file
// its next start reads.
type kvInstance struct {
	c           conn
	state       redisState
	hasState    bool
	conf        string
	persistence string // the config file's
}

func (db *DB) kvInstance(k kvEngine, name string) (kvInstance, error) {
	b, err := db.d.FS.ReadFile(k.confPath(name))
	if errors.Is(err, fs.ErrNotExist) {
		return kvInstance{}, fmt.Errorf("%s instance %q does not exist on this server", k.label, name)
	}
	if err != nil {
		return kvInstance{}, err
	}
	parsed := parseRedisConf(b)
	if parsed.port == 0 {
		return kvInstance{}, fmt.Errorf("%s has no port", k.confPath(name))
	}
	inst := kvInstance{c: conn{db: db, k: k, port: parsed.port, password: parsed.password, config: parsed.configName}, conf: string(b), persistence: parsed.persistence}
	if _, err := db.d.FS.ReadFile(db.redisStatePath(k, name)); err == nil {
		inst.state, inst.hasState = db.loadRedisState(k, name), true
	}
	// The state is what runs (written once the process uses it); the file may be ahead of a failed apply.
	if s := inst.state; s.Applied != "" && s.Port > 0 && s.Password != "" {
		inst.c.port, inst.c.password = s.Port, s.Password
		if s.ConfigName != "" {
			inst.c.config = s.ConfigName
		}
	}
	return inst, nil
}

// redisBackup is db.backup of an instance: an RDB snapshot from the running process, gzipped and shipped.
func (db *DB) redisBackup(ctx context.Context, p BackupPayload, st commands.Stream) (any, error) {
	start := time.Now()
	k, err := kvEngineFor(p.Engine)
	if err != nil {
		return nil, err
	}
	if !redisName.MatchString(p.Database) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid instance name %q", p.Database)}
	}
	if err := checkDestination(p.Destination); err != nil {
		return nil, err
	}
	snap, h, err := db.redisSnapshot(ctx, k, p.Database)
	if err != nil {
		return nil, err
	}
	defer os.Remove(snap)
	fmt.Fprintf(st.Stdout(), "snapshot of %s: %s\n", k.unit(p.Database), h)
	result, err := db.ship(ctx, p, start, st, func(out io.Writer) error {
		f, err := os.Open(snap)
		if err != nil {
			return err
		}
		defer f.Close()
		_, err = io.Copy(out, f)
		return err
	})
	if err != nil {
		return nil, err
	}
	result.RDB = h.String()
	return result, nil
}

// redisSnapshot writes an RDB snapshot of the running instance to a temporary file (the caller removes it). Applies,
// removes and restores of the instance wait meanwhile; the upload does not hold them up.
func (db *DB) redisSnapshot(ctx context.Context, k kvEngine, name string) (string, rdbHeader, error) {
	unlock, err := lockInstance(ctx, k, name)
	if err != nil {
		return "", rdbHeader{}, err
	}
	defer unlock()
	inst, err := db.kvInstance(k, name)
	if err != nil {
		return "", rdbHeader{}, err
	}
	unit := k.unit(name)
	if !db.unitActive(ctx, unit) {
		return "", rdbHeader{}, fmt.Errorf("%s is not running, so there is nothing to back up from; start it (re-apply the instance) first", unit)
	}
	if err := inst.c.pingWait(ctx); err != nil {
		return "", rdbHeader{}, fmt.Errorf("%s does not answer: %w", unit, err)
	}
	f, err := os.CreateTemp(db.d.TempDir, "falak-rdb-*")
	if err != nil {
		return "", rdbHeader{}, err
	}
	f.Close()
	file := f.Name()
	res, err := db.d.Runner.Run(ctx, runner.Cmd{
		Name: k.cli,
		Args: []string{"-h", "127.0.0.1", "-p", strconv.Itoa(inst.c.port), "--no-auth-warning", "--rdb", file},
		Env:  []string{"REDISCLI_AUTH=" + inst.c.password},
	})
	if err == nil && res.ExitCode != 0 {
		msg := lastLine(string(res.Stderr) + "\n" + string(res.Stdout))
		if msg == "" {
			msg = fmt.Sprintf("%s exited with %d", k.cli, res.ExitCode)
		}
		err = errors.New(inst.c.redact(msg, nil))
	}
	if err != nil {
		os.Remove(file)
		return "", rdbHeader{}, fmt.Errorf("snapshot of %s: %w", unit, err)
	}
	h, err := readRDBHeader(file)
	if err != nil {
		os.Remove(file)
		return "", rdbHeader{}, fmt.Errorf("snapshot of %s: %s --rdb wrote %w", unit, k.cli, err)
	}
	return file, h, nil
}

func lastLine(s string) string {
	lines := strings.Split(strings.ReplaceAll(s, "\r", ""), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if l := strings.TrimSpace(lines[i]); l != "" {
			return l
		}
	}
	return ""
}

// kvDataFiles are the files a start loads from: the snapshot and the AOF (Redis 7+ / Valkey: a directory; 6.0: a file).
var kvDataFiles = []string{"dump.rdb", "appendonlydir", "appendonly.aof"}

// redisRestore is db.restore into an existing instance (see the top of this file).
func (db *DB) redisRestore(ctx context.Context, p RestorePayload, st commands.Stream) (any, error) {
	start := time.Now()
	k, err := kvEngineFor(p.Engine)
	if err != nil {
		return nil, err
	}
	name := p.Database
	if !redisName.MatchString(name) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid instance name %q", name)}
	}
	if !db.kvInstalled(k) {
		return nil, fmt.Errorf("%s is not installed on this server (no %s@.service unit)", k.label, k.server)
	}
	file, cleanup, err := db.fetchSource(ctx, p)
	if err != nil {
		return nil, err
	}
	defer cleanup()

	unlock, err := lockInstance(ctx, k, name)
	if err != nil {
		return nil, err
	}
	defer unlock()
	inst, err := db.kvInstance(k, name)
	if err != nil {
		return nil, err
	}
	if ok, err := db.realDir(k.dataPath(name)); err != nil {
		return nil, err
	} else if !ok {
		return nil, fmt.Errorf("%s does not exist; re-apply the instance first", k.dataPath(name))
	}
	dataP := db.d.FS.P(k.dataPath(name))
	staged := dataP + "/.falak-restore.rdb"
	if err := os.Remove(staged); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return nil, err
	}
	defer os.Remove(staged)
	size, h, err := stageSnapshot(file, p.Compression, staged)
	if err != nil {
		return nil, err
	}
	version := db.kvVersion(ctx, k)
	if version == "" {
		db.d.Logger.Warn("could not read the server version; the snapshot's RDB version is not checked", "engine", k.name)
	}
	if err := rdbLoadable(k, version, h); err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "snapshot %s, %d bytes\n", h, size)
	if db.d.FS.IsReal() {
		if err := db.d.FS.Chown(k.dataPath(name)+"/.falak-restore.rdb", k.user(name), k.user(name)); err != nil {
			return nil, fmt.Errorf("chown the snapshot: %w", err)
		}
	}

	// An instance with AOF starts once without it, from dump.rdb (Redis ignores the snapshot while AOF is on).
	aof := inst.persistence == "aof"
	first := inst.conf
	if aof {
		if first = strings.Replace(inst.conf, "\nappendonly yes\n", "\nappendonly no\n", 1); first == inst.conf {
			return nil, fmt.Errorf("%s: no `appendonly yes` line to switch off", k.confPath(name))
		}
	}
	if dl, ok := ctx.Deadline(); ok && time.Until(dl) < RedisMinRestartBudget {
		return nil, fmt.Errorf("not enough time left to restart %s safely (%s); nothing was changed", k.unit(name), time.Until(dl).Round(time.Second))
	}

	unit := k.unit(name)
	c := inst.c
	// wasActive only decides whether a rollback starts the instance again.
	wasActive := db.unitActive(ctx, unit)
	if wasActive && c.config != "" {
		// An AOF whose first rewrite still runs can't be stopped cleanly (Redis refuses while writing it): AOF off, and a
		// snapshot of what is there for the way back.
		if incomplete, err := c.aofIncomplete(ctx); err == nil && incomplete {
			if _, err := c.do(ctx, c.config, "SET", "appendonly", "no"); err == nil {
				_, _ = c.do(ctx, "SAVE")
			}
		}
	}
	// Stopped whatever is-active said: a unit waiting for its automatic restart ("activating") is not active, and
	// would start in the middle of the file swap; the stop cancels the pending restart.
	if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"stop", unit}}); err != nil {
		tail := db.journalTail(ctx, unit)
		if wasActive {
			// Nothing was changed yet: whatever the failed stop left is started again (best effort).
			_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}})
		}
		return nil, fmt.Errorf("stop %s: %w; nothing was changed%s", unit, err, tail)
	}

	var moved [][2]string
	installed := false
	rollback := func(cause error) error {
		return db.restoreRollback(ctx, k, name, inst, c, wasActive, installed, moved, cause)
	}
	if moved, err = db.moveAsideNamed(k, name, kvDataFiles...); err != nil {
		return nil, rollback(err)
	}
	dump := dataP + "/dump.rdb"
	if err := os.Rename(staged, dump); err != nil {
		return nil, rollback(fmt.Errorf("install the snapshot: %w", err))
	}
	installed = true
	if err := os.Chmod(dump, 0o600); err != nil {
		return nil, rollback(err)
	}
	if aof {
		if err := db.writeRedisConf(k, name, first); err != nil {
			return nil, rollback(err)
		}
		if inst.hasState {
			// The process runs from the first config until AOF is on: a redelivered apply after a failure here then
			// switches AOF on itself.
			s := inst.state
			s.Applied, s.Persistence = hashOf(first), "rdb"
			if err := db.saveRedisState(k, name, s); err != nil {
				return nil, rollback(err)
			}
		}
	}
	_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
	if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
		return nil, rollback(fmt.Errorf("start %s: %w", unit, err))
	}
	if err := c.ready(ctx); err != nil {
		return nil, rollback(fmt.Errorf("%s did not answer PING on 127.0.0.1:%d: %w", unit, c.port, err))
	}
	if aof {
		if err := db.setPersistence(ctx, c, name, "rdb", "aof"); err != nil {
			return nil, rollback(fmt.Errorf("switch AOF back on: %w", err))
		}
		if err := db.writeRedisConf(k, name, inst.conf); err != nil {
			return nil, err
		}
		if inst.hasState {
			if err := db.saveRedisState(k, name, inst.state); err != nil {
				return nil, err
			}
		}
	}
	if inst.persistence == "none" {
		// Loaded; an instance without persistence keeps nothing on disk (its next restart starts empty, as always).
		_ = os.Remove(dump)
	}
	result := RestoreResult{Bytes: size, DurationMS: time.Since(start).Milliseconds(), RDB: h.String()}
	for _, m := range moved {
		result.MovedAside = append(result.MovedAside, m[1])
	}
	fmt.Fprintf(st.Stdout(), "%s restored from %s; earlier files kept as %s\n", unit, h, strings.Join(result.MovedAside, ", "))
	return result, nil
}

// stageSnapshot writes the (gunzipped) dump to staged (0600, new file) and checks its header.
func stageSnapshot(file, compression, staged string) (int64, rdbHeader, error) {
	in, closeIn, err := openDump(file, compression)
	if err != nil {
		return 0, rdbHeader{}, err
	}
	defer closeIn()
	out, err := os.OpenFile(staged, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return 0, rdbHeader{}, err
	}
	n, err := io.Copy(out, in)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return 0, rdbHeader{}, fmt.Errorf("write the snapshot: %w", err)
	}
	h, err := readRDBHeader(staged)
	if err != nil {
		return 0, rdbHeader{}, fmt.Errorf("the backup is %w", err)
	}
	return n, h, nil
}

// restoreRollback puts the instance back as it was before the restore: the restored files go, the earlier ones come
// back, the earlier config and state are written and, when it ran before, the instance starts again. It runs on its own
// time budget (the command's may be spent).
func (db *DB) restoreRollback(ctx context.Context, k kvEngine, name string, inst kvInstance, c conn, wasActive, installed bool, moved [][2]string, cause error) error {
	rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Minute)
	defer cancel()
	unit := k.unit(name)
	tail := db.journalTail(rctx, unit)
	if c.config != "" && db.unitActive(rctx, unit) {
		_, _ = c.do(rctx, c.config, "SET", "appendonly", "no") // an AOF rewrite would hold up the stop
	}
	// Always: a start that failed may have left an automatic restart pending.
	_, _ = db.d.Runner.Run(rctx, runner.Cmd{Name: "systemctl", Args: []string{"stop", unit}})
	var problems []string
	dataP := db.d.FS.P(k.dataPath(name))
	if installed {
		for _, f := range kvDataFiles {
			if err := os.RemoveAll(dataP + "/" + f); err != nil {
				problems = append(problems, err.Error())
			}
		}
	}
	for _, m := range moved {
		if err := os.Rename(dataP+"/"+m[1], dataP+"/"+m[0]); err != nil {
			problems = append(problems, fmt.Sprintf("put %s back: %v", m[0], err))
		}
	}
	if err := db.writeRedisConf(k, name, inst.conf); err != nil {
		problems = append(problems, err.Error())
	}
	if inst.hasState {
		if err := db.saveRedisState(k, name, inst.state); err != nil {
			problems = append(problems, err.Error())
		}
	}
	if wasActive && len(problems) == 0 {
		_, _ = db.d.Runner.Run(rctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
		if _, err := runner.Check(rctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
			problems = append(problems, fmt.Sprintf("start %s again: %v", unit, err))
		} else if err := c.ready(rctx); err != nil {
			problems = append(problems, fmt.Sprintf("%s did not answer PING again: %v", unit, err))
		}
	}
	if len(problems) > 0 {
		return fmt.Errorf("restore into %s failed (%w), and putting the earlier data back failed too: %s%s", unit, cause, strings.Join(problems, "; "), tail)
	}
	return fmt.Errorf("restore into %s failed, the earlier data is back: %w%s", unit, cause, tail)
}
