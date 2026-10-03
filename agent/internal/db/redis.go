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
	"net"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
)

// Redis and Valkey instances (db.redis.apply / db.redis.remove, feature db.redis).
//
// Every Kiln instance is its own process of the distribution's template unit: Debian and Ubuntu ship
// redis-server@.service (Type=notify, ExecStart=/usr/bin/redis-server /etc/redis/redis-%i.conf --supervised systemd
// --daemonize no, PIDFile=/run/redis-%i/redis-server.pid, RuntimeDirectory=redis-%i, ProtectSystem=strict) and, where
// the archive has Valkey, valkey-server@.service (the same with /etc/valkey/valkey-%i.conf). An instance "cache" is the
// unit redis-server@kiln-cache with /etc/redis/redis-kiln-cache.conf.
//
// Isolation from the stock instance (6379, no password, same packages) and from other instances:
//   - each instance runs as its own system user (kiln-redis-<name>), set by a drop-in
//     /etc/systemd/system/redis-server@kiln-<name>.service.d/50-kiln.conf that also resets ReadWritePaths= to the
//     instance's data directory and its runtime directory only;
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
	redisDisabled = []string{"DEBUG", "MODULE", "SHUTDOWN", "REPLICAOF", "SLAVEOF", "MIGRATE", "ACL", "MONITOR"}
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
	confDir string
}

func kvEngineFor(name string) (kvEngine, error) {
	switch name {
	case "redis":
		return kvEngine{"redis", "Redis", "redis-server", "redis-cli", "/etc/redis"}, nil
	case "valkey":
		return kvEngine{"valkey", "Valkey", "valkey-server", "valkey-cli", "/etc/valkey"}, nil
	}
	return kvEngine{}, &commands.PayloadError{Err: fmt.Errorf("unknown key-value engine %q", name)}
}

func (k kvEngine) instance(name string) string { return "kiln-" + name }
func (k kvEngine) unit(name string) string     { return k.server + "@" + k.instance(name) + ".service" }
func (k kvEngine) confPath(name string) string {
	return k.confDir + "/" + k.name + "-" + k.instance(name) + ".conf"
}
func (k kvEngine) dataRoot() string             { return "/var/lib/kiln-" + k.name }
func (k kvEngine) dataPath(name string) string  { return k.dataRoot() + "/" + name }
func (k kvEngine) dropInDir(name string) string { return "/etc/systemd/system/" + k.unit(name) + ".d" }
func (k kvEngine) dropIn(name string) string    { return k.dropInDir(name) + "/50-kiln.conf" }
func (k kvEngine) runDir(name string) string    { return "/run/" + k.name + "-" + k.instance(name) }

// user is the instance's system user: kiln-redis-<name>, shortened with a hash past the 32 characters useradd takes.
func (k kvEngine) user(name string) string {
	u := "kiln-" + k.name + "-" + name
	if len(u) <= 32 {
		return u
	}
	sum := sha256.Sum256([]byte(name))
	return "kiln-" + k.name + "-" + name[:32-len("kiln-"+k.name+"-")-9] + "-" + hex.EncodeToString(sum[:4])
}

// RedisApplyPayload is db.redis.apply.
type RedisApplyPayload struct {
	Engine   string `json:"engine"`
	Name     string `json:"name"`
	Port     int    `json:"port"`
	Password string `json:"password"`
	// Bind: addresses to listen on. 127.0.0.1 is always included (the agent checks the instance there).
	Bind        []string `json:"bind,omitempty"`
	MaxMemoryMB int      `json:"maxmemory_mb"`
	Eviction    string   `json:"eviction"`
	Persistence string   `json:"persistence"`
}

// RedisApplyResult is db.redis.apply's result.
type RedisApplyResult struct {
	Changed   bool `json:"changed"`
	Restarted bool `json:"restarted"`
	Port      int  `json:"port"`
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
}

func (p *RedisApplyPayload) validate() ([]string, error) {
	bad := func(format string, a ...any) error { return &commands.PayloadError{Err: fmt.Errorf(format, a...)} }
	if !redisName.MatchString(p.Name) {
		return nil, bad("invalid name %q", p.Name)
	}
	if p.Port < 1024 || p.Port > 65535 {
		return nil, bad("invalid port %d", p.Port)
	}
	if !redisPassword.MatchString(p.Password) {
		return nil, bad("invalid password (12-128 characters of A-Z a-z 0-9 . _ ~ -)")
	}
	if p.MaxMemoryMB < 16 || p.MaxMemoryMB > 1<<20 {
		return nil, bad("invalid maxmemory_mb %d", p.MaxMemoryMB)
	}
	if !slices.Contains(redisEviction, p.Eviction) {
		return nil, bad("invalid eviction %q", p.Eviction)
	}
	if !slices.Contains(redisPersist, p.Persistence) {
		return nil, bad("invalid persistence %q", p.Persistence)
	}
	bind := []string{"127.0.0.1"}
	for _, a := range p.Bind {
		ip := net.ParseIP(a)
		if ip == nil || ip.IsUnspecified() {
			return nil, bad("invalid bind address %q", a)
		}
		if s := ip.String(); !slices.Contains(bind, s) {
			bind = append(bind, s)
		}
	}
	return bind, nil
}

