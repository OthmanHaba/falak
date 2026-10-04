package db

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/system"
)

// Redis and Valkey instances (db.redis.apply / db.redis.remove, feature db.redis).
//
// Every Kiln instance is its own process of the distribution's template unit: Debian and Ubuntu ship
// redis-server@.service (Type=notify, ExecStart=/usr/bin/redis-server /etc/redis/redis-%i.conf --supervised systemd
// --daemonize no, PIDFile=/run/redis-%i/redis-server.pid, RuntimeDirectory=redis-%i, ProtectSystem=strict) and, where
// the archive has Valkey, valkey-server@.service (the same for Valkey). An instance "cache" is the unit
// redis-server@kiln-cache; its drop-in points ExecStart at /etc/kiln-redis/cache.conf (Debian's /etc/redis is 0770
// redis:redis: the instance user can't read it, and must not join the stock instance's group).
//
// Isolation from the stock instance (6379, no password, same packages) and from other instances:
//   - each instance runs as its own system user (kiln-redis-<name>), set by a drop-in
//     /etc/systemd/system/redis-server@kiln-<name>.service.d/50-kiln.conf that also resets ReadWritePaths= to the
//     instance's data directory and its runtime directory only, and points ExecStart at Kiln's config file;
//   - its data lives in /var/lib/kiln-redis/<name> (0700, the instance user), outside the stock engine's directories,
//     so neither the stock process (user redis, writable /var/lib/redis only) nor another instance can read or write it;
//   - the config file holds the password and is 0640 root:<instance group>.
//
// Commands: DEBUG, MODULE, SHUTDOWN, REPLICAOF, SLAVEOF, MIGRATE, ACL and MONITOR are disabled (MONITOR would show the
// agent's commands); CONFIG is renamed to a random name only the agent knows (kept in its root-only state and in the
// config file), so the agent can change memory, eviction, password and persistence on the running instance. redis-cli
// reads every command from stdin and the password from REDISCLI_AUTH: neither ever appears on a command line.
//
// Persistence changes never lose data: a live switch follows the documented path (AOF on: CONFIG SET appendonly yes and
// wait for the rewrite; AOF off: SAVE first), the instance is SAVEd before any restart (unless its persistence is
// none), stale AOF files are moved aside (timestamped) before AOF is enabled, and a restart that enables AOF starts
// without it (loading dump.rdb) and switches it on live. With persistence none the data is in memory only: every
// restart starts empty (files from earlier modes are moved aside).

