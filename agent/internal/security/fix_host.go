package security

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"sort"
	"strings"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Fix paths and units.
const (
	Fail2banJail = "/etc/fail2ban/jail.d/falak-sshd.local"
	SysctlFile   = "/etc/sysctl.d/90-falak-hardening.conf"
	RebootUnit   = "falak-security-reboot"
	updatesLimit = 20 * time.Minute
)

func (s *Security) fixUnattended(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	if on, _ := s.unattendedOn(ctx); on {
		return false, "unattended-upgrades is already on", nil
	}
	if _, err := system.AptFor(s.d.Runner, s.d.FS, st).Ensure(ctx, []string{"unattended-upgrades"}, true); err != nil {
		return false, "", err
	}
	if err := b.saveFile(AutoUpgrades); err != nil {
		return false, "", err
	}
	// The same files provisioning writes (provision.apply unattended_upgrades), automatic reboots off.
	periodic := "// Managed by Falak\nAPT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n"
	if err := s.writeKeepMode(AutoUpgrades, []byte(periodic), 0o644); err != nil {
		return false, "", err
	}
	if !s.d.FS.Exists(FalakUnattended) {
		if err := b.saveFile(FalakUnattended); err != nil {
			return false, "", err
		}
		cfg := "// Managed by Falak\nUnattended-Upgrade::Automatic-Reboot \"false\";\nUnattended-Upgrade::Automatic-Reboot-Time \"04:00\";\nUnattended-Upgrade::Remove-Unused-Kernel-Packages \"true\";\n"
		if _, err := s.d.FS.WriteFile(FalakUnattended, []byte(cfg), 0o644); err != nil {
			return false, "", err
		}
	}
	if err := s.exec(ctx, st, time.Minute, system.AptEnv, "apt-config", "dump"); err != nil {
		return false, "", fmt.Errorf("apt rejected the configuration: %w", err)
	}
	return true, "unattended-upgrades installs security updates daily", nil
}

func (s *Security) fixInstallUpdates(ctx context.Context, _ *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	pkgs, ok := s.aptSimulate(ctx)
	if !ok {
		return false, "", errors.New("apt-get could not simulate an upgrade")
	}
	if len(pkgs) == 0 {
		return false, "no pending security updates", nil
	}
	if on, _ := s.unattendedOn(ctx); !on {
		return false, "", errors.New("unattended-upgrades is off; turn it on first (it installs from the security pockets only)")
	}
	fmt.Fprintf(st.Stdout(), "installing %s\n", plural(len(pkgs), "security update", "security updates"))
	ctx, cancel := context.WithTimeout(ctx, updatesLimit)
	defer cancel()
	if err := system.AptFor(s.d.Runner, s.d.FS, st).Update(ctx); err != nil {
		return false, "", err
	}
	if err := s.exec(ctx, st, updatesLimit, system.AptEnv, "unattended-upgrade", "-v"); err != nil {
		return false, "", err
	}
	msg := "installed " + plural(len(pkgs), "security update", "security updates")
	if s.d.FS.Exists(RebootRequired) {
		msg += "; a reboot is required"
	}
	return true, msg, nil
}

func (s *Security) fixReboot(ctx context.Context, b *backup, p FixPayload, st commands.Stream) (bool, string, error) {
	if !s.d.FS.Exists(RebootRequired) {
		return false, "no reboot is required", nil
	}
	if s.active(ctx, RebootUnit+".timer") {
		return false, "a reboot is already scheduled", nil
	}
	at := p.RebootAt
	if at == "" {
		at = "04:00"
	}
	if err := b.setState("unit", RebootUnit); err != nil {
		return false, "", err
	}
	if err := b.setState("at", at); err != nil {
		return false, "", err
	}
	// A transient timer: it fires once (the reboot clears it) and undo stops it.
	if err := s.exec(ctx, st, 30*time.Second, nil, "systemd-run", "--unit="+RebootUnit, "--on-calendar=*-*-* "+at+":00",
		"--timer-property=AccuracySec=1min", "--description=Falak: reboot for updates", "systemctl", "reboot"); err != nil {
		return false, "", err
	}
	return true, "reboot scheduled at " + at + " (server time)", nil
}

func (s *Security) undoReboot(ctx context.Context, _ Manifest, st commands.Stream) error {
	res, err := s.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"stop", RebootUnit + ".timer"}, Stdout: st.Stdout(), Stderr: st.Stderr()})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 && res.ExitCode != 5 { // 5: not loaded (fired, or the server rebooted)
		return fmt.Errorf("systemctl stop %s.timer: exit status %d", RebootUnit, res.ExitCode)
	}
	return nil
}

