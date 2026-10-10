package security

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
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
	// needrestart only lists what needs a restart: services (the agent among them) are never restarted from here.
	if err := s.exec(ctx, st, updatesLimit, listOnly(system.AptEnv), "unattended-upgrade", "-v"); err != nil {
		return false, "", err
	}
	msg := "installed " + plural(len(pkgs), "security update", "security updates")
	if svcs := s.needRestart(ctx); len(svcs) > 0 {
		msg += "; to restart: " + list(svcs, 6)
	}
	if s.d.FS.Exists(RebootRequired) {
		msg += "; a reboot is required"
	}
	return true, msg, nil
}

// listOnly is the apt environment with needrestart in list mode.
func listOnly(env []string) []string {
	out := make([]string, 0, len(env)+1)
	for _, kv := range env {
		if !strings.HasPrefix(kv, "NEEDRESTART_MODE=") {
			out = append(out, kv)
		}
	}
	return append(out, "NEEDRESTART_MODE=l", "NEEDRESTART_SUSPEND=1")
}

// needRestart lists the services running outdated libraries (needrestart's batch mode), when needrestart exists.
func (s *Security) needRestart(ctx context.Context) []string {
	out, ok := s.output(ctx, "needrestart", "-b", "-r", "l")
	if !ok {
		return nil
	}
	return ParseNeedRestart(out)
}