var (
	redisName     = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,40}$`)
	redisPassword = regexp.MustCompile(`^[A-Za-z0-9._~-]{12,128}$`)
	redisCfgName  = regexp.MustCompile(`^kiln-config-[a-f0-9]{32}$`)
	redisEviction = []string{"noeviction", "allkeys-lru", "allkeys-lfu", "allkeys-random", "volatile-lru", "volatile-lfu", "volatile-random", "volatile-ttl"}
	redisPersist  = []string{"rdb", "aof", "none"}
	// Disabled for clients. SYNC / PSYNC / REPLCONF stay (redis-cli --rdb backups), EVAL / FUNCTION stay (Laravel).
	// MONITOR, SLOWLOG (and Valkey's COMMANDLOG, see disabledCommands) would show the agent's commands: the secret
	// CONFIG name and, on Redis 6.0, requirepass values.
	redisDisabled = []string{"DEBUG", "MODULE", "SHUTDOWN", "REPLICAOF", "SLAVEOF", "MIGRATE", "ACL", "MONITOR", "SLOWLOG"}
	redisSave     = "3600 1 300 100 60 10000"

	// RedisReadyTimeout bounds the wait for PING after a (re)start; RedisLoadingTimeout while the instance answers
	// LOADING (a big dataset being read back); RedisAOFTimeout bounds an AOF rewrite.
	RedisReadyTimeout   = 30 * time.Second
	RedisLoadingTimeout = 15 * time.Minute
	RedisAOFTimeout     = 15 * time.Minute
	redisPoll           = 500 * time.Millisecond
	redisNow            = time.Now
)

// kvEngine is one key-value flavour as packaged by Debian/Ubuntu.
type kvEngine struct {
	name    string // redis | valkey
	label   string
	server  string // binary / unit prefix
	cli     string
	confDir string // Kiln's own (0755 root): the distribution's /etc/redis is 0770 redis:redis
}

func kvEngineFor(name string) (kvEngine, error) {
	switch name {
	case "redis":
		return kvEngine{"redis", "Redis", "redis-server", "redis-cli", "/etc/kiln-redis"}, nil
	case "valkey":
		return kvEngine{"valkey", "Valkey", "valkey-server", "valkey-cli", "/etc/kiln-valkey"}, nil
	}
	return kvEngine{}, &commands.PayloadError{Err: fmt.Errorf("unknown key-value engine %q", name)}
}

func (k kvEngine) instance(name string) string  { return "kiln-" + name }
func (k kvEngine) unit(name string) string      { return k.server + "@" + k.instance(name) + ".service" }
func (k kvEngine) confPath(name string) string  { return k.confDir + "/" + name + ".conf" }
func (k kvEngine) dataRoot() string             { return "/var/lib/kiln-" + k.name }
func (k kvEngine) dataPath(name string) string  { return k.dataRoot() + "/" + name }
func (k kvEngine) dropInDir(name string) string { return "/etc/systemd/system/" + k.unit(name) + ".d" }
func (k kvEngine) dropIn(name string) string    { return k.dropInDir(name) + "/50-kiln.conf" }
func (k kvEngine) runDir(name string) string    { return "/run/" + k.name + "-" + k.instance(name) }

// user is the instance's system user: kiln-redis-<name> / kiln-valkey-<name>, or past the 32 characters useradd takes
// kiln-rh-<hash> / kiln-vh-<hash>, which no plain name can produce (plain ones always start kiln-redis- / kiln-valkey-).
func (k kvEngine) user(name string) string {
	u := "kiln-" + k.name + "-" + name
	if len(u) <= 32 {
		return u
	}
	sum := sha256.Sum256([]byte(k.name + "\x00" + name))
	return "kiln-" + k.name[:1] + "h-" + hex.EncodeToString(sum[:12])
}

// gecos marks the users the agent creates; only such users are adopted or deleted.
func (k kvEngine) gecos(name string) string { return "Kiln " + k.label + " instance " + name }

// RedisApplyPayload is db.redis.apply.
type RedisApplyPayload struct {
	Engine   string `json:"engine"`
	Name     string `json:"name"`
	Port     int    `json:"port"`
	Password string `json:"password"`
	// Bind: addresses to listen on. 127.0.0.1 is always included (the agent checks the instance there); only
	// loopback, private and WireGuard addresses are accepted (see resolveBind).
	Bind []string `json:"bind,omitempty"`
	// Containers (feature db.redis.network): also listen on the Docker bridge's address (docker0).
	Containers  bool   `json:"containers,omitempty"`
	MaxMemoryMB int    `json:"maxmemory_mb"`
	Eviction    string `json:"eviction"`
	Persistence string `json:"persistence"`
}

// RedisApplyResult is db.redis.apply's result.
type RedisApplyResult struct {
	Changed   bool `json:"changed"`
	Restarted bool `json:"restarted"`
	Port      int  `json:"port"`
	// Bind is what the instance listens on; ContainerHost the address containers use (docker0's), Skipped wanted
	// addresses the host doesn't have yet (feature db.redis.network).
	Bind          []string `json:"bind,omitempty"`
	ContainerHost string   `json:"container_host,omitempty"`
	Skipped       []string `json:"skipped,omitempty"`
}

// RedisRemovePayload is db.redis.remove.
type RedisRemovePayload struct {
	Engine string `json:"engine"`
	Name   string `json:"name"`
}

// redisState is what the agent knows to be live (root-only, 0600): written only once the instance runs with it.
type redisState struct {
	ConfigName  string   `json:"config_name"`
	Applied     string   `json:"applied,omitempty"` // sha256 of the config file the running process uses
	Port        int      `json:"port,omitempty"`
	Bind        []string `json:"bind,omitempty"`
	Persistence string   `json:"persistence,omitempty"`
	Password    string   `json:"password,omitempty"`
	Disabled    string   `json:"disabled,omitempty"` // commands renamed to "" in the running process
}

func (p *RedisApplyPayload) validate() error {
	bad := func(format string, a ...any) error { return &commands.PayloadError{Err: fmt.Errorf(format, a...)} }
	if !redisName.MatchString(p.Name) {
		return bad("invalid name %q", p.Name)
	}
	if p.Port < 1024 || p.Port > 65535 {
		return bad("invalid port %d", p.Port)
	}
	if !redisPassword.MatchString(p.Password) {
		return bad("invalid password (12-128 characters of A-Z a-z 0-9 . _ ~ -)")
	}
	if p.MaxMemoryMB < 16 || p.MaxMemoryMB > 1<<20 {
		return bad("invalid maxmemory_mb %d", p.MaxMemoryMB)
	}
	if !slices.Contains(redisEviction, p.Eviction) {
		return bad("invalid eviction %q", p.Eviction)
	}
	if !slices.Contains(redisPersist, p.Persistence) {
		return bad("invalid persistence %q", p.Persistence)
	}
	return nil
}

// disabledCommands are the commands renamed to "" for this engine version (renaming one the server doesn't know is a
// fatal config error): COMMANDLOG exists from Valkey 8.1.
func disabledCommands(k kvEngine, version string) []string {
	out := append([]string(nil), redisDisabled...)
	if k.name == "valkey" && !versionLess(version, "8.1") {
		out = append(out, "COMMANDLOG")
	}
	return out
}

// renderRedisConf renders the instance configuration (identical input → identical bytes). appendonly is the
// persistence's unless overridden: the first start of a switch to AOF runs with snapshots and without AOF.
func renderRedisConf(k kvEngine, p RedisApplyPayload, bind []string, configName string, appendonly bool, disabled []string) string {
	var b strings.Builder
	w := func(format string, a ...any) { fmt.Fprintf(&b, format+"\n", a...) }
	w("# Managed by the Kiln agent (db.redis.apply): changes are overwritten.")
	w("# %s instance %q, unit %s.", k.label, p.Name, k.unit(p.Name))
	w("port %d", p.Port)
	w("bind %s", strings.Join(bind, " "))
	w("protected-mode yes")
	w("requirepass %q", p.Password)
	w("maxmemory %dmb", p.MaxMemoryMB)
	w("maxmemory-policy %s", p.Eviction)
	if p.Persistence == "rdb" || (p.Persistence == "aof" && !appendonly) {
		// One pair per line: Redis 6.0 (Ubuntu 22.04) does not take several pairs on one save line.
		for _, pair := range [][2]string{{"3600", "1"}, {"300", "100"}, {"60", "10000"}} {
			w("save %s %s", pair[0], pair[1])
		}
	} else {
		w(`save ""`)
	}
	if appendonly {
		w("appendonly yes")
		w("appendfsync everysec")
	} else {
		w("appendonly no")
	}
	w("dir %s", k.dataPath(p.Name))
	w("dbfilename dump.rdb")
	// The template unit starts the server with --supervised systemd --daemonize no and RuntimeDirectory=<name>-%i.
	w("supervised systemd")
	w("daemonize no")
	w("pidfile %s/%s.pid", k.runDir(p.Name), k.server)
	w(`logfile ""`)
	w("tcp-keepalive 300")
	w("timeout 0")
	w("databases 16")
	w("rename-command CONFIG %s", configName)
	for _, c := range disabled {
		w(`rename-command %s ""`, c)
	}
	return b.String()
}

// renderRedisDropIn runs the instance as its own user, from Kiln's config path (the template's /etc/redis is 0770
// redis:redis, which the instance user must not join), writing only its data and runtime directories. The template's
// Type=notify, RuntimeDirectory, PIDFile and sandboxing (ProtectSystem=strict, …) stay.
func renderRedisDropIn(k kvEngine, name string) string {
	user := k.user(name)
	return "# Managed by the Kiln agent (db.redis.apply): changes are overwritten.\n" +
		"[Service]\n" +
		"ExecStart=\n" +
		"ExecStart=/usr/bin/" + k.server + " " + k.confPath(name) + " --supervised systemd --daemonize no\n" +
		"User=" + user + "\n" +
		"Group=" + user + "\n" +
		"ReadWritePaths=\n" +
		"ReadWritePaths=" + k.dataPath(name) + "\n" +
		"ReadWritePaths=-" + k.runDir(name) + "\n" +
		// Type=notify: start waits for READY, sent once the dataset is loaded (the default 90s is short for big ones).
		"TimeoutStartSec=20min\n"
}

// parsedConf is what an instance config on disk says (an instance from before the state file, or lost state).
type parsedConf struct {
	port        int
	bind        []string
	password    string
	configName  string
	persistence string
}

func parseRedisConf(b []byte) parsedConf {
	var c parsedConf
	appendonly, saves, noSave := false, 0, false
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) < 2 || strings.HasPrefix(f[0], "#") {
			continue
		}
		switch strings.ToLower(f[0]) {
		case "port":
			c.port, _ = strconv.Atoi(f[1])
		case "bind":
			c.bind = f[1:]
		case "requirepass":
			c.password, _ = strconv.Unquote(f[1])
		case "rename-command":
			if strings.EqualFold(f[1], "CONFIG") && len(f) > 2 && redisCfgName.MatchString(f[2]) {
				c.configName = f[2]
			}
		case "appendonly":
			appendonly = f[1] == "yes"
		case "save":
			if f[1] == `""` {
				noSave = true
			} else {
				saves++
			}
		}
	}
	switch {
	case c.port == 0:
	case appendonly:
		c.persistence = "aof"
	case noSave && saves == 0:
		c.persistence = "none"
	default:
		c.persistence = "rdb"
	}
	return c
}

func (db *DB) kvInstalled(k kvEngine) bool {
	for _, dir := range []string{"/usr/lib/systemd/system", "/lib/systemd/system", "/etc/systemd/system"} {
		if db.d.FS.Exists(dir + "/" + k.server + "@.service") {
			return true
		}
	}
	return false
}

func (db *DB) redisStatePath(k kvEngine, name string) string {
	return strings.TrimRight(db.d.StateDir, "/") + "/db/redis/" + k.name + "-" + name + ".json"
}

func (db *DB) loadRedisState(k kvEngine, name string) redisState {
	var s redisState
	if b, err := db.d.FS.ReadFile(db.redisStatePath(k, name)); err == nil {
		_ = json.Unmarshal(b, &s)
	}
	if !redisCfgName.MatchString(s.ConfigName) {
		s.ConfigName = ""
	}
	return s
}

func (db *DB) saveRedisState(k kvEngine, name string, s redisState) error {
	b, _ := json.MarshalIndent(s, "", "  ")
	dir := strings.TrimRight(db.d.StateDir, "/") + "/db/redis"
	if err := db.d.FS.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	_, err := db.d.FS.WriteFile(db.redisStatePath(k, name), b, 0o600)
	return err
}

func newConfigName() (string, error) {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return "kiln-config-" + hex.EncodeToString(b), nil
}

func hashOf(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}

// RedisApply converges one instance: user, drop-in, data directory, configuration, enabled and running unit with
// the wanted settings (applied live when possible, else by a restart that keeps the data), PING with the password.
// Applies and removes of one instance never run at the same time.
func (db *DB) RedisApply(ctx context.Context, p RedisApplyPayload, st commands.Stream) (any, error) {
	k, err := kvEngineFor(p.Engine)
	if err != nil {
		return nil, err
	}
	if err := p.validate(); err != nil {
		return nil, err
	}
	resolved, err := db.resolveBind(p.Bind, p.Containers)
	if err != nil {
		return nil, err
	}
	bind := resolved.addrs
	result := func(r RedisApplyResult) RedisApplyResult {
		r.Port, r.Bind, r.ContainerHost, r.Skipped = p.Port, bind, resolved.containerHost, resolved.skipped
		return r
	}
	if !db.kvInstalled(k) {
		return nil, fmt.Errorf("%s is not installed on this server (no %s@.service unit); install it first", k.label, k.server)
	}
	unlock, err := lockInstance(ctx, k, p.Name)
	if err != nil {
		return nil, err
	}
	defer unlock()

	unit, confPath, user := k.unit(p.Name), k.confPath(p.Name), k.user(p.Name)
	disabled, err := db.kvDisabled(ctx, k)
	if err != nil {
		return nil, err
	}
	state := db.loadRedisState(k, p.Name)
	var onDisk []byte
	if b, err := db.d.FS.ReadFile(confPath); err == nil {
		onDisk = b
	}
	old := parseRedisConf(onDisk)
	if state.ConfigName == "" {
		state.ConfigName = old.configName
	}
	if state.ConfigName == "" {
		if state.ConfigName, err = newConfigName(); err != nil {
			return nil, err
		}
	}
	desired := renderRedisConf(k, p, bind, state.ConfigName, p.Persistence == "aof", disabled)
	dropIn := renderRedisDropIn(k, p.Name)
	want := conn{db: db, k: k, port: p.Port, password: p.Password, config: state.ConfigName}
	active := db.unitActive(ctx, unit)

	// Already live: the marker is only written once the running process uses exactly this file.
	if active && state.Applied == hashOf(desired) && string(onDisk) == desired && db.fileIs(k.dropIn(p.Name), dropIn) && want.pingWait(ctx) == nil {
		return result(RedisApplyResult{}), nil
	}

	if err := db.ensureInstanceUser(ctx, k, p.Name); err != nil {
		return nil, err
	}
	if err := db.ensureDataDir(k, p.Name); err != nil {
		return nil, err
	}
	dropInChanged, err := db.d.FS.WriteFile(k.dropIn(p.Name), []byte(dropIn), 0o644)
	if err != nil {
		return nil, fmt.Errorf("write %s: %w", k.dropIn(p.Name), err)
	}
	if dropInChanged {
		if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return nil, fmt.Errorf("systemctl daemon-reload: %w", err)
		}
	}

	// How to reach the running process: the state when known, else its config file (state lost or older agent).
	prev := redisState{Port: state.Port, Bind: state.Bind, Password: state.Password, ConfigName: state.ConfigName, Disabled: state.Disabled}
	if state.Applied == "" {
		prev = redisState{Port: old.port, Bind: old.bind, Password: old.password, ConfigName: old.configName}
	}
	var live *conn
	if active && prev.Port > 0 {
		for _, pw := range []string{p.Password, prev.Password} {
			c := conn{db: db, k: k, port: prev.Port, password: pw, config: prev.ConfigName}
			if pw != "" && c.pingWait(ctx) == nil {
				live = &c
				break
			}
		}
	}
	// The persistence the data is in right now: asked from the running process (the state file lags behind a live
	// switch that failed half way), else what its config file starts it with. Files are only moved aside from this.
	from := old.persistence
	if live != nil && prev.ConfigName != "" {
		if mode, err := live.liveMode(ctx); err == nil {
			from = mode
		}
	}
	if from == "" {
		from = state.Persistence
	}
	final := redisState{ConfigName: state.ConfigName, Applied: hashOf(desired), Port: p.Port, Bind: bind, Persistence: p.Persistence, Password: p.Password, Disabled: strings.Join(disabled, " ")}

	// Live: memory, eviction, password and persistence change on the running process; the file then matches it.
	// Renamed commands, port, bind and the drop-in only change with a restart.
	if live != nil && !dropInChanged && prev.Port == p.Port && slices.Equal(prev.Bind, bind) && prev.ConfigName == state.ConfigName &&
		prev.ConfigName != "" && prev.Disabled == final.Disabled {
		err := db.applyLive(ctx, *live, p, from)
		if err == nil {
			if err := db.writeRedisConf(k, p.Name, desired); err != nil {
				return nil, err
			}
			if err := db.saveRedisState(k, p.Name, final); err != nil {
				return nil, err
			}
			return result(RedisApplyResult{Changed: true}), nil
		}
		// A wait that ran out of time (the rewrite or the load continues) or a cancelled command is not a reason to
		// restart: a restart would interrupt the rewrite with little time left. The redelivery waits again.
		if ctx.Err() != nil || errors.Is(err, errWaitTimeout) {
			return nil, fmt.Errorf("change %s: %w", unit, err)
		}
		db.d.Logger.Warn("live redis change failed, restarting the instance", "unit", unit, "err", err)
		if st != nil {
			fmt.Fprintf(st.Stdout(), "live change failed (%v); restarting %s\n", err, unit)
		}
		if live.password != p.Password && want.ping(ctx) == nil {
			live.password = p.Password // the password change went through before the failure
		}
		if mode, err := live.liveMode(ctx); err == nil {
			from = mode
		}
	}

	// Restart: another port, bind, drop-in or renamed commands, the instance not running, or a live change that failed.
	if dl, ok := ctx.Deadline(); ok && time.Until(dl) < RedisMinRestartBudget {
		return nil, fmt.Errorf("not enough time left to restart %s safely (%s); the command is retried", unit, time.Until(dl).Round(time.Second))
	}
	if live != nil && from == "aof" {
		// AOF on but not complete (its first rewrite still running or failed): the AOF can't be loaded. Switch it off
		// (ends the rewrite; Redis also refuses to stop while writing it) and restart from a snapshot instead.
		if incomplete, err := live.aofIncomplete(ctx); err != nil {
			return nil, fmt.Errorf("check %s's AOF: %w", unit, err)
		} else if incomplete {
			if _, err := live.do(ctx, live.config, "SET", "appendonly", "no"); err != nil {
				return nil, fmt.Errorf("switch the unfinished AOF off on %s: %w", unit, err)
			}
			from = "rdb"
		}
	} else if live == nil && from == "aof" && !db.aofFilesComplete(k, p.Name) {
		from = "rdb" // the stopped instance's AOF has no manifest: its first rewrite never finished
	}
	if !active || prev.Port != p.Port {
		if who, err := db.portUser(ctx, p.Port, unit); err != nil {
			return nil, err
		} else if who != "" {
			return nil, fmt.Errorf("port %d is in use by %s", p.Port, who)
		}
	}
	if live != nil {
		// The stop must not undo the change: with none it must not write a snapshot on its way down; otherwise it
		// writes one (save points set live), so nothing written after the SAVE below is lost either.
		if err := db.prepareStop(ctx, *live, from, p.Persistence); err != nil {
			return nil, fmt.Errorf("prepare %s for its restart: %w", unit, err)
		}
	}
	if active {
		if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"stop", unit}}); err != nil {
			return nil, fmt.Errorf("stop %s: %w%s", unit, err, db.journalTail(ctx, unit))
		}
	}
	// Stopped: nothing writes the data directory now. Move aside what the next start must not load.
	if err := db.moveStale(k, p.Name, from, p.Persistence); err != nil {
		return nil, err
	}
	twoPhase := p.Persistence == "aof" && from != "aof"
	first := desired
	if twoPhase {
		first = renderRedisConf(k, p, bind, state.ConfigName, false, disabled)
	}
	if err := db.writeRedisConf(k, p.Name, first); err != nil {
		return nil, err
	}
	if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"enable", "--quiet", unit}}); err != nil {
		return nil, fmt.Errorf("enable %s: %w", unit, err)
	}
	_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
	if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
		return nil, fmt.Errorf("start %s: %w%s", unit, err, db.journalTail(ctx, unit))
	}
	// The process now runs with the first config: recorded at once, so a redelivery after a failure below (still
	// loading, AOF rewrite not done) reaches it on its new port with its password, and knows its mode.
	started := final
	if twoPhase {
		started.Applied, started.Persistence = hashOf(first), "rdb"
	}
	if err := db.saveRedisState(k, p.Name, started); err != nil {
		return nil, err
	}
	if err := want.ready(ctx); err != nil {
		return nil, fmt.Errorf("%s did not answer PING on 127.0.0.1:%d: %w%s", unit, p.Port, err, db.journalTail(ctx, unit))
	}
	if twoPhase {
		// Started from dump.rdb with snapshots and without AOF; AOF is switched on live (rewrite from memory), then
		// the file says so.
		if err := db.setPersistence(ctx, want, p.Name, "rdb", "aof"); err != nil {
			return nil, fmt.Errorf("enable AOF on %s: %w", unit, err)
		}
		if err := db.writeRedisConf(k, p.Name, desired); err != nil {
			return nil, err
		}
	}
	if err := db.saveRedisState(k, p.Name, final); err != nil {
		return nil, err
	}
	if st != nil {
		fmt.Fprintf(st.Stdout(), "%s (user %s) listening on %d\n", unit, user, p.Port)
	}
	return result(RedisApplyResult{Changed: true, Restarted: true}), nil
}

// prepareStop makes the coming stop keep or drop the data as the new persistence wants.
func (db *DB) prepareStop(ctx context.Context, c conn, from, to string) error {
	set := func(key, value string) error {
		_, err := c.do(ctx, c.config, "SET", key, value)
		return err
	}
	if c.config == "" {
		// An instance from before the renamed CONFIG: only a snapshot is possible.
		if to != "none" {
			_, err := c.do(ctx, "SAVE")
			return err
		}
		return nil
	}
	if to == "none" {
		if err := set("save", ""); err != nil {
			return err
		}
		if from == "aof" {
			return set("appendonly", "no")
		}
		return nil
	}
	if err := set("save", redisSave); err != nil {
		return err
	}
	_, err := c.do(ctx, "SAVE")
	return err
}

// kvDisabled lists the commands to disable for the installed server version (Valkey's COMMANDLOG from 8.1).
func (db *DB) kvDisabled(ctx context.Context, k kvEngine) ([]string, error) {
	if k.name != "valkey" {
		return disabledCommands(k, ""), nil
	}
	cctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	res, err := db.d.Runner.Run(cctx, runner.Cmd{Name: db.d.FS.P("/usr/bin/" + k.server), Args: []string{"--version"}})
	if err == nil && res.ExitCode == 0 {
		for _, w := range strings.Fields(string(res.Stdout)) {
			if v, ok := strings.CutPrefix(w, "v="); ok && v != "" {
				return disabledCommands(k, v), nil
			}
		}
	}
	return nil, fmt.Errorf("could not read the %s version (%s --version)", k.label, k.server)
}

// applyLive changes the running instance without a restart.
func (db *DB) applyLive(ctx context.Context, c conn, p RedisApplyPayload, prevPersistence string) error {
	for _, kv := range [][2]string{{"maxmemory", strconv.Itoa(p.MaxMemoryMB) + "mb"}, {"maxmemory-policy", p.Eviction}} {
		if _, err := c.do(ctx, c.config, "SET", kv[0], kv[1]); err != nil {
			return err
		}
	}
	if p.Password != c.password {
		if _, err := c.do(ctx, c.config, "SET", "requirepass", p.Password); err != nil {
			return err
		}
		c.password = p.Password
	}
	return db.setPersistence(ctx, c, p.Name, prevPersistence, p.Persistence)
}

// setPersistence switches a running instance between rdb, aof and none the documented way.
func (db *DB) setPersistence(ctx context.Context, c conn, name, from, to string) error {
	set := func(key, value string) error {
		_, err := c.do(ctx, c.config, "SET", key, value)
		return err
	}
	switch to {
	case "rdb":
		if err := set("save", redisSave); err != nil {
			return err
		}
		if from != "rdb" {
			// Snapshot now: from AOF before switching it off, from none to persist what is in memory.
			if _, err := c.do(ctx, "SAVE"); err != nil {
				return err
			}
		}
		if from == "aof" {
			return set("appendonly", "no")
		}
		return nil
	case "aof":
		// from is the live process' mode: a running AOF (aof_enabled:1, even mid-rewrite) is never moved.
		if from != "aof" {
			if err := db.moveAside(c.k, name, "appendonlydir", "appendonly.aof"); err != nil {
				return err
			}
			if err := set("appendonly", "yes"); err != nil {
				return err
			}
		}
		if err := c.waitAOFRewrite(ctx); err != nil {
			return err
		}
		return set("save", "")
	default: // none
		if err := set("save", ""); err != nil {
			return err
		}
		if from == "aof" {
			if err := set("appendonly", "no"); err != nil {
				return err
			}
		}
		// Files from the earlier mode (or an app's BGSAVE) would come back on the next restart: moved aside, every time.
		return db.moveAside(c.k, name, "dump.rdb", "appendonlydir", "appendonly.aof")
	}
}

// moveStale moves files aside that the next start must not load: an old AOF when AOF gets switched on (Redis would
// load it instead of dump.rdb), everything when the instance keeps nothing.
func (db *DB) moveStale(k kvEngine, name, from, to string) error {
	switch {
	case to == "aof" && from != "aof":
		return db.moveAside(k, name, "appendonlydir", "appendonly.aof")
	case to == "none":
		return db.moveAside(k, name, "dump.rdb", "appendonlydir", "appendonly.aof")
	}
	return nil
}

func (db *DB) moveAside(k kvEngine, name string, files ...string) error {
	stamp := redisNow().UTC().Format("20060102T150405Z")
	for _, f := range files {
		path := db.d.FS.P(k.dataPath(name) + "/" + f)
		if _, err := os.Lstat(path); errors.Is(err, fs.ErrNotExist) {
			continue
		}
		target := path + ".kiln-" + stamp
		for i := 2; exists(target); i++ {
			target = path + ".kiln-" + stamp + "-" + strconv.Itoa(i)
		}
		if err := os.Rename(path, target); err != nil {
			return fmt.Errorf("move %s aside: %w", f, err)
		}
	}
	return nil
}

func exists(path string) bool {
	_, err := os.Lstat(path)
	return err == nil
}

func (db *DB) writeRedisConf(k kvEngine, name, content string) error {
	path := k.confPath(name)
	if ok, err := db.realDir(k.confDir); err != nil {
		return err
	} else if !ok {
		if err := db.d.FS.MkdirAll(k.confDir, 0o755); err != nil {
			return fmt.Errorf("create %s: %w", k.confDir, err)
		}
	}
	if err := db.notSymlink(path); err != nil {
		return err
	}
	if _, err := db.d.FS.WriteFile(path, []byte(content), 0o640); err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}
	if db.d.FS.IsReal() {
		if err := db.d.FS.Chown(path, "root", k.user(name)); err != nil {
			return fmt.Errorf("chown %s: %w", path, err)
		}
	}
	return nil
}

func (db *DB) fileIs(path, content string) bool {
	b, err := db.d.FS.ReadFile(path)
	return err == nil && string(b) == content
}

// notSymlink refuses a path that exists as anything but a regular file (never write or chown through a link).
func (db *DB) notSymlink(path string) error {
	fi, err := os.Lstat(db.d.FS.P(path))
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	if !fi.Mode().IsRegular() {
		return fmt.Errorf("%s is not a regular file", path)
	}
	return nil
}

// realDir checks that path is a directory itself (not a symlink to one).
func (db *DB) realDir(path string) (bool, error) {
	fi, err := os.Lstat(db.d.FS.P(path))
	if errors.Is(err, fs.ErrNotExist) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if !fi.IsDir() {
		return false, fmt.Errorf("%s exists and is not a directory", path)
	}
	return true, nil
}

func (db *DB) ensureDataDir(k kvEngine, name string) error {
	root, data := k.dataRoot(), k.dataPath(name)
	if ok, err := db.realDir(root); err != nil {
		return err
	} else if !ok {
		if err := db.d.FS.MkdirAll("/var/lib", 0o755); err != nil {
			return err
		}
		if err := os.Mkdir(db.d.FS.P(root), 0o755); err != nil && !errors.Is(err, fs.ErrExist) {
			return fmt.Errorf("create %s: %w", root, err)
		}
	}
	if ok, err := db.realDir(data); err != nil {
		return err
	} else if !ok {
		if err := os.Mkdir(db.d.FS.P(data), 0o700); err != nil {
			return fmt.Errorf("create %s: %w", data, err)
		}
	}
	if err := os.Chmod(db.d.FS.P(data), 0o700); err != nil {
		return fmt.Errorf("chmod %s: %w", data, err)
	}
	if db.d.FS.IsReal() {
		if err := db.d.FS.Chown(data, k.user(name), k.user(name)); err != nil {
			return fmt.Errorf("chown %s: %w", data, err)
		}
	}
	return nil
}

// instanceUser looks the user up: exists, and whether it is the one the agent created for this instance (Kiln's GECOS,
// home /nonexistent, nologin shell). Any other user of that name (a site user, someone's account) is never adopted or
// deleted.
func (db *DB) instanceUser(ctx context.Context, k kvEngine, name string) (exists, ours bool, err error) {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "getent", Args: []string{"passwd", k.user(name)}})
	if err != nil {
		return false, false, err
	}
	if res.ExitCode == 2 {
		return false, false, nil
	}
	if res.ExitCode != 0 {
		return false, false, fmt.Errorf("getent passwd %s: exit status %d", k.user(name), res.ExitCode)
	}
	f := strings.Split(strings.TrimSpace(firstLine(string(res.Stdout))), ":")
	ours = len(f) == 7 && f[0] == k.user(name) && f[4] == k.gecos(name) && f[5] == "/nonexistent" && (f[6] == "/usr/sbin/nologin" || f[6] == "/sbin/nologin")
	return true, ours, nil
}

func (db *DB) ensureInstanceUser(ctx context.Context, k kvEngine, name string) error {
	system.AccountsMu.Lock()
	defer system.AccountsMu.Unlock()
	user := k.user(name)
	exists, ours, err := db.instanceUser(ctx, k, name)
	if err != nil {
		return err
	}
	if exists {
		if !ours {
			return fmt.Errorf("a user named %s already exists and was not created by Kiln for this instance; rename the instance", user)
		}
		return nil
	}
	_, err = runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "useradd", Args: []string{
		"--system", "--user-group", "--no-create-home", "--home-dir", "/nonexistent", "--shell", "/usr/sbin/nologin",
		"--comment", k.gecos(name), user,
	}})
	if err != nil {
		return fmt.Errorf("create user %s: %w", user, err)
	}
	return nil
}

// RedisRemove stops and disables the instance and deletes its drop-in, configuration, data, user and state.
func (db *DB) RedisRemove(ctx context.Context, p RedisRemovePayload, _ commands.Stream) (any, error) {
	k, err := kvEngineFor(p.Engine)
	if err != nil {
		return nil, err
	}
	if !redisName.MatchString(p.Name) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid name %q", p.Name)}
	}
	unlock, err := lockInstance(ctx, k, p.Name)
	if err != nil {
		return nil, err
	}
	defer unlock()
	unit := k.unit(p.Name)
	changed := db.unitActive(ctx, unit)
	// Not an error when the unit was never started or the template is gone with the package.
	_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"disable", "--now", "--quiet", unit}})
	if db.unitActive(ctx, unit) {
		return nil, fmt.Errorf("could not stop %s", unit)
	}
	if _, err := os.Lstat(db.d.FS.P(k.dropInDir(p.Name))); err == nil {
		if err := os.RemoveAll(db.d.FS.P(k.dropInDir(p.Name))); err != nil {
			return nil, fmt.Errorf("remove %s: %w", k.dropInDir(p.Name), err)
		}
		_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"daemon-reload"}})
		changed = true
	}
	for _, path := range []string{k.confPath(p.Name), db.redisStatePath(k, p.Name)} {
		removed, err := db.d.FS.Remove(path)
		if err != nil {
			return nil, fmt.Errorf("remove %s: %w", path, err)
		}
		changed = changed || removed
	}
	// RemoveAll never follows a symlink at the path itself; the parent is root's.
	data := db.d.FS.P(k.dataPath(p.Name))
	if _, err := os.Lstat(data); err == nil {
		if err := os.RemoveAll(data); err != nil {
			return nil, fmt.Errorf("remove %s: %w", k.dataPath(p.Name), err)
		}
		changed = true
	}
	system.AccountsMu.Lock()
	defer system.AccountsMu.Unlock()
	if exists, ours, err := db.instanceUser(ctx, k, p.Name); err != nil {
		return nil, err
	} else if exists && ours {
		if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "userdel", Args: []string{k.user(p.Name)}}); err != nil {
			return nil, fmt.Errorf("remove user %s: %w", k.user(p.Name), err)
		}
		changed = true
	} else if exists {
		db.d.Logger.Warn("not removing a user Kiln did not create for this instance", "user", k.user(p.Name))
	}
	return ChangedResult{Changed: changed}, nil
}

func (db *DB) unitActive(ctx context.Context, unit string) bool {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-active", "--quiet", unit}})
	return err == nil && res.ExitCode == 0
}

var ssUserRe = regexp.MustCompile(`\("([^"]+)",pid=(\d+)`)