func (s *Security) fixFail2ban(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	if s.active(ctx, "fail2ban.service") {
		if res, err := s.run(ctx, cmdTimeout, "fail2ban-client", "status", "sshd"); err == nil && res.ExitCode == 0 {
			return false, "the sshd jail is already active", nil
		}
	}
	if _, err := system.AptFor(s.d.Runner, s.d.FS, st).Ensure(ctx, []string{"fail2ban"}, true); err != nil {
		return false, "", err
	}
	eff, _ := s.sshEffective(ctx)
	port := strings.Fields(eff["port"] + " 22")[0]
	if err := b.saveFile(Fail2banJail); err != nil {
		return false, "", err
	}
	// The systemd backend: Ubuntu ≥ 24.04 and Debian 12 log sshd to the journal only.
	jail := fmt.Sprintf("# Managed by Falak (security fix fail2ban.sshd)\n[sshd]\nenabled = true\nport = %s\nbackend = systemd\n", port)
	if _, err := s.d.FS.WriteFile(Fail2banJail, []byte(jail), 0o644); err != nil {
		return false, "", err
	}
	if err := s.exec(ctx, st, 30*time.Second, nil, "fail2ban-client", "-t"); err != nil {
		return false, "", fmt.Errorf("fail2ban rejected the configuration: %w", err)
	}
	if err := s.exec(ctx, st, time.Minute, nil, "systemctl", "enable", "fail2ban.service"); err != nil {
		return false, "", err
	}
	if err := s.exec(ctx, st, time.Minute, nil, "systemctl", "restart", "fail2ban.service"); err != nil {
		return false, "", err
	}
	return true, "fail2ban guards sshd on port " + port, nil
}

func (s *Security) undoFail2ban(ctx context.Context, _ Manifest, st commands.Stream) error {
	if !s.active(ctx, "fail2ban.service") {
		return nil
	}
	return s.exec(ctx, st, time.Minute, nil, "systemctl", "restart", "fail2ban.service")
}

// DisableDaemonTCP removes the tcp:// entries from daemon.json "hosts" (the key goes when nothing is left); changed is
// false when there was none.
func DisableDaemonTCP(raw []byte) ([]byte, bool, error) {
	var cfg map[string]any
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return nil, false, fmt.Errorf("%s is not valid JSON: %w", DaemonJSON, err)
	}
	hosts, _ := cfg["hosts"].([]any)
	var keep []any
	for _, h := range hosts {
		if str, ok := h.(string); ok && strings.HasPrefix(str, "tcp://") {
			continue
		}
		keep = append(keep, h)
	}
	if len(keep) == len(hosts) {
		return raw, false, nil
	}
	if len(keep) == 0 {
		delete(cfg, "hosts")
	} else {
		cfg["hosts"] = keep
	}
	out, err := json.MarshalIndent(cfg, "", "  ")
	return append(out, '\n'), true, err
}

func (s *Security) fixDockerTCP(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	raw, ok := s.read(DaemonJSON)
	if !ok {
		return false, "", errors.New(DaemonJSON + " does not exist; a TCP socket set in docker.service must be removed by hand")
	}
	next, changed, err := DisableDaemonTCP([]byte(raw))
	if err != nil {
		return false, "", err
	}
	if !changed {
		return false, "the daemon has no TCP socket in " + DaemonJSON, nil
	}
	if err := b.saveFile(DaemonJSON); err != nil {
		return false, "", err
	}
	if err := s.writeKeepMode(DaemonJSON, next, 0o644); err != nil {
		return false, "", err
	}
	if err := s.exec(ctx, st, 30*time.Second, nil, "dockerd", "--validate", "--config-file", DaemonJSON); err != nil {
		return false, "", fmt.Errorf("dockerd rejected the configuration: %w", err)
	}
	if err := s.exec(ctx, st, 3*time.Minute, nil, "systemctl", "restart", "docker.service"); err != nil {
		// Put the old configuration back and start Docker with it.
		_ = b.restore(context.WithoutCancel(ctx))
		_ = s.exec(context.WithoutCancel(ctx), st, 3*time.Minute, nil, "systemctl", "restart", "docker.service")
		return false, "", fmt.Errorf("docker did not restart with the new configuration: %w", err)
	}
	return true, "the Docker API listens on its unix socket only (live-restore keeps containers running)", nil
}

