package security

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// exec runs a fix's command with its output streamed, failing on a non-zero exit.
func (s *Security) exec(ctx context.Context, st commands.Stream, d time.Duration, env []string, name string, args ...string) error {
	c, cancel := context.WithTimeout(ctx, d)
	defer cancel()
	_, err := runner.Check(c, s.d.Runner, runner.Cmd{Name: name, Args: args, Env: env, Stdout: st.Stdout(), Stderr: st.Stderr()})
	return err
}

// active reports whether a systemd unit is active.
func (s *Security) active(ctx context.Context, unit string) bool {
	res, err := s.run(ctx, cmdTimeout, "systemctl", "is-active", "--quiet", unit)
	return err == nil && res.ExitCode == 0
}

// writeKeepMode writes a file, keeping the mode of the file it replaces.
func (s *Security) writeKeepMode(path string, content []byte, mode os.FileMode) error {
	if fi, err := os.Stat(s.d.FS.P(path)); err == nil {
		mode = fi.Mode().Perm()
	}
	_, err := s.d.FS.WriteFile(path, content, mode)
	return err
}

// sshWant is what ssh.harden makes effective (keyword → value).
var sshWant = []struct{ lower, keyword, value string }{
	{"permitrootlogin", "PermitRootLogin", "prohibit-password"},
	{"passwordauthentication", "PasswordAuthentication", "no"},
	{"permitemptypasswords", "PermitEmptyPasswords", "no"},
	{"kbdinteractiveauthentication", "KbdInteractiveAuthentication", "no"},
}

// sshOK reports whether an effective value is hardened (any root login mode but "yes" is; sshd -T prints
// prohibit-password as without-password).
func sshOK(key, value string) bool {
	if key == "permitrootlogin" {
		return value != "yes"
	}
	return value == "no"
}

// SetDirectives sets keywords in an sshd config file: an active line before the first Match block is replaced, else the
// line goes in before the Match block (or at the end).
func SetDirectives(content string, set [][2]string) string {
	lines := strings.Split(strings.TrimRight(content, "\n"), "\n")
	if len(lines) == 1 && lines[0] == "" {
		lines = nil
	}
	for _, kv := range set {
		done := false
		match := len(lines)
		for i, line := range lines {
			k, _, ok := sshLine(line)
			if !ok {
				continue
			}
			if k == "match" {
				match = i
				break
			}
			if k == strings.ToLower(kv[0]) {
				lines[i] = kv[0] + " " + kv[1]
				done = true
				break
			}
		}
		if !done {
			lines = append(lines[:match], append([]string{kv[0] + " " + kv[1]}, lines[match:]...)...)
		}
	}
	return strings.Join(lines, "\n") + "\n"
}

// CommentConflicts comments out the active lines (before any Match block) that set a hardened keyword to an unsafe
// value, so an earlier drop-in (50-cloud-init.conf sorts before 50-falak.conf) or the main file can't win.
func CommentConflicts(content string) (string, bool) {
	lines := strings.Split(content, "\n")
	changed := false
	for i, line := range lines {
		k, v, ok := sshLine(line)
		if !ok {
			continue
		}
		if k == "match" {
			break
		}
		for _, w := range sshWant {
			if k == w.lower && !sshOK(k, strings.ToLower(v)) {
				lines[i] = "# " + strings.TrimSpace(line) + " # disabled by Falak (security fix ssh.harden)"
				changed = true
			}
		}
	}
	return strings.Join(lines, "\n"), changed
}