// portUser names the process listening on TCP port (any address), "" when it is free or held by the instance's own
// unit (its MainPID: an earlier apply that started it there and failed later, e.g. while it loaded).
func (db *DB) portUser(ctx context.Context, port int, unit string) (string, error) {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "ss", Args: []string{"-H", "-ltnp", "sport = :" + strconv.Itoa(port)}})
	if err != nil {
		return "", fmt.Errorf("check port %d: %w", port, err)
	}
	if res.ExitCode != 0 {
		return "", fmt.Errorf("check port %d: ss exited with %d", port, res.ExitCode)
	}
	out := strings.TrimSpace(string(res.Stdout))
	if out == "" {
		return "", nil
	}
	users := ssUserRe.FindAllStringSubmatch(out, -1)
	if len(users) == 0 {
		return "another process", nil
	}
	own := db.mainPID(ctx, unit)
	for _, m := range users {
		if m[2] != own {
			return fmt.Sprintf("%s (pid %s)", m[1], m[2]), nil
		}
	}
	return "", nil
}

// mainPID is the unit's main process id ("" when it has none).
func (db *DB) mainPID(ctx context.Context, unit string) string {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"show", "--property=MainPID", "--value", unit}})
	if err != nil || res.ExitCode != 0 {
		return ""
	}
	if pid := strings.TrimSpace(string(res.Stdout)); pid != "" && pid != "0" {
		return pid
	}
	return ""
}