// renderRedisConf renders the instance configuration (identical input → identical bytes). appendonly is the
// persistence's unless overridden (the first start of a switch to AOF runs without it).
func renderRedisConf(k kvEngine, p RedisApplyPayload, bind []string, configName string, appendonly bool) string {
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
	if p.Persistence == "rdb" {
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
	for _, c := range redisDisabled {
		w(`rename-command %s ""`, c)
	}
	return b.String()
}

// renderRedisDropIn runs the instance as its own user, writing only its data and runtime directories.
func renderRedisDropIn(k kvEngine, name string) string {
	user := k.user(name)
	return "# Managed by the Kiln agent (db.redis.apply): changes are overwritten.\n" +
		"[Service]\n" +
		"User=" + user + "\n" +
		"Group=" + user + "\n" +
		"ReadWritePaths=\n" +
		"ReadWritePaths=" + k.dataPath(name) + "\n" +
		"ReadWritePaths=-" + k.runDir(name) + "\n"
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
func (db *DB) RedisApply(ctx context.Context, p RedisApplyPayload, st commands.Stream) (any, error) {
	k, err := kvEngineFor(p.Engine)
	if err != nil {
		return nil, err
	}
	bind, err := p.validate()
	if err != nil {
		return nil, err
	}
	if !db.kvInstalled(k) {
		return nil, fmt.Errorf("%s is not installed on this server (no %s@.service unit); install it first", k.label, k.server)
	}
	unit, confPath, user := k.unit(p.Name), k.confPath(p.Name), k.user(p.Name)
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
	desired := renderRedisConf(k, p, bind, state.ConfigName, p.Persistence == "aof")
	dropIn := renderRedisDropIn(k, p.Name)
	want := conn{db: db, k: k, port: p.Port, password: p.Password, config: state.ConfigName}
	active := db.unitActive(ctx, unit)

	// Already live: the marker is only written once the running process uses exactly this file.
	if active && state.Applied == hashOf(desired) && string(onDisk) == desired && db.fileIs(k.dropIn(p.Name), dropIn) && want.ping(ctx) == nil {
		return RedisApplyResult{Port: p.Port}, nil
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

	// What the running process has: the state when known, else its config file (state lost or older agent).
	prev := redisState{Port: state.Port, Bind: state.Bind, Persistence: state.Persistence, Password: state.Password, ConfigName: state.ConfigName}
	if state.Applied == "" {
		prev = redisState{Port: old.port, Bind: old.bind, Persistence: old.persistence, Password: old.password, ConfigName: old.configName}
	}
	var live *conn
	if active && prev.Port > 0 {
		for _, pw := range []string{p.Password, prev.Password} {
			c := conn{db: db, k: k, port: prev.Port, password: pw, config: prev.ConfigName}
			if pw != "" && c.ping(ctx) == nil {
				live = &c
				break
			}
		}
	}
	final := redisState{ConfigName: state.ConfigName, Applied: hashOf(desired), Port: p.Port, Bind: bind, Persistence: p.Persistence, Password: p.Password}

	// Live: memory, eviction, password and persistence change on the running process; the file then matches it.
	if live != nil && !dropInChanged && prev.Port == p.Port && slices.Equal(prev.Bind, bind) && prev.ConfigName == state.ConfigName && prev.ConfigName != "" {
		err := db.applyLive(ctx, *live, p, prev.Persistence)
		if err == nil {
			if err := db.writeRedisConf(k, p.Name, desired); err != nil {
				return nil, err
			}
			if err := db.saveRedisState(k, p.Name, final); err != nil {
				return nil, err
			}
			return RedisApplyResult{Changed: true, Port: p.Port}, nil
		}
		db.d.Logger.Warn("live redis change failed, restarting the instance", "unit", unit, "err", err)
		if st != nil {
			fmt.Fprintf(st.Stdout(), "live change failed (%v); restarting %s\n", err, unit)
		}
	}

	// Restart: another port or bind, a new drop-in, the instance not running, or a live change that failed.
	if !active || prev.Port != p.Port {
		if who, err := db.portUser(ctx, p.Port); err != nil {
			return nil, err
		} else if who != "" {
			return nil, fmt.Errorf("port %d is in use by %s", p.Port, who)
		}
	}
	if live != nil && p.Persistence != "none" {
		if _, err := live.do(ctx, "SAVE"); err != nil {
			return nil, fmt.Errorf("save %s before restarting it: %w", unit, err)
		}
	}
	twoPhase := p.Persistence == "aof" && prev.Persistence != "aof"
	if err := db.moveStale(k, p.Name, prev.Persistence, p.Persistence); err != nil {
		return nil, err
	}
	first := desired
	if twoPhase {
		first = renderRedisConf(k, p, bind, state.ConfigName, false)
	}
	if err := db.writeRedisConf(k, p.Name, first); err != nil {
		return nil, err
	}
	if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"enable", "--quiet", unit}}); err != nil {
		return nil, fmt.Errorf("enable %s: %w", unit, err)
	}
	if active {
		_, err = runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"restart", unit}})
	} else {
		_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
		_, err = runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}})
	}
	if err != nil {
		return nil, fmt.Errorf("start %s: %w%s", unit, err, db.journalTail(ctx, unit))
	}
	if err := want.ready(ctx); err != nil {
		return nil, fmt.Errorf("%s did not answer PING on 127.0.0.1:%d: %w%s", unit, p.Port, err, db.journalTail(ctx, unit))
	}
	if twoPhase {
		// Started from dump.rdb without AOF; AOF is switched on live (rewrite from memory), then the file says so.
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
	return RedisApplyResult{Changed: true, Restarted: true, Port: p.Port}, nil
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
		if from != "aof" {
			if err := db.moveAside(c.k, name, "appendonlydir", "appendonly.aof"); err != nil {
				return err
			}
			if err := set("appendonly", "yes"); err != nil {
				return err
			}
			if err := c.waitAOFRewrite(ctx); err != nil {
				return err
			}
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
		if from != "none" {
			// Files from the earlier mode would come back on the next restart (stale data): moved aside.
			return db.moveAside(c.k, name, "dump.rdb", "appendonlydir", "appendonly.aof")
		}
		return nil
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

func (db *DB) userExists(ctx context.Context, user string) bool {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "id", Args: []string{"-u", user}})
	return err == nil && res.ExitCode == 0
}

