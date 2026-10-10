package db

import (
	"context"
	"fmt"
	"strconv"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// Docker publishes ports with its own NAT rules, which the host firewall (nftables input chain) never sees: a port
// bound to a private or public address is open to anyone who reaches that address. Docker's DOCKER-USER chain is
// evaluated first for forwarded traffic, so each instance with published addresses gets a chain jumped to from there:
// connections to its host port on those addresses are accepted from the allowed sources only and dropped otherwise.
// Loopback (127.0.0.1) never leaves the host and needs no rule.

// fwChain is the instance's chain (iptables names are at most 28 characters).
func fwChain(id string) string { return "FALAK-DB-" + id[len(id)-16:] }

func (db *DB) iptables(ctx context.Context, args ...string) (runner.Result, error) {
	return db.d.Runner.Run(ctx, runner.Cmd{Name: "iptables", Args: append([]string{"-w"}, args...)})
}

func (db *DB) mustIptables(ctx context.Context, args ...string) error {
	res, err := db.iptables(ctx, args...)
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("iptables %s: exit %d: %s", strings.Join(args, " "), res.ExitCode, strings.TrimSpace(string(res.Stderr)))
	}
	return err
}

// applyFirewall makes the instance's chain match its published addresses (none: the chain and its jump go).
func (db *DB) applyFirewall(ctx context.Context, s InstanceSpec) error {
	if s.HostPort == 0 || s.Publish == nil || (!s.Publish.Public && len(s.Publish.Addresses) == 0) {
		return db.removeFirewall(ctx, s.ID)
	}
	chain := fwChain(s.ID)
	if res, err := db.iptables(ctx, "-N", chain); err != nil {
		return err
	} else if res.ExitCode != 0 && !strings.Contains(string(res.Stderr), "exists") {
		return fmt.Errorf("iptables -N %s: %s", chain, strings.TrimSpace(string(res.Stderr)))
	}
	if err := db.mustIptables(ctx, "-F", chain); err != nil {
		return err
	}
	hp := strconv.Itoa(s.HostPort)
	targets := s.Publish.Addresses
	if s.Publish.Public {
		targets = []string{""}
	}
	for _, dst := range targets {
		// --ctdir ORIGINAL: only packets towards the database are filtered. Its replies keep the connection's original
		// destination in conntrack too, and would otherwise hit the DROP below (their source is the container).
		match := []string{"-A", chain, "-p", "tcp", "-m", "conntrack", "--ctdir", "ORIGINAL", "--ctorigdstport", hp}
		if dst != "" {
			match = append(match, "--ctorigdst", dst)
		}
		for _, src := range s.Publish.AllowedSources {
			if err := db.mustIptables(ctx, append(append([]string{}, match...), "-s", src, "-j", "RETURN")...); err != nil {
				return err
			}
		}
		if err := db.mustIptables(ctx, append(append([]string{}, match...), "-j", "DROP")...); err != nil {
			return err
		}
	}
	// Docker creates DOCKER-USER (it may be missing on a daemon that has not started yet).
	if res, err := db.iptables(ctx, "-C", "DOCKER-USER", "-j", chain); err != nil {
		return err
	} else if res.ExitCode != 0 {
		return db.mustIptables(ctx, "-I", "DOCKER-USER", "-j", chain)
	}
	return nil
}

// removeFirewall deletes the instance's chain and the jump to it (absent ones are fine).
func (db *DB) removeFirewall(ctx context.Context, id string) error {
	chain := fwChain(id)
	for range 8 {
		res, err := db.iptables(ctx, "-D", "DOCKER-USER", "-j", chain)
		if err != nil {
			return err
		}
		if res.ExitCode != 0 {
			break
		}
	}
	if _, err := db.iptables(ctx, "-F", chain); err != nil {
		return err
	}
	_, err := db.iptables(ctx, "-X", chain)
	return err
}