func (db *DB) journalTail(ctx context.Context, unit string) string {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "journalctl", Args: []string{"-u", unit, "-n", "15", "--no-pager", "-o", "cat"}})
	if err != nil || res.ExitCode != 0 {
		return ""
	}
	if out := strings.TrimSpace(string(res.Stdout)); out != "" {
		return "\n" + out
	}
	return ""
}

// conn talks to one instance through redis-cli: the command on stdin, the password in REDISCLI_AUTH.
type conn struct {
	db       *DB
	k        kvEngine
	port     int
	password string
	config   string // the instance's CONFIG name
}

// replyError matches error replies redis-cli prints in raw mode (its output when stdout is not a terminal).
var replyError = regexp.MustCompile(`^(ERR|WRONGPASS|NOAUTH|NOPERM|LOADING|MISCONF|BUSY|READONLY|OOM|EXECABORT|NOREPLICAS|MASTERDOWN|UNKILLABLE)\b`)

// RedisError is an error reply of the instance, redacted: the secret CONFIG name and every argument after the
// command (and its subcommand) never reach errors, logs or the control plane.
type RedisError struct{ Reply string }

func (e *RedisError) Error() string { return e.Reply }

func quoteArg(s string) string {
	return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`).Replace(s) + `"`
}

func (c conn) do(ctx context.Context, args ...string) (string, error) {
	quoted := make([]string, len(args))
	for i, a := range args {
		quoted[i] = quoteArg(a)
	}
	cctx, cancel := context.WithTimeout(ctx, 10*time.Minute)
	defer cancel()
	res, err := c.db.d.Runner.Run(cctx, runner.Cmd{
		Name:  c.k.cli,
		Args:  []string{"-h", "127.0.0.1", "-p", strconv.Itoa(c.port), "--no-auth-warning"},
		Env:   []string{"REDISCLI_AUTH=" + c.password},
		Stdin: strings.NewReader(strings.Join(quoted, " ") + "\n"),
	})
	if err != nil {
		return "", err
	}
	out := strings.TrimSpace(strings.ReplaceAll(string(res.Stdout), "\r", ""))
	if first := firstLine(out); replyError.MatchString(first) {
		return "", &RedisError{Reply: c.redact(first, args)}
	}
	if res.ExitCode != 0 {
		msg := strings.TrimSpace(string(res.Stderr))
		if msg == "" {
			msg = fmt.Sprintf("%s exited with %d", c.k.cli, res.ExitCode)
		}
		return "", errors.New(c.redact(firstLine(msg), args))
	}
	return out, nil
}