func (db *DB) ensureInstanceUser(ctx context.Context, k kvEngine, name string) error {
	user := k.user(name)
	if db.userExists(ctx, user) {
		return nil
	}
	_, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "useradd", Args: []string{
		"--system", "--user-group", "--no-create-home", "--home-dir", "/nonexistent", "--shell", "/usr/sbin/nologin",
		"--comment", "Kiln " + k.label + " instance " + name, user,
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
	if user := k.user(p.Name); db.userExists(ctx, user) {
		if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "userdel", Args: []string{user}}); err != nil {
			return nil, fmt.Errorf("remove user %s: %w", user, err)
		}
		changed = true
	}
	return ChangedResult{Changed: changed}, nil
}

func (db *DB) unitActive(ctx context.Context, unit string) bool {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-active", "--quiet", unit}})
	return err == nil && res.ExitCode == 0
}

var ssUserRe = regexp.MustCompile(`users:\(\("([^"]+)",pid=(\d+)`)

// portUser names the process listening on TCP port (any address), "" when it is free.
func (db *DB) portUser(ctx context.Context, port int) (string, error) {
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
	if m := ssUserRe.FindStringSubmatch(out); m != nil {
		return fmt.Sprintf("%s (pid %s)", m[1], m[2]), nil
	}
	return "another process", nil
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

// RedisError is an error reply of the instance.
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
		return "", &RedisError{Reply: first}
	}
	if res.ExitCode != 0 {
		msg := strings.TrimSpace(string(res.Stderr))
		if msg == "" {
			msg = fmt.Sprintf("%s exited with %d", c.k.cli, res.ExitCode)
		}
		return "", errors.New(firstLine(msg))
	}
	return out, nil
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
	start := redisNow()
	deadline := start.Add(RedisReadyTimeout)
	for {
		err := c.ping(ctx)
		if err == nil {
			return nil
		}
		var re *RedisError
		if errors.As(err, &re) && strings.HasPrefix(re.Reply, "LOADING") {
			deadline = start.Add(RedisLoadingTimeout)
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if redisNow().After(deadline) {
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
	deadline := redisNow().Add(RedisAOFTimeout)
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
			return errors.New("AOF rewrite did not finish in time")
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
