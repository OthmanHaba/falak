package db

import (
	"context"
	"fmt"
	"net"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/kiln/agent/internal/runner"
)

// Instances that listen on docker0 or a WireGuard address after a reboot.
//
// Both kinds of address appear late in the boot: docker0 once docker.service has started, a Kiln private network's
// once its wg-quick@<interface>.service is up. The template unit is only After=network.target, so an instance can
// start before its address exists: Redis 6.2+ / Valkey then refuse to start ("Failed listening on port") and systemd
// gives up after its default 5 starts in 10 s; Redis 6.0 starts without the address and never picks it up.
//
//   - A second drop-in, 40-kiln-boot.conf, orders the instance after network-online.target, docker.service and the
//     Kiln WireGuard units on the host (ordering only: none of them is pulled in, an absent one is ignored) and makes
//     systemd retry every 2 s (at most 150 starts in 10 minutes). It only needs a daemon-reload, never a restart: it is kept apart from
//     50-kiln.conf, whose changes restart the instance.
//   - RedisWatch checks every instance once a minute: an instance whose configured addresses all exist but that does not
//     listen on each of them is restarted (data kept: snapshotted first), a failed one started. Attempts back off
//     (1, 2, 5, then every 10 minutes) while they don't help; the backoff starts over once the instance is healthy or
//     one of its addresses was gone (it came back: worth trying at once).

// wgQuickConf is the header net.wireguard.apply writes into /etc/wireguard/<interface>.conf.
const wgQuickConf = "# Managed by Kiln (net.wireguard.apply)"

var wgIfaceName = regexp.MustCompile(`^[A-Za-z0-9_=+.-]{1,15}$`)

// RedisWatchInterval is how often RedisWatch checks the instances (the first pass runs after RedisWatchDelay).
var (
	RedisWatchInterval = time.Minute
	RedisWatchDelay    = 30 * time.Second
)

func (k kvEngine) bootDropIn(name string) string { return k.dropInDir(name) + "/40-kiln-boot.conf" }

// renderRedisBootDropIn orders the instance after the units that bring its addresses up and keeps systemd retrying.
func renderRedisBootDropIn(wgUnits []string) string {
	after := append([]string{"network-online.target", "docker.service"}, wgUnits...)
	return "# Managed by the Kiln agent (db.redis.apply): changes are overwritten.\n" +
		"# Start once docker0 and Kiln's WireGuard addresses can exist (ordering only), and keep retrying until they do.\n" +
		"[Unit]\n" +
		"Wants=network-online.target\n" +
		"After=" + strings.Join(after, " ") + "\n" +
		// A few minutes of retries every 2 s (docker0 and WireGuard come up within them), then RedisWatch takes over: a
		// broken instance doesn't restart every 2 s forever.
		"StartLimitIntervalSec=10min\n" +
		"StartLimitBurst=150\n" +
		"\n" +
		"[Service]\n" +
		"RestartSec=2s\n"
}

// kilnWireGuardUnits lists wg-quick@<interface>.service for the Kiln WireGuard configs in /etc/wireguard.
func (db *DB) kilnWireGuardUnits() []string {
	entries, err := os.ReadDir(db.d.FS.P("/etc/wireguard"))
	if err != nil {
		return nil
	}
	var out []string
	for _, e := range entries {
		iface, ok := strings.CutSuffix(e.Name(), ".conf")
		if !ok || !e.Type().IsRegular() || !wgIfaceName.MatchString(iface) {
			continue
		}
		if b, err := db.d.FS.ReadFile("/etc/wireguard/" + e.Name()); err == nil && strings.HasPrefix(string(b), wgQuickConf) {
			out = append(out, "wg-quick@"+iface+".service")
		}
	}
	slices.Sort(out)
	return out
}

// ensureBootDropIn writes the instance's boot drop-in and daemon-reloads when it changed.
func (db *DB) ensureBootDropIn(ctx context.Context, k kvEngine, name string) error {
	path := k.bootDropIn(name)
	if err := db.notSymlink(path); err != nil {
		return err
	}
	changed, err := db.d.FS.WriteFile(path, []byte(renderRedisBootDropIn(db.kilnWireGuardUnits())), 0o644)
	if err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}
	if changed {
		if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"daemon-reload"}}); err != nil {
			return fmt.Errorf("systemctl daemon-reload: %w", err)
		}
	}
	return nil
}