// redact removes the secret CONFIG name, the passwords and the command's arguments (values) from a message. The
// arguments Redis echoes ("…, with args beginning with: …", truncated so they may not match) are cut off.
func (c conn) redact(msg string, args []string) string {
	if i := strings.Index(msg, ", with args beginning with"); i >= 0 {
		msg = msg[:i]
	}
	var secrets []string
	for i, a := range args {
		if i >= 2 && a != "" {
			secrets = append(secrets, a)
		}
	}
	secrets = append(secrets, c.password)
	slices.SortFunc(secrets, func(a, b string) int { return len(b) - len(a) })
	for _, s := range secrets {
		if len(s) >= 3 {
			msg = strings.ReplaceAll(msg, s, "***")
		}
	}
	if c.config != "" {
		msg = strings.ReplaceAll(msg, c.config, "CONFIG")
	}
	return msg
}

// lockInstance serializes applies and removes of one instance (the dispatcher runs commands concurrently). Waiting
// for it ends with the command's context.
func lockInstance(ctx context.Context, k kvEngine, name string) (func(), error) {
	m, _ := instanceLocks.LoadOrStore(k.name+"/"+name, make(chan struct{}, 1))
	sem := m.(chan struct{})
	select {
	case sem <- struct{}{}:
		return func() { <-sem }, nil
	case <-ctx.Done():
		return nil, fmt.Errorf("waiting for another command on %s instance %q: %w", k.label, name, ctx.Err())
	}
}