func (s *Security) fixSSH(ctx context.Context, b *backup, p FixPayload, st commands.Stream) (bool, string, error) {
	eff, _ := s.sshEffective(ctx)
	// Every hardened setting goes into the drop-in (so a later edit elsewhere can't undo it); a stricter root login
	// mode already in effect is kept.
	var set, wrong [][2]string
	for _, w := range sshWant {
		v, ok := eff[w.lower]
		if !ok {
			v = sshDefaults[w.lower]
		}
		want := w.value
		if w.lower == "permitrootlogin" && (v == "no" || v == "forced-commands-only") {
			want = v
		}
		set = append(set, [2]string{w.keyword, want})
		if !sshOK(w.lower, v) {
			wrong = append(wrong, [2]string{w.keyword, want})
		}
	}
	if len(wrong) == 0 {
		return false, "SSH is already hardened", nil
	}
	// Turning password logins off must not lock everyone out.
	if err := s.keyLoginPossible(ctx, eff, p); err != nil {
		return false, "", err
	}
	if err := b.saveFile(SSHDDropIn); err != nil {
		return false, "", err
	}
	cur, ok := s.read(SSHDDropIn)
	if !ok {
		cur = "# Managed by Falak\n"
	}
	if err := s.writeKeepMode(SSHDDropIn, []byte(SetDirectives(cur, set)), 0o644); err != nil {
		return false, "", err
	}
	fmt.Fprintf(st.Stdout(), "%s: %s\n", SSHDDropIn, strings.Join(keywords(set), ", "))
	for _, f := range append(s.sshDropIns(), SSHDConfig) {
		if f == SSHDDropIn {
			continue
		}
		c, ok := s.read(f)
		if !ok {
			continue
		}
		next, changed := CommentConflicts(c)
		if !changed {
			continue
		}
		if err := b.saveFile(f); err != nil {
			return false, "", err
		}
		if err := s.writeKeepMode(f, []byte(next), 0o644); err != nil {
			return false, "", err
		}
		fmt.Fprintf(st.Stdout(), "%s: commented out conflicting settings\n", f)
	}
	if err := s.sshdTest(ctx, st); err != nil {
		return false, "", err
	}
	// sshd -T shows what sshd will really use: a config without the Include of sshd_config.d never reads our drop-in.
	if out, ok := s.output(ctx, "sshd", "-T"); ok {
		now := ParseSSHDT(out)
		for _, w := range sshWant {
			if v, ok := now[w.lower]; ok && !sshOK(w.lower, v) {
				return false, "", fmt.Errorf("sshd still uses %s %s (is %s included by %s?)", w.keyword, v, SSHDDir, SSHDConfig)
			}
		}
	}
	if err := s.reloadSSH(ctx, st); err != nil {
		return false, "", err
	}
	return true, "SSH hardened: " + strings.Join(keywords(wrong), ", "), nil
}

func keywords(set [][2]string) []string {
	var ks []string
	for _, kv := range set {
		ks = append(ks, kv[0]+" "+kv[1])
	}
	return ks
}

func (s *Security) undoSSH(ctx context.Context, _ Manifest, st commands.Stream) error {
	if err := s.sshdTest(ctx, st); err != nil {
		return err
	}
	return s.reloadSSH(ctx, st)
}

// sshdTest validates the config; sshd -t needs its privilege separation directory.
func (s *Security) sshdTest(ctx context.Context, st commands.Stream) error {
	if err := s.d.FS.MkdirAll("/run/sshd", 0o755); err != nil {
		return err
	}
	if err := s.exec(ctx, st, 30*time.Second, nil, "sshd", "-t"); err != nil {
		return fmt.Errorf("sshd -t rejected the config: %w", err)
	}
	return nil
}

// reloadSSH applies the config to the running daemon. A socket-activated sshd that is not running reads it on the
// next connection.
func (s *Security) reloadSSH(ctx context.Context, st commands.Stream) error {
	for _, unit := range []string{"ssh.service", "sshd.service"} {
		if s.active(ctx, unit) {
			return s.exec(ctx, st, time.Minute, nil, "systemctl", "reload-or-restart", unit)
		}
	}
	return nil
}