// notListening returns the addresses of bind the port has no listening socket on (a wildcard listener covers all).
func (db *DB) notListening(ctx context.Context, port int, bind []string) ([]string, error) {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "ss", Args: []string{"-H", "-ltn", "sport = :" + strconv.Itoa(port)}})
	if err != nil {
		return nil, fmt.Errorf("check port %d: %w", port, err)
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("check port %d: ss exited with %d", port, res.ExitCode)
	}
	listening := map[string]bool{}
	for _, line := range strings.Split(string(res.Stdout), "\n") {
		f := strings.Fields(line)
		if len(f) < 4 {
			continue
		}
		local := f[3]
		// 10.0.0.1%wg0:6380 (a socket bound to a device) → 10.0.0.1:6380
		if i := strings.Index(local, "%"); i >= 0 {
			if j := strings.LastIndex(local, ":"); j > i {
				local = local[:i] + local[j:]
			}
		}
		host, p, err := net.SplitHostPort(local)
		if err != nil || p != strconv.Itoa(port) {
			continue
		}
		if host == "*" || host == "0.0.0.0" || host == "::" {
			return nil, nil
		}
		if ip := net.ParseIP(host); ip != nil {
			listening[ip.String()] = true
		}
	}
	var missing []string
	for _, a := range bind {
		if ip := net.ParseIP(a); ip != nil && !listening[ip.String()] {
			missing = append(missing, a)
		}
	}
	return missing, nil
}

// RedisWatch checks the instances every RedisWatchInterval until ctx ends (see RedisCheck).
func (db *DB) RedisWatch(ctx context.Context) {
	t := time.NewTimer(RedisWatchDelay)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
		db.RedisCheck(ctx)
		t.Reset(RedisWatchInterval)
	}
}

// RedisCheck makes every instance listen on the addresses its configuration names, once they exist: it refreshes the
// boot drop-in (a private network added since), restarts a running instance that misses one of them (Redis 6.0
// started before the address existed) and starts a failed one (it gave up before the address existed). An instance
// busy with a command is skipped.
func (db *DB) RedisCheck(ctx context.Context) {
	dir := strings.TrimRight(db.d.StateDir, "/") + "/db/redis"
	entries, err := os.ReadDir(db.d.FS.P(dir))
	if err != nil {
		return
	}
	ifs, err := redisInterfaces()
	if err != nil {
		db.d.Logger.Warn("redis watch: list network interfaces", "err", err)
		return
	}
	present := map[string]bool{}
	for _, it := range ifs {
		for _, a := range it.Addrs {
			present[a.String()] = true
		}
	}
	for _, e := range entries {
		base, ok := strings.CutSuffix(e.Name(), ".json")
		if !ok {
			continue
		}
		engine, name, ok := strings.Cut(base, "-")
		if !ok || !redisName.MatchString(name) {
			continue
		}
		k, err := kvEngineFor(engine)
		if err != nil || !db.kvInstalled(k) {
			continue
		}
		unlock, ok := tryLockInstance(k, name)
		if !ok {
			continue
		}
		db.checkInstance(ctx, k, name, present)
		unlock()
		if ctx.Err() != nil {
			return
		}
	}
}

// watchAttempts is RedisWatch's record of one unit: how many attempts in a row did not help, and when the last was.
type watchAttempts struct {
	n    int
	last time.Time
}

// RedisWatchBackoff is the wait after the 1st, 2nd, 3rd… attempt that did not help (the last one repeats).
var RedisWatchBackoff = []time.Duration{time.Minute, 2 * time.Minute, 5 * time.Minute, 10 * time.Minute}

// mayAttempt tells whether the unit's backoff allows an attempt now.
func (db *DB) mayAttempt(unit string) bool {
	db.watchMu.Lock()
	defer db.watchMu.Unlock()
	a, ok := db.watchTried[unit]
	if !ok || a.n == 0 {
		return true
	}
	wait := RedisWatchBackoff[min(a.n, len(RedisWatchBackoff))-1]
	return !redisNow().Before(a.last.Add(wait))
}

// attempted records an attempt (a stop or a start was issued).
func (db *DB) attempted(unit string) {
	db.watchMu.Lock()
	defer db.watchMu.Unlock()
	if db.watchTried == nil {
		db.watchTried = map[string]watchAttempts{}
	}
	a := db.watchTried[unit]
	db.watchTried[unit] = watchAttempts{n: a.n + 1, last: redisNow()}
}

// resetAttempts starts the backoff over: the instance is healthy, or one of its addresses was gone.
func (db *DB) resetAttempts(unit string) {
	db.watchMu.Lock()
	defer db.watchMu.Unlock()
	delete(db.watchTried, unit)
}