var instanceLocks sync.Map

// within bounds a wait by the command's deadline (a little before it, so the wait fails with its own reason).
func within(ctx context.Context, d time.Duration) time.Time {
	limit := redisNow().Add(d)
	if dl, ok := ctx.Deadline(); ok {
		if dl = dl.Add(-5 * time.Second); dl.Before(limit) {
			return dl
		}
	}
	return limit
}

// errWaitTimeout marks a wait that ran out of time while the instance kept working (rewrite, load).
var errWaitTimeout = errors.New("timed out waiting")

// RedisMinRestartBudget is the time a command must have left to start a restart (stop with its final snapshot, start,
// load); with less it fails and is retried rather than leaving an instance stopped.
var RedisMinRestartBudget = 2 * time.Minute

// aofIncomplete tells whether the running process' AOF can't be loaded yet: its first rewrite is running, scheduled or
// failed.
func (c conn) aofIncomplete(ctx context.Context) (bool, error) {
	m, err := c.info(ctx, "persistence")
	if err != nil {
		return false, err
	}
	if m["aof_enabled"] != "1" {
		return false, nil
	}
	return m["aof_rewrite_in_progress"] == "1" || m["aof_rewrite_scheduled"] == "1" ||
		(m["aof_last_bgrewrite_status"] != "" && m["aof_last_bgrewrite_status"] != "ok"), nil
}