// keyLoginPossible makes sure somebody can still log in once passwords are off: a user sshd lets in (its settings for
// that user: PermitRootLogin, AllowUsers / DenyUsers / AllowGroups / DenyGroups, from `sshd -T -C`) who has a key
// usable for a shell (no forced command) that Falak did not install for its own falak user. Two-factor logins
// (AuthenticationMethods with keyboard-interactive) would be locked out altogether, so the fix refuses them.
func (s *Security) keyLoginPossible(ctx context.Context, eff map[string]string, p FixPayload) error {
	if strings.Contains(eff["authenticationmethods"], "keyboard-interactive") {
		return errors.New("AuthenticationMethods needs keyboard-interactive (two-factor logins); turning it off would lock those users out: change it by hand")
	}
	keysFile := eff["authorizedkeysfile"]
	if keysFile == "" || keysFile == "none" {
		keysFile = sshDefaults["authorizedkeysfile"]
	}
	falak := map[string]bool{}
	for _, k := range p.ManagedKeys["falak"] {
		if blob := KeyBlob(k); blob != "" {
			falak[blob] = true
		}
	}
	etcGroup, _ := s.read("/etc/group")
	var tried []string
	for _, u := range s.loginUsers() {
		usable := 0
		for _, f := range strings.Fields(keysFile) {
			for _, k := range s.keyEntries(expandKeysPath(f, u.Name, u.Home)) {
				if !k.forced && !(u.Name == "falak" && falak[k.blob]) {
					usable++
				}
			}
		}
		if usable == 0 {
			continue
		}
		tried = append(tried, u.Name)
		cfg := eff
		if out, ok := s.output(ctx, "sshd", "-T", "-C", "user="+u.Name+",host=,addr=127.0.0.1"); ok {
			cfg = ParseSSHDT(out)
		}
		if SSHAllows(cfg, u.Name, UserGroups(etcGroup, u.Name, u.GID)) {
			return nil
		}
	}
	if len(tried) == 0 {
		return errors.New("no user who can log in has an SSH key of their own (keys with a forced command, and the keys Falak installs for its falak user, don't count); add one before turning password logins off")
	}
	return fmt.Errorf("sshd lets none of the users with a key in (%s: PermitRootLogin, AllowUsers, DenyUsers, AllowGroups or DenyGroups); fix that before turning password logins off", strings.Join(tried, ", "))
}

// SSHAllows reports whether sshd's settings for a user (sshd -T -C) let it log in with a key.
func SSHAllows(cfg map[string]string, user string, groups []string) bool {
	if cfg["pubkeyauthentication"] == "no" || strings.Contains(cfg["authenticationmethods"], "keyboard-interactive") {
		return false
	}
	if user == "root" {
		if v := cfg["permitrootlogin"]; v == "no" || v == "forced-commands-only" {
			return false
		}
	}
	matchUser := func(list string) bool {
		for _, pat := range strings.Fields(list) {
			pat, _, _ = strings.Cut(pat, "@")
			if ok, _ := path.Match(pat, user); ok {
				return true
			}
		}
		return false
	}
	matchGroup := func(list string) bool {
		for _, pat := range strings.Fields(list) {
			for _, g := range groups {
				if ok, _ := path.Match(pat, g); ok {
					return true
				}
			}
		}
		return false
	}
	if matchUser(cfg["denyusers"]) || (cfg["allowusers"] != "" && !matchUser(cfg["allowusers"])) {
		return false
	}
	return !matchGroup(cfg["denygroups"]) && (cfg["allowgroups"] == "" || matchGroup(cfg["allowgroups"]))
}

// UserGroups returns a user's groups: its primary group (by gid) and the groups listing it in /etc/group.
func UserGroups(etcGroup, user, gid string) []string {
	var gs []string
	for _, line := range strings.Split(etcGroup, "\n") {
		f := strings.Split(line, ":")
		if len(f) < 4 {
			continue
		}
		if f[2] == gid {
			gs = append(gs, f[0])
			continue
		}
		for _, m := range strings.Split(f[3], ",") {
			if strings.TrimSpace(m) == user {
				gs = append(gs, f[0])
				break
			}
		}
	}
	return gs
}

// recoverSSH restarts sshd after a rollback, so it runs with the restored config, and checks it is up: ssh.socket
// when socket activation is on, else ssh.service (Debian, Ubuntu) or sshd.service.
func (s *Security) recoverSSH(ctx context.Context, st commands.Stream) error {
	units := []string{"ssh.service", "sshd.service"}
	if s.active(ctx, "ssh.socket") {
		units = []string{"ssh.socket"}
	}
	for _, unit := range units {
		if res, err := s.run(ctx, cmdTimeout, "systemctl", "cat", unit); unit != "ssh.socket" && (err != nil || res.ExitCode != 0) {
			continue
		}
		if err := s.exec(ctx, st, time.Minute, nil, "systemctl", "restart", unit); err != nil {
			return err
		}
		if !s.active(ctx, unit) {
			return fmt.Errorf("%s is not active after a restart", unit)
		}
		fmt.Fprintf(st.Stdout(), "%s restarted with the restored config\n", unit)
		return nil
	}
	return errors.New("no SSH unit found")
}