func (s *Security) undoDockerTCP(ctx context.Context, _ Manifest, st commands.Stream) error {
	return s.exec(ctx, st, 3*time.Minute, nil, "systemctl", "restart", "docker.service")
}

// RenderSysctl renders 90-falak-hardening.conf for the settings this kernel has.
func RenderSysctl(has func(key string) bool) string {
	var b strings.Builder
	b.WriteString("# Managed by Falak (security fix kernel.sysctl)\n")
	for _, k := range Sysctls {
		if has(k.Key) {
			fmt.Fprintf(&b, "%s = %s\n", k.Key, k.Want)
		}
	}
	return b.String()
}

func (s *Security) fixSysctl(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	var bad []string
	has := func(key string) bool { _, ok := s.sysctl(key); return ok }
	for _, k := range Sysctls {
		if v, ok := s.sysctl(k.Key); ok && !k.OK(v) {
			bad = append(bad, k.Key)
		}
	}
	if len(bad) == 0 {
		return false, "the kernel settings are already hardened", nil
	}
	if err := b.saveFile(SysctlFile); err != nil {
		return false, "", err
	}
	for _, k := range Sysctls {
		if v, ok := s.sysctl(k.Key); ok {
			if err := b.saveSysctl(k.Key, v); err != nil {
				return false, "", err
			}
		}
	}
	if _, err := s.d.FS.WriteFile(SysctlFile, []byte(RenderSysctl(has)), 0o644); err != nil {
		return false, "", err
	}
	if err := s.exec(ctx, st, 30*time.Second, nil, "sysctl", "-p", SysctlFile); err != nil {
		return false, "", fmt.Errorf("sysctl rejected the settings: %w", err)
	}
	sort.Strings(bad)
	return true, "hardened " + list(bad, 4), nil
}

// fixSecretPerms takes the other users' bits off exposed secret files. The files are found again here (the control
// plane never names a path), only under Falak's own paths, and each one is changed through a descriptor opened
// without following symlinks.
func (s *Security) fixSecretPerms(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	files, _ := s.exposedSecrets(ctx)
	roots := append([]string{s.d.SitesRoot}, s.secretRoots()...)
	n := 0
	for _, p := range files {
		confined := false
		for _, r := range roots {
			confined = confined || within(r, p)
		}
		if !confined {
			continue
		}
		changed, err := s.tightenFile(b, p)
		if err != nil {
			return false, "", fmt.Errorf("%s: %w", p, err)
		}
		if changed {
			n++
		}
	}
	if n == 0 {
		return false, "no secret file is readable by other users", nil
	}
	fmt.Fprintf(st.Stdout(), "removed other users' access to %s\n", plural(n, "file", "files"))
	return true, "made " + plural(n, "secret file", "secret files") + " private", nil
}

func (s *Security) tightenFile(b *backup, p string) (bool, error) {
	f, err := os.OpenFile(s.d.FS.P(p), os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return false, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return false, err
	}
	if !fi.Mode().IsRegular() || fi.Mode().Perm()&0o007 == 0 {
		return false, nil
	}
	if err := b.savePerms(p, fi); err != nil {
		return false, err
	}
	return true, f.Chmod(fi.Mode().Perm() &^ 0o007)
}

func (s *Security) fixTimeSync(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	out, ok := s.output(ctx, "timedatectl", "show", "--property=NTP", "--value")
	if !ok {
		return false, "", errors.New("timedatectl is not available")
	}
	prev := strings.TrimSpace(out)
	if prev == "yes" {
		return false, "NTP is already on", nil
	}
	if res, err := s.run(ctx, cmdTimeout, "systemctl", "cat", "systemd-timesyncd.service"); err != nil || res.ExitCode != 0 {
		if _, err := system.AptFor(s.d.Runner, s.d.FS, st).Ensure(ctx, []string{"systemd-timesyncd"}, true); err != nil {
			return false, "", err
		}
	}
	if err := b.setState("ntp", prev); err != nil {
		return false, "", err
	}
	if err := s.exec(ctx, st, time.Minute, nil, "timedatectl", "set-ntp", "true"); err != nil {
		return false, "", err
	}
	return true, "systemd-timesyncd keeps the clock in sync", nil
}

func (s *Security) undoTimeSync(ctx context.Context, m Manifest, st commands.Stream) error {
	if m.State["ntp"] == "yes" {
		return nil
	}
	return s.exec(ctx, st, time.Minute, nil, "timedatectl", "set-ntp", "false")
}