// aofFilesComplete tells whether a stopped instance's data has a loadable AOF: Redis 7+ / Valkey write a manifest
// once the first rewrite finished (Redis 6.0's single appendonly.aof can't be told apart and counts as complete).
func (db *DB) aofFilesComplete(k kvEngine, name string) bool {
	dir := db.d.FS.P(k.dataPath(name))
	if exists(dir + "/appendonlydir") {
		return exists(dir + "/appendonlydir/appendonly.aof.manifest")
	}
	return exists(dir + "/appendonly.aof")
}

// pingWait pings; an instance answering LOADING is waited for (ready) instead of being taken as unreachable.
func (c conn) pingWait(ctx context.Context) error {
	err := c.ping(ctx)
	var re *RedisError
	if errors.As(err, &re) && strings.HasPrefix(re.Reply, "LOADING") {
		return c.ready(ctx)
	}
	return err
}

// liveMode is the persistence the running process actually has: aof when AOF is on (or its rewrite is running), else
// rdb when it has save points, else none.
func (c conn) liveMode(ctx context.Context) (string, error) {
	m, err := c.info(ctx, "persistence")
	if err != nil {
		return "", err
	}
	if m["aof_enabled"] == "1" {
		return "aof", nil
	}
	out, err := c.do(ctx, c.config, "GET", "save")
	if err != nil {
		return "", err
	}
	lines := strings.Split(out, "\n")
	if len(lines) < 2 || strings.TrimSpace(lines[1]) == "" {
		return "none", nil
	}
	return "rdb", nil
}