// ParseNeedRestart reads `needrestart -b` ("NEEDRESTART-SVC: nginx.service").
func ParseNeedRestart(out string) []string {
	var svcs []string
	for _, line := range strings.Split(out, "\n") {
		if svc, ok := strings.CutPrefix(strings.TrimSpace(line), "NEEDRESTART-SVC:"); ok && strings.TrimSpace(svc) != "" {
			svcs = append(svcs, strings.TrimSpace(svc))
		}
	}
	return svcs
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

// LiveRestore reports whether daemon.json turns live-restore on (containers keep running while dockerd restarts).
func LiveRestore(raw []byte) bool {
	var cfg struct {
		LiveRestore bool `json:"live-restore"`
	}
	return json.Unmarshal(raw, &cfg) == nil && cfg.LiveRestore
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
	liveRestore := LiveRestore([]byte(raw))
	if !liveRestore {
		fmt.Fprintln(st.Stdout(), "live-restore is off: restarting Docker restarts every container")
	}
	if err := s.exec(ctx, st, 3*time.Minute, nil, "systemctl", "restart", "docker.service"); err != nil {
		// Put the old configuration back and start Docker with it.
		_ = b.restore(context.WithoutCancel(ctx))
		_ = s.exec(context.WithoutCancel(ctx), st, 3*time.Minute, nil, "systemctl", "restart", "docker.service")
		return false, "", fmt.Errorf("docker did not restart with the new configuration: %w", err)
	}
	if !liveRestore {
		return true, "the Docker API listens on its unix socket only; live-restore is off, so every container restarted", nil
	}
	return true, "the Docker API listens on its unix socket only (live-restore kept the containers running)", nil
}

func (s *Security) undoDockerTCP(ctx context.Context, _ Manifest, st commands.Stream) error {
	return s.exec(ctx, st, 3*time.Minute, nil, "systemctl", "restart", "docker.service")
}

// RenderSysctl renders 90-falak-hardening.conf for the settings this kernel has. A setting that already passes keeps
// its value (kptr_restrict 2, strict rp_filter 1): the fix never loosens anything.
func RenderSysctl(value func(key string) (string, bool)) string {
	var b strings.Builder
	b.WriteString("# Managed by Falak (security fix kernel.sysctl)\n")
	for _, k := range Sysctls {
		if cur, ok := value(k.Key); ok {
			fmt.Fprintf(&b, "%s = %s\n", k.Key, k.Stricter(cur))
		}
	}
	return b.String()
}

func (s *Security) fixSysctl(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	var bad []string
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
	if _, err := s.d.FS.WriteFile(SysctlFile, []byte(RenderSysctl(s.sysctl)), 0o644); err != nil {
		return false, "", err
	}
	if err := s.exec(ctx, st, 30*time.Second, nil, "sysctl", "-p", SysctlFile); err != nil {
		return false, "", fmt.Errorf("sysctl rejected the settings: %w", err)
	}
	sort.Strings(bad)
	return true, "hardened " + list(bad, 4), nil
}

// fixSecretPerms takes the other users' bits off exposed secret files. The files are found again here (the control
// plane never names a path), only under the sites root and the tmpfs env directory, and each one is opened from that
// root one component at a time without following symlinks (openConfined). A file with more than one link, or owned
// by anyone but its site's user (the env directory: root), is left alone and listed.
func (s *Security) fixSecretPerms(ctx context.Context, b *backup, _ FixPayload, st commands.Stream) (bool, string, error) {
	files, _ := s.exposedSecrets(ctx)
	n := 0
	var skipped []string
	for _, p := range files {
		var changed bool
		var err error
		switch {
		case within(s.envDir(), p):
			changed, err = s.tightenFile(b, s.envDir(), p, s.d.RootUID)
		case within(s.d.SitesRoot, p):
			owner, oerr := s.siteOwner(p)
			if oerr != nil {
				err = oerr
				break
			}
			changed, err = s.tightenFile(b, s.d.SitesRoot, p, owner)
		default:
			continue
		}
		if err != nil {
			skipped = append(skipped, p+": "+err.Error())
			continue
		}
		if changed {
			n++
		}
	}
	for _, sk := range skipped {
		fmt.Fprintf(st.Stderr(), "left alone: %s\n", sk)
	}
	if n == 0 {
		if len(skipped) > 0 {
			return false, "", fmt.Errorf("no secret file could be changed safely: %s", list(skipped, 3))
		}
		return false, "no secret file is readable by other users", nil
	}
	msg := "made " + plural(n, "secret file", "secret files") + " private"
	if len(skipped) > 0 {
		msg += "; left alone: " + list(skipped, 3)
	}
	return true, msg, nil
}

// siteOwner is the uid owning a path's site directory (<sites root>/<site>); root-owned sites are refused.
func (s *Security) siteOwner(p string) (int, error) {
	rel, err := filepath.Rel(s.d.SitesRoot, p)
	if err != nil || rel == "." || strings.HasPrefix(rel, "..") {
		return 0, errors.New("not under the sites root")
	}
	site, _, _ := strings.Cut(rel, "/")
	fi, err := os.Lstat(s.d.FS.P(filepath.Join(s.d.SitesRoot, site)))
	if err != nil || !fi.IsDir() {
		return 0, errors.New("the site directory is not a directory")
	}
	uid := uidOf(fi)
	if uid == s.d.RootUID || uid < 0 {
		return 0, errors.New("the site directory belongs to root")
	}
	return uid, nil
}

// openConfined opens p (a host path below root) through a chain of os.Root handles, one component at a time: no
// directory on the way and not the file itself may be a symlink, and a directory swapped between the check and the
// open is refused. os.Root keeps every step inside root whatever happens.
func (s *Security) openConfined(root, p string) (*os.File, error) {
	rel, err := filepath.Rel(root, p)
	if err != nil || rel == "." || strings.HasPrefix(rel, "..") {
		return nil, errors.New("outside its root")
	}
	r, err := os.OpenRoot(s.d.FS.P(root))
	if err != nil {
		return nil, err
	}
	parts := strings.Split(rel, string(filepath.Separator))
	for _, dir := range parts[:len(parts)-1] {
		fi, err := r.Lstat(dir)
		if err != nil {
			r.Close()
			return nil, err
		}
		if !fi.IsDir() {
			r.Close()
			return nil, errors.New("a symlink or file on the way")
		}
		next, err := r.OpenRoot(dir)
		r.Close()
		if err != nil {
			return nil, err
		}
		if now, err := next.Stat("."); err != nil || !os.SameFile(fi, now) {
			next.Close()
			return nil, errors.New("a directory on the way was swapped")
		}
		r = next
	}
	defer r.Close()
	return r.OpenFile(parts[len(parts)-1], os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
}

func (s *Security) tightenFile(b *backup, root, p string, owner int) (bool, error) {
	f, err := s.openConfined(root, p)
	if err != nil {
		return false, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return false, err
	}
	if !fi.Mode().IsRegular() {
		return false, errors.New("not a regular file")
	}
	st, ok := fi.Sys().(*syscall.Stat_t)
	if !ok || st.Nlink != 1 {
		return false, errors.New("it has other hard links")
	}
	if int(st.Uid) != owner {
		return false, fmt.Errorf("owned by uid %d, not %d", st.Uid, owner)
	}
	if fi.Mode().Perm()&0o007 == 0 {
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