func (db *DB) checkInstance(ctx context.Context, k kvEngine, name string, present map[string]bool) {
	unit := k.unit(name)
	log := db.d.Logger.With("unit", unit)
	onDisk, err := db.d.FS.ReadFile(k.confPath(name))
	if err != nil {
		return // removed, or never written
	}
	if !db.d.FS.Exists(k.dropIn(name)) {
		return // removed half way (or never applied): the watch doesn't bring the drop-in directory back
	}
	if err := db.ensureBootDropIn(ctx, k, name); err != nil {
		log.Warn("redis watch: boot drop-in", "err", err)
	}
	conf := parseRedisConf(onDisk)
	if conf.port == 0 {
		return
	}
	for _, a := range conf.bind {
		if ip := net.ParseIP(a); ip == nil || !present[ip.String()] {
			// Not there yet: nothing can listen on it (systemd retries a refused start). Once it is back, try at once.
			db.resetAttempts(unit)
			return
		}
	}
	switch db.activeState(ctx, unit) {
	case "active":
		missing, err := db.notListening(ctx, conf.port, conf.bind)
		if err != nil {
			log.Warn("redis watch: listening check", "err", err)
			return
		}
		if len(missing) == 0 {
			db.resetAttempts(unit)
			return
		}
		if !db.mayAttempt(unit) {
			return
		}
		state := db.loadRedisState(k, name)
		if state.Applied != hashOf(string(onDisk)) {
			return // the process does not run this file (an apply was interrupted): its redelivery restarts it
		}
		log.Warn("redis instance does not listen on all of its addresses, restarting it", "missing", strings.Join(missing, " "))
		stopped, err := db.restartKeepingData(ctx, k, name, conf, state)
		if stopped {
			db.attempted(unit)
		}
		if err != nil {
			log.Warn("redis watch: restart failed", "err", err)
		}
	case "failed", "inactive":
		res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-enabled", "--quiet", unit}})
		if err != nil || res.ExitCode != 0 || !db.mayAttempt(unit) {
			return
		}
		log.Warn("redis instance is not running although its addresses exist, starting it")
		db.attempted(unit)
		_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
		if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
			log.Warn("redis watch: start failed", "err", err)
		}
	default:
		// activating (systemd still retrying), deactivating, unknown: leave it to systemd for now.
	}
}

// activeState is the unit's ActiveState (active, failed, inactive, activating, …), "" when unknown.
func (db *DB) activeState(ctx context.Context, unit string) string {
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"show", "--property=ActiveState", "--value", unit}})
	if err != nil || res.ExitCode != 0 {
		return ""
	}
	return strings.TrimSpace(string(res.Stdout))
}

// restartKeepingData restarts a running instance with its unchanged config: snapshotted first (unless it keeps
// nothing), never while its first AOF rewrite runs (the AOF could not be loaded). stopped tells whether it got as far as
// stopping the instance (a failure before that is not an attempt: nothing was done).
func (db *DB) restartKeepingData(ctx context.Context, k kvEngine, name string, conf parsedConf, state redisState) (stopped bool, err error) {
	unit := k.unit(name)
	c := conn{db: db, k: k, port: conf.port, password: state.Password, config: state.ConfigName}
	if c.password == "" {
		c.password = conf.password
	}
	if c.config == "" {
		c.config = conf.configName
	}
	if err := c.ping(ctx); err != nil {
		return false, fmt.Errorf("not reachable on 127.0.0.1:%d: %w", conf.port, err)
	}
	mode := conf.persistence
	if c.config != "" {
		if m, err := c.liveMode(ctx); err == nil {
			mode = m
		}
	}
	if mode == "aof" {
		if incomplete, err := c.aofIncomplete(ctx); err != nil {
			return false, err
		} else if incomplete {
			return false, fmt.Errorf("its AOF rewrite is not finished; the watch tries again on its next pass")
		}
	}
	if err := db.prepareStop(ctx, c, mode, mode); err != nil {
		return false, fmt.Errorf("prepare the restart: %w", err)
	}
	// Stop and start are not cut short by the agent shutting down: the instance must not be left stopped.
	sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Minute)
	defer cancel()
	if _, err := runner.Check(sctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"stop", unit}}); err != nil {
		return true, fmt.Errorf("stop: %w", err)
	}
	_, _ = db.d.Runner.Run(sctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
	if _, err := runner.Check(sctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
		return true, fmt.Errorf("start: %w%s", err, db.journalTail(sctx, unit))
	}
	return true, c.ready(ctx)
}

// tryLockInstance takes the instance's lock only when it is free (see lockInstance).
func tryLockInstance(k kvEngine, name string) (func(), bool) {
	m, _ := instanceLocks.LoadOrStore(k.name+"/"+name, make(chan struct{}, 1))
	sem := m.(chan struct{})
	select {
	case sem <- struct{}{}:
		return func() { <-sem }, true
	default:
		return nil, false
	}
}