func (c conn) ping(ctx context.Context) error {
	out, err := c.do(ctx, "PING")
	if err != nil {
		return err
	}
	if out != "PONG" {
		if out == "" {
			return errors.New("no answer")
		}
		return errors.New(firstLine(out))
	}
	return nil
}

// ready waits until the instance answers PING with the password. LOADING (a dataset being read back) counts as
// progress and extends the wait up to RedisLoadingTimeout.
func (c conn) ready(ctx context.Context) error {
	deadline := within(ctx, RedisReadyTimeout)
	loading := false
	for {
		err := c.ping(ctx)
		if err == nil {
			return nil
		}
		var re *RedisError
		if !loading && errors.As(err, &re) && strings.HasPrefix(re.Reply, "LOADING") {
			loading = true // once: the loading budget counts from the first LOADING
			deadline = within(ctx, RedisLoadingTimeout)
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if redisNow().After(deadline) {
			if loading {
				return fmt.Errorf("%w: still loading its dataset", errWaitTimeout)
			}
			return err
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(redisPoll):
		}
	}
}

func (c conn) info(ctx context.Context, section string) (map[string]string, error) {
	out, err := c.do(ctx, "INFO", section)
	if err != nil {
		return nil, err
	}
	m := map[string]string{}
	for _, line := range strings.Split(out, "\n") {
		if k, v, ok := strings.Cut(strings.TrimSpace(line), ":"); ok {
			m[k] = v
		}
	}
	return m, nil
}

// waitAOFRewrite waits for the rewrite CONFIG SET appendonly yes started (the AOF then holds the dataset).
func (c conn) waitAOFRewrite(ctx context.Context) error {
	deadline := within(ctx, RedisAOFTimeout)
	for {
		m, err := c.info(ctx, "persistence")
		if err != nil {
			return err
		}
		if m["aof_enabled"] == "1" && m["aof_rewrite_in_progress"] == "0" && m["aof_rewrite_scheduled"] == "0" {
			if s := m["aof_last_bgrewrite_status"]; s != "" && s != "ok" {
				return fmt.Errorf("AOF rewrite failed (%s)", s)
			}
			return nil
		}
		if redisNow().After(deadline) {
			return fmt.Errorf("%w: the AOF rewrite is still running", errWaitTimeout)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(redisPoll):
		}
	}
}

func firstLine(s string) string {
	line, _, _ := strings.Cut(s, "\n")
	return line
}
