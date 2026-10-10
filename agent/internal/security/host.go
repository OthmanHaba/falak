package security

import (
	"context"
	"fmt"
	"regexp"
	"strings"
	"time"
)

// Update paths.
const (
	AutoUpgrades     = "/etc/apt/apt.conf.d/20auto-upgrades"
	FalakUnattended  = "/etc/apt/apt.conf.d/52falak-unattended"
	RebootRequired   = "/var/run/reboot-required"
	rebootPkgs       = "/var/run/reboot-required.pkgs"
	aptSimulateLimit = 30 * time.Second
)

// aptSimulate lists the upgrades apt would install from the package lists already on disk (no download, no lock).
func (s *Security) aptSimulate(ctx context.Context) ([]string, bool) {
	res, err := s.run(ctx, aptSimulateLimit, "apt-get", "-s", "-q", "-o", "Debug::NoLocking=1", "upgrade")
	if err != nil || res.ExitCode != 0 {
		return nil, false
	}
	return ParseSecurityUpgrades(string(res.Stdout)), true
}

var aptInst = regexp.MustCompile(`^Inst (\S+) .*\((.*)\)`)

// ParseSecurityUpgrades returns the packages of `apt-get -s upgrade` whose candidate comes from a security pocket
// ("Inst libssl3 [3.0.2-0ubuntu1.10] (3.0.2-0ubuntu1.12 Ubuntu:22.04/jammy-security [amd64])").
func ParseSecurityUpgrades(out string) []string {
	var pkgs []string
	for _, line := range strings.Split(out, "\n") {
		m := aptInst.FindStringSubmatch(strings.TrimSpace(line))
		if m != nil && strings.Contains(strings.ToLower(m[2]), "security") {
			pkgs = append(pkgs, m[1])
		}
	}
	return pkgs
}

var aptPeriodic = regexp.MustCompile(`APT::Periodic::([A-Za-z-]+)\s+"([^"]*)"`)

// unattendedOn reports whether unattended-upgrades is installed and runs (APT::Periodic::Unattended-Upgrade "1").
func (s *Security) unattendedOn(ctx context.Context) (bool, string) {
	res, err := s.run(ctx, cmdTimeout, "dpkg-query", "-W", "-f=${db:Status-Status}", "unattended-upgrades")
	if err != nil || res.ExitCode != 0 || strings.TrimSpace(string(res.Stdout)) != "installed" {
		return false, "unattended-upgrades is not installed"
	}
	b, ok := s.read(AutoUpgrades)
	if !ok {
		return false, AutoUpgrades + " is missing"
	}
	periodic := map[string]string{}
	for _, m := range aptPeriodic.FindAllStringSubmatch(b, -1) {
		periodic[m[1]] = m[2]
	}
	if periodic["Unattended-Upgrade"] == "0" || periodic["Unattended-Upgrade"] == "" {
		return false, `APT::Periodic::Unattended-Upgrade is "` + periodic["Unattended-Upgrade"] + `"`
	}
	return true, "installed; APT::Periodic::Unattended-Upgrade \"" + periodic["Unattended-Upgrade"] + `"`
}

func (s *Security) updateChecks(ctx context.Context, _ AuditPayload) []Check {
	var cs []Check
	c := Check{ID: "updates.security", Title: "Security updates are installed", Area: "updates", Status: Pass, Severity: Medium}
	if pkgs, ok := s.aptSimulate(ctx); !ok {
		c.Status, c.Severity, c.Evidence = Info, SevInfo, "apt-get could not simulate an upgrade"
	} else if len(pkgs) > 0 {
		c.Status, c.FixID = Fail, "updates.install"
		c.Evidence = plural(len(pkgs), "security update", "security updates") + " pending: " + list(pkgs, 5)
	} else {
		c.Evidence = "no pending security updates (as of the last apt update)"
	}
	cs = append(cs, c)

	c = Check{ID: "updates.unattended", Title: "Security updates install automatically", Area: "updates", Status: Pass, Severity: Medium}
	on, why := s.unattendedOn(ctx)
	c.Evidence = why
	if !on {
		c.Status, c.FixID = Fail, "updates.unattended"
	}
	cs = append(cs, c)

	c = Check{ID: "updates.reboot", Title: "No reboot is pending", Area: "updates", Status: Pass, Severity: Medium, Evidence: "no reboot required"}
	if s.d.FS.Exists(RebootRequired) {
		c.Status, c.FixID, c.Evidence = Warn, "updates.reboot", "a reboot is required"
		if b, ok := s.read(rebootPkgs); ok && strings.TrimSpace(b) != "" {
			c.Evidence += " by " + list(uniq(strings.Fields(b)), 4)
		}
	}
	return append(cs, c)
}

func uniq(xs []string) []string {
	seen := map[string]bool{}
	var out []string
	for _, x := range xs {
		if !seen[x] {
			seen[x] = true
			out = append(out, x)
		}
	}
	return out
}

func (s *Security) fail2banChecks(ctx context.Context, _ AuditPayload) []Check {
	c := Check{ID: "intrusion.fail2ban", Title: "fail2ban guards SSH", Area: "intrusion", Status: Pass, Severity: Medium, Evidence: "the sshd jail is active"}
	res, err := s.run(ctx, cmdTimeout, "systemctl", "is-active", "--quiet", "fail2ban.service")
	switch {
	case err != nil || res.ExitCode != 0:
		c.Status, c.FixID, c.Evidence = Fail, "fail2ban.sshd", "fail2ban is not running"
	default:
		if res, err := s.run(ctx, cmdTimeout, "fail2ban-client", "status", "sshd"); err != nil || res.ExitCode != 0 {
			c.Status, c.FixID, c.Evidence = Fail, "fail2ban.sshd", "fail2ban runs without an sshd jail"
		}
	}
	return []Check{c}
}

func (s *Security) timeChecks(ctx context.Context, _ AuditPayload) []Check {
	c := Check{ID: "time.ntp", Title: "The clock is synchronized", Area: "time", Status: Pass, Severity: Medium}
	out, ok := s.output(ctx, "timedatectl", "show", "--property=NTP", "--property=NTPSynchronized")
	if !ok {
		c.Status, c.Severity, c.Evidence = Info, SevInfo, "timedatectl is not available"
		return []Check{c}
	}
	kv := map[string]string{}
	for _, line := range strings.Split(out, "\n") {
		if k, v, ok := strings.Cut(strings.TrimSpace(line), "="); ok {
			kv[k] = v
		}
	}
	c.Evidence = fmt.Sprintf("NTP=%s NTPSynchronized=%s", kv["NTP"], kv["NTPSynchronized"])
	switch {
	case kv["NTPSynchronized"] == "yes":
	case kv["NTP"] != "yes":
		c.Status, c.FixID = Fail, "time.sync"
	default:
		c.Status, c.Severity = Warn, Low // on, not synchronized yet
	}
	return []Check{c}
}
