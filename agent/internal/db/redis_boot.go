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
//     systemd retry every 2 s with no start limit. It only needs a daemon-reload, never a restart: it is kept apart from
//     50-kiln.conf, whose changes restart the instance.
//   - RedisWatch checks every instance once a minute: an instance whose configured addresses all exist but that does not
//     listen on each of them is restarted (data kept: snapshotted first), a failed one started. Each instance gets one
//     attempt per set of host addresses, so a broken one is not restarted over and over.

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
		"StartLimitIntervalSec=0\n" +
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
	var hostAddrs []string
	for _, it := range ifs {
		for _, a := range it.Addrs {
			present[a.String()] = true
			hostAddrs = append(hostAddrs, a.String())
		}
	}
	slices.Sort(hostAddrs)
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
		db.checkInstance(ctx, k, name, present, strings.Join(hostAddrs, " "))
		unlock()
		if ctx.Err() != nil {
			return
		}
	}
}

func (db *DB) checkInstance(ctx context.Context, k kvEngine, name string, present map[string]bool, hostKey string) {
	unit := k.unit(name)
	log := db.d.Logger.With("unit", unit)
	onDisk, err := db.d.FS.ReadFile(k.confPath(name))
	if err != nil {
		return // removed, or never written
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
			return // not there yet: nothing can listen on it (systemd keeps retrying a refused start)
		}
	}
	// One attempt per set of host addresses: a new address (or one gone and back) earns another.
	attempted := func() bool {
		db.watchMu.Lock()
		defer db.watchMu.Unlock()
		if db.watchTried == nil {
			db.watchTried = map[string]string{}
		}
		if db.watchTried[unit] == hostKey {
			return true
		}
		db.watchTried[unit] = hostKey
		return false
	}
	switch db.activeState(ctx, unit) {
	case "active":
		missing, err := db.notListening(ctx, conf.port, conf.bind)
		if err != nil {
			log.Warn("redis watch: listening check", "err", err)
			return
		}
		if len(missing) == 0 {
			db.watchMu.Lock()
			delete(db.watchTried, unit)
			db.watchMu.Unlock()
			return
		}
		if attempted() {
			return
		}
		state := db.loadRedisState(k, name)
		if state.Applied != hashOf(string(onDisk)) {
			return // the process does not run this file (an apply was interrupted): its redelivery restarts it
		}
		log.Warn("redis instance does not listen on all of its addresses, restarting it", "missing", strings.Join(missing, " "))
		if err := db.restartKeepingData(ctx, k, name, conf, state); err != nil {
			log.Warn("redis watch: restart failed", "err", err)
		}
	case "failed", "inactive":
		res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-enabled", "--quiet", unit}})
		if err != nil || res.ExitCode != 0 || attempted() {
			return
		}
		log.Warn("redis instance is not running although its addresses exist, starting it")
		_, _ = db.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
		if _, err := runner.Check(ctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
			log.Warn("redis watch: start failed", "err", err)
		}
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
// nothing), never while its first AOF rewrite runs (the AOF could not be loaded).
func (db *DB) restartKeepingData(ctx context.Context, k kvEngine, name string, conf parsedConf, state redisState) error {
	unit := k.unit(name)
	c := conn{db: db, k: k, port: conf.port, password: state.Password, config: state.ConfigName}
	if c.password == "" {
		c.password = conf.password
	}
	if c.config == "" {
		c.config = conf.configName
	}
	if err := c.ping(ctx); err != nil {
		return fmt.Errorf("not reachable on 127.0.0.1:%d: %w", conf.port, err)
	}
	mode := conf.persistence
	if c.config != "" {
		if m, err := c.liveMode(ctx); err == nil {
			mode = m
		}
	}
	if mode == "aof" {
		if incomplete, err := c.aofIncomplete(ctx); err != nil {
			return err
		} else if incomplete {
			return fmt.Errorf("its AOF rewrite is not finished; retried later")
		}
	}
	if err := db.prepareStop(ctx, c, mode, mode); err != nil {
		return fmt.Errorf("prepare the restart: %w", err)
	}
	// Stop and start are not cut short by the agent shutting down: the instance must not be left stopped.
	sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Minute)
	defer cancel()
	if _, err := runner.Check(sctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"stop", unit}}); err != nil {
		return fmt.Errorf("stop: %w", err)
	}
	_, _ = db.d.Runner.Run(sctx, runner.Cmd{Name: "systemctl", Args: []string{"reset-failed", unit}})
	if _, err := runner.Check(sctx, db.d.Runner, runner.Cmd{Name: "systemctl", Args: []string{"start", unit}}); err != nil {
		return fmt.Errorf("start: %w%s", err, db.journalTail(sctx, unit))
	}
	return c.ready(ctx)
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
