package db

import (
	"context"
	"errors"
	"fmt"
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
// redis-server@.service (ExecStart=/usr/bin/redis-server /etc/redis/redis-%i.conf --supervised systemd
// --daemonize no, User=redis, ReadWritePaths=/var/lib/redis) and, from Ubuntu 26.04 / Debian 13,
// valkey-server@.service (the same with /etc/valkey/valkey-%i.conf, User=valkey, /var/lib/valkey). An instance
// "cache" is the unit redis-server@kiln-cache with /etc/redis/redis-kiln-cache.conf and its data in
// /var/lib/redis/kiln-cache (inside the unit's ReadWritePaths). The stock instance (redis-server.service on 6379)
// is never touched.
//
// The configuration holds the password (requirepass), as in every Redis install, so it is 0640 and owned by the
// engine's user; redis-cli gets the password through REDISCLI_AUTH, never on its command line. CONFIG, DEBUG,
// MODULE and SHUTDOWN are disabled (rename-command … ""), so a client can neither rewrite the file nor load code:
// the agent never needs them (it stops and restarts through systemctl).

var (
	redisName     = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,40}$`)
	redisPassword = regexp.MustCompile(`^[A-Za-z0-9._~-]{12,128}$`)
	redisEviction = []string{"noeviction", "allkeys-lru", "allkeys-lfu", "allkeys-random", "volatile-lru", "volatile-lfu", "volatile-random", "volatile-ttl"}
	redisPersist  = []string{"rdb", "aof", "none"}
	redisDisabled = []string{"CONFIG", "DEBUG", "MODULE", "SHUTDOWN"}

	// RedisReadyTimeout bounds the wait for PING after a (re)start.
	RedisReadyTimeout = 30 * time.Second
	redisPoll         = 500 * time.Millisecond
)

// kvEngine is one key-value flavour as packaged by Debian/Ubuntu.
type kvEngine struct {
	name    string // redis | valkey
	label   string
	server  string // binary / unit prefix
	cli     string
	confDir string
	dataDir string
	user    string
}

func kvEngineFor(name string) (kvEngine, error) {
	switch name {
	case "redis":
		return kvEngine{"redis", "Redis", "redis-server", "redis-cli", "/etc/redis", "/var/lib/redis", "redis"}, nil
	case "valkey":
		return kvEngine{"valkey", "Valkey", "valkey-server", "valkey-cli", "/etc/valkey", "/var/lib/valkey", "valkey"}, nil
	}
	return kvEngine{}, &commands.PayloadError{Err: fmt.Errorf("unknown key-value engine %q", name)}
}

func (k kvEngine) instance(name string) string { return "kiln-" + name }
func (k kvEngine) unit(name string) string     { return k.server + "@" + k.instance(name) + ".service" }
func (k kvEngine) confPath(name string) string {
	return k.confDir + "/" + k.name + "-" + k.instance(name) + ".conf"
}
func (k kvEngine) dataPath(name string) string { return k.dataDir + "/" + k.instance(name) }

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

// renderRedisConf renders the instance configuration (identical input → identical bytes, so an unchanged
// instance is never restarted).
func renderRedisConf(k kvEngine, p RedisApplyPayload, bind []string) string {
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
	switch p.Persistence {
	case "rdb":
		// One pair per line: Redis 6.0 (Ubuntu 22.04) does not take several pairs on one save line.
		w("save 3600 1")
		w("save 300 100")
		w("save 60 10000")
		w("appendonly no")
	case "aof":
		w(`save ""`)
		w("appendonly yes")
		w("appendfsync everysec")
	default:
		w(`save ""`)
		w("appendonly no")
	}
	w("dir %s", k.dataPath(p.Name))
	w("dbfilename dump.rdb")
	// The template unit starts the server with --supervised systemd --daemonize no and a RuntimeDirectory of
	// <name>-%i; logs go to the journal.
	w("supervised systemd")
	w("daemonize no")
	w("pidfile /run/%s-%s/%s.pid", k.name, k.instance(p.Name), k.server)
	w(`logfile ""`)
	w("tcp-keepalive 300")
	w("timeout 0")
	w("databases 16")
	for _, c := range redisDisabled {
		w(`rename-command %s ""`, c)
	}
	return b.String()
}

func (db *DB) kvInstalled(k kvEngine) bool {
	for _, dir := range []string{"/usr/lib/systemd/system", "/lib/systemd/system", "/etc/systemd/system"} {
		if db.d.FS.Exists(dir + "/" + k.server + "@.service") {
			return true
		}
	}
	return false
}

var confPortRe = regexp.MustCompile(`(?m)^port (\d+)$`)

// RedisApply converges one instance: configuration, data directory, enabled and running unit, PING with the password.
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
	unit := k.unit(p.Name)
	conf := k.confPath(p.Name)

	oldPort := 0
	if cur, err := db.d.FS.ReadFile(conf); err == nil {
		if m := confPortRe.FindSubmatch(cur); m != nil {
			oldPort, _ = strconv.Atoi(string(m[1]))
		}
	}
	active := db.unitActive(ctx, unit)
	if !active || oldPort != p.Port {
		if who, err := db.portUser(ctx, p.Port); err != nil {
			return nil, err
		} else if who != "" {
			return nil, fmt.Errorf("port %d is in use by %s", p.Port, who)
		}
	}

	data := k.dataPath(p.Name)
	if err := db.d.FS.MkdirAll(data, 0o750); err != nil {
		return nil, fmt.Errorf("create %s: %w", data, err)
	}
	if err := os.Chmod(db.d.FS.P(data), 0o750); err != nil {
		return nil, fmt.Errorf("chmod %s: %w", data, err)
	}
	changed, err := db.d.FS.WriteFile(conf, []byte(renderRedisConf(k, p, bind)), 0o640)
	if err != nil {
		return nil, fmt.Errorf("write %s: %w", conf, err)
	}
	if db.d.FS.IsReal() {
		for _, path := range []string{data, conf} {
			if err := db.d.FS.Chown(path, k.user, k.user); err != nil {
				return nil, fmt.Errorf("chown %s: %w", path, err)
			}
		}
	}

	if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"enable", "--quiet", unit}}); err != nil {
		return nil, fmt.Errorf("enable %s: %w", unit, err)
	}
	restarted := false
	switch {
	case !active:
		_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
		_, err = runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}})
		restarted = true
	case changed:
		_, err = runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"restart", unit}})
		restarted = true
	}
	if err != nil {
		return nil, fmt.Errorf("start %s: %w%s", unit, err, db.journalTail(ctx, unit))
	}
	if err := db.redisReady(ctx, k, p.Port, p.Password); err != nil {
		return nil, fmt.Errorf("%s did not answer PING on 127.0.0.1:%d: %w%s", unit, p.Port, err, db.journalTail(ctx, unit))
	}
	if restarted && st != nil {
		fmt.Fprintf(st.Stdout(), "%s listening on %d\n", unit, p.Port)
	}
	return RedisApplyResult{Changed: changed || restarted, Restarted: restarted, Port: p.Port}, nil
}

// RedisRemove stops and disables the instance and deletes its configuration and data. Idempotent.
func (db *DB) RedisRemove(ctx context.Context, p RedisRemovePayload, _ commands.Stream) (any, error) {
	k, err := kvEngineFor(p.Engine)
	if err != nil {
		return nil, err
	}
	if !redisName.MatchString(p.Name) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid name %q", p.Name)}
	}
	unit := k.unit(p.Name)
	changed := false
	if db.unitActive(ctx, unit) {
		changed = true
	}
	// Not an error when the unit was never started or the template is gone with the package.
	_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"disable", "--now", "--quiet", unit}})
	if db.unitActive(ctx, unit) {
		return nil, fmt.Errorf("could not stop %s", unit)
	}
	removed, err := db.d.FS.Remove(k.confPath(p.Name))
	if err != nil {
		return nil, fmt.Errorf("remove %s: %w", k.confPath(p.Name), err)
	}
	changed = changed || removed
	data := db.d.FS.P(k.dataPath(p.Name))
	if _, err := os.Lstat(data); err == nil {
		if err := os.RemoveAll(data); err != nil {
			return nil, fmt.Errorf("remove %s: %w", k.dataPath(p.Name), err)
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

// redisReady waits until the instance answers PING with the password (REDISCLI_AUTH, never argv).
func (db *DB) redisReady(ctx context.Context, k kvEngine, port int, password string) error {
	deadline := time.Now().Add(RedisReadyTimeout)
	last := errors.New("no answer")
	for {
		cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		res, err := db.d.Runner.Run(cctx, runner.Cmd{
			Name: k.cli,
			Args: []string{"-h", "127.0.0.1", "-p", strconv.Itoa(port), "--no-auth-warning", "PING"},
			Env:  []string{"REDISCLI_AUTH=" + password},
		})
		cancel()
		switch out := strings.TrimSpace(string(res.Stdout)); {
		case err == nil && res.ExitCode == 0 && out == "PONG":
			return nil
		case err != nil:
			last = err
		case out != "":
			last = errors.New(firstLine(out))
		default:
			last = fmt.Errorf("%s exited with %d", k.cli, res.ExitCode)
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if time.Now().After(deadline) {
			return last
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(redisPoll):
		}
	}
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

func firstLine(s string) string {
	line, _, _ := strings.Cut(s, "\n")
	return line
}
