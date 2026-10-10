package security

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func read(t *testing.T, root, p string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(root, p))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func mode(t *testing.T, root, p string) os.FileMode {
	t.Helper()
	fi, err := os.Lstat(filepath.Join(root, p))
	if err != nil {
		t.Fatal(err)
	}
	return fi.Mode().Perm()
}

func backups(t *testing.T, root string) []string {
	t.Helper()
	ents, _ := os.ReadDir(filepath.Join(root, "/var/lib/falak/security-backups"))
	var ids []string
	for _, e := range ents {
		ids = append(ids, e.Name())
	}
	return ids
}

func manifest(t *testing.T, root, id string) Manifest {
	t.Helper()
	var m Manifest
	if err := json.Unmarshal([]byte(read(t, root, "/var/lib/falak/security-backups/"+id+"/manifest.json")), &m); err != nil {
		t.Fatal(err)
	}
	return m
}

func fix(t *testing.T, s *Security, p FixPayload) FixResult {
	t.Helper()
	res, err := s.Fix(context.Background(), p, &stream{})
	if err != nil {
		t.Fatalf("fix %s: %v", p.FixID, err)
	}
	return res.(FixResult)
}

func undo(t *testing.T, s *Security, fixID, backupID string) UndoResult {
	t.Helper()
	res, err := s.Undo(context.Background(), UndoPayload{FixID: fixID, BackupID: backupID}, &stream{})
	if err != nil {
		t.Fatalf("undo %s: %v", fixID, err)
	}
	return res.(UndoResult)
}

func TestTheAllowlistRefusesEverythingElse(t *testing.T) {
	s, f, _ := newSec(t)
	for _, id := range []string{"", "rm -rf /", "ssh.harden; reboot", "system.exec", "firewall.close_port:tcp:22", "firewall.apply", "SSH.HARDEN"} {
		_, err := s.Fix(context.Background(), FixPayload{FixID: id}, &stream{})
		if !commands.IsPayloadError(err) {
			t.Errorf("%q: %v", id, err)
		}
	}
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "updates.reboot", RebootAt: "04:00; reboot"}, &stream{}); !commands.IsPayloadError(err) {
		t.Errorf("reboot_at: %v", err)
	}
	for _, p := range []UndoPayload{{FixID: "ssh.harden", BackupID: "../../etc"}, {FixID: "updates.install", BackupID: "20261009T120000Z-1a2b3c4d"}, {FixID: "nope", BackupID: "20261009T120000Z-1a2b3c4d"}} {
		if _, err := s.Undo(context.Background(), p, &stream{}); !commands.IsPayloadError(err) {
			t.Errorf("%+v: %v", p, err)
		}
	}
	if len(f.Calls()) != 0 {
		t.Fatalf("ran %v", f.Lines())
	}
}

// sshHost scripts sshd: -T computes the effective config from the files on disk, like the real one.
func sshHost(t *testing.T) (*Security, *runnertest.Fake, string) {
	s, f, root := newSec(t)
	passwd(root, t)
	put(t, root, "/root/.ssh/authorized_keys", keyA+"\n", 0o600)
	put(t, root, SSHDConfig, "Include /etc/ssh/sshd_config.d/*.conf\nPasswordAuthentication yes\nKbdInteractiveAuthentication no\nUsePAM yes\n", 0o644)
	put(t, root, SSHDDir+"/50-cloud-init.conf", "PasswordAuthentication yes\n", 0o600)
	put(t, root, SSHDDropIn, "# Managed by Falak\nPort 22\nPermitRootLogin prohibit-password\nPasswordAuthentication no\n", 0o644)
	f.OnFunc("sshd -T", func(runnertest.Call) (runner.Result, error) {
		main, _ := s.read(SSHDConfig)
		var drops []string
		for _, d := range s.sshDropIns() {
			c, _ := s.read(d)
			drops = append(drops, c)
		}
		var b strings.Builder
		for k, v := range EffectiveFromFiles(main, drops) {
			b.WriteString(k + " " + v + "\n")
		}
		return ok(b.String()), nil
	})
	f.On("systemctl is-active --quiet ssh.service", ok(""))
	return s, f, root
}

func TestSSHFixBacksUpValidatesAndUndoesExactly(t *testing.T) {
	s, f, root := sshHost(t)
	beforeDrop, beforeCloud, beforeMain := read(t, root, SSHDDropIn), read(t, root, SSHDDir+"/50-cloud-init.conf"), read(t, root, SSHDConfig)
	if c := byID(t, s.sshChecks(context.Background(), AuditPayload{}), "ssh.password_authentication"); c.Status != Fail {
		t.Fatalf("precondition: %+v", c)
	}
	res := fix(t, s, FixPayload{FixID: "ssh.harden"})
	if !res.Changed || !res.Undoable || !res.Disruptive || !BackupIDRe.MatchString(res.BackupID) {
		t.Fatalf("%+v", res)
	}
	drop := read(t, root, SSHDDropIn)
	for _, want := range []string{"Port 22\n", "PasswordAuthentication no\n", "PermitEmptyPasswords no\n", "KbdInteractiveAuthentication no\n"} {
		if !strings.Contains(drop, want) {
			t.Errorf("drop-in lacks %q:\n%s", want, drop)
		}
	}
	if got := read(t, root, SSHDDir+"/50-cloud-init.conf"); !strings.HasPrefix(got, "# PasswordAuthentication yes # disabled by Falak") {
		t.Errorf("cloud-init drop-in: %q", got)
	}
	for _, c := range s.sshChecks(context.Background(), AuditPayload{}) {
		if c.ID != "ssh.authorized_keys" && c.Status != Pass {
			t.Errorf("after the fix: %+v", c)
		}
	}
	if !f.Ran("sshd -t") || !f.Ran("systemctl reload-or-restart ssh.service") {
		t.Fatalf("not validated / reloaded: %v", f.Lines())
	}
	m := manifest(t, root, res.BackupID)
	if m.FixID != "ssh.harden" || len(m.Files) != 3 {
		t.Fatalf("manifest %+v", m)
	}
	for _, e := range m.Files {
		orig := map[string]string{SSHDDropIn: beforeDrop, SSHDDir + "/50-cloud-init.conf": beforeCloud, SSHDConfig: beforeMain}[e.Path]
		sum := sha256.Sum256([]byte(orig))
		if !e.Existed || e.SHA256 != hex.EncodeToString(sum[:]) || e.Stored == "" {
			t.Errorf("entry %+v", e)
		}
	}
	if e := m.Files[1]; e.Path != SSHDDir+"/50-cloud-init.conf" || e.Mode != 0o600 {
		t.Errorf("mode not recorded: %+v", e)
	}

	// Idempotent: nothing left to do, no new backup.
	again := fix(t, s, FixPayload{FixID: "ssh.harden"})
	if again.Changed || again.BackupID != "" || len(backups(t, root)) != 1 {
		t.Fatalf("second run %+v, backups %v", again, backups(t, root))
	}

	f.Reset()
	u := undo(t, s, "ssh.harden", res.BackupID)
	if u.Restored != 3 {
		t.Fatalf("%+v", u)
	}
	if read(t, root, SSHDDropIn) != beforeDrop || read(t, root, SSHDDir+"/50-cloud-init.conf") != beforeCloud || read(t, root, SSHDConfig) != beforeMain {
		t.Fatal("undo did not restore the files exactly")
	}
	if mode(t, root, SSHDDir+"/50-cloud-init.conf") != 0o600 {
		t.Error("mode not restored")
	}
	if !f.Ran("sshd -t") || !f.Ran("systemctl reload-or-restart ssh.service") || len(backups(t, root)) != 0 {
		t.Fatalf("undo: %v, backups %v", f.Lines(), backups(t, root))
	}
	if _, err := s.Undo(context.Background(), UndoPayload{FixID: "ssh.harden", BackupID: res.BackupID}, &stream{}); err == nil {
		t.Fatal("a backup is undone twice")
	}
}

func TestSSHFixRollsBackWhenSSHDRejectsTheConfig(t *testing.T) {
	s, f, root := sshHost(t)
	f.On("sshd -t", runner.Result{ExitCode: 255, Stderr: []byte("bad configuration option")})
	beforeDrop, beforeCloud := read(t, root, SSHDDropIn), read(t, root, SSHDDir+"/50-cloud-init.conf")
	_, err := s.Fix(context.Background(), FixPayload{FixID: "ssh.harden"}, &stream{})
	if err == nil || !strings.Contains(err.Error(), "sshd -t rejected") || !strings.Contains(err.Error(), "rolled back") {
		t.Fatalf("err %v", err)
	}
	if read(t, root, SSHDDropIn) != beforeDrop || read(t, root, SSHDDir+"/50-cloud-init.conf") != beforeCloud {
		t.Fatal("not rolled back")
	}
	if f.Ran("systemctl reload-or-restart") || len(backups(t, root)) != 0 {
		t.Fatalf("%v %v", f.Lines(), backups(t, root))
	}
}

// sshd that never reads sshd_config.d: the fix sees the settings did not take and rolls back.
func TestSSHFixRollsBackWhenTheDropInIsNotIncluded(t *testing.T) {
	s, f, root := newSec(t)
	passwd(root, t)
	put(t, root, "/root/.ssh/authorized_keys", keyA+"\n", 0o600)
	put(t, root, SSHDConfig, "PasswordAuthentication yes\n", 0o644)
	f.On("sshd -T", ok("passwordauthentication yes\npermitrootlogin yes\n"))
	before := read(t, root, SSHDConfig)
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "ssh.harden"}, &stream{}); err == nil || !strings.Contains(err.Error(), "sshd still uses") {
		t.Fatalf("err %v", err)
	}
	if read(t, root, SSHDConfig) != before || fileExists(root, SSHDDropIn) {
		t.Fatal("not rolled back")
	}
}

func fileExists(root, p string) bool {
	_, err := os.Lstat(filepath.Join(root, p))
	return err == nil
}

func TestSSHFixRefusesToLockEveryoneOut(t *testing.T) {
	s, _, root := sshHost(t)
	if err := os.Remove(filepath.Join(root, "/root/.ssh/authorized_keys")); err != nil {
		t.Fatal(err)
	}
	before := read(t, root, SSHDDropIn)
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "ssh.harden"}, &stream{}); err == nil || !strings.Contains(err.Error(), "has an SSH key") {
		t.Fatalf("err %v", err)
	}
	if read(t, root, SSHDDropIn) != before {
		t.Fatal("changed")
	}
}

func TestSysctlFixAndUndo(t *testing.T) {
	s, f, root := newSec(t)
	sysctls(t, root, map[string]string{"net.ipv4.conf.all.rp_filter": "0", "kernel.dmesg_restrict": "0", "net.ipv6.conf.all.accept_redirects": "-"})
	res := fix(t, s, FixPayload{FixID: "kernel.sysctl"})
	if !res.Changed || res.BackupID == "" || res.Disruptive {
		t.Fatalf("%+v", res)
	}
	conf := read(t, root, SysctlFile)
	if !strings.Contains(conf, "net.ipv4.conf.all.rp_filter = 2\n") || !strings.Contains(conf, "kernel.dmesg_restrict = 1\n") || strings.Contains(conf, "ipv6") {
		t.Fatalf("%s", conf)
	}
	if !f.Ran("sysctl -p " + SysctlFile) {
		t.Fatal(f.Lines())
	}
	m := manifest(t, root, res.BackupID)
	if m.Sysctl["net.ipv4.conf.all.rp_filter"] != "0" || m.Sysctl["kernel.dmesg_restrict"] != "0" || len(m.Files) != 1 || m.Files[0].Existed {
		t.Fatalf("%+v", m)
	}
	f.Reset()
	undo(t, s, "kernel.sysctl", res.BackupID)
	if fileExists(root, SysctlFile) || !f.Ran("sysctl -q -w net.ipv4.conf.all.rp_filter=0") || !f.Ran("sysctl -q -w kernel.dmesg_restrict=0") {
		t.Fatalf("%v", f.Lines())
	}

	// sysctl refuses: the file goes and the live values are put back.
	f.Reset()
	f.On("sysctl -p", runner.Result{ExitCode: 255, Stderr: []byte("permission denied on key")})
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "kernel.sysctl"}, &stream{}); err == nil {
		t.Fatal("no error")
	}
	if fileExists(root, SysctlFile) || !f.Ran("sysctl -q -w net.ipv4.conf.all.rp_filter=0") || len(backups(t, root)) != 0 {
		t.Fatalf("not rolled back: %v", f.Lines())
	}
}

func TestSecretPermissionFixStaysInFalakPaths(t *testing.T) {
	s, _, root := newSec(t) // RootUID 0: the test's files belong to a site user
	put(t, root, "/srv/falak/sites/shop/current/.env", "APP_KEY=base64:secret\n", 0o644)
	put(t, root, "/srv/falak/sites/shop/shared/.env.production", "X=1\n", 0o604)
	put(t, root, "/etc/shadow", "root:*:", 0o644)
	if err := os.Symlink(filepath.Join(root, "/etc/shadow"), filepath.Join(root, "/srv/falak/sites/shop/.env")); err != nil {
		t.Fatal(err)
	}
	// Container secrets are world-readable on purpose: never touched.
	put(t, root, "/run/falak/secrets/shop-app/db_password", "pw", 0o444)
	res := fix(t, s, FixPayload{FixID: "files.secret_permissions"})
	if !res.Changed || res.Message != "made 2 secret files private" {
		t.Fatalf("%+v", res)
	}
	if mode(t, root, "/srv/falak/sites/shop/current/.env") != 0o640 || mode(t, root, "/srv/falak/sites/shop/shared/.env.production") != 0o600 ||
		mode(t, root, "/etc/shadow") != 0o644 || mode(t, root, "/run/falak/secrets/shop-app/db_password") != 0o444 {
		t.Fatal("modes")
	}
	m := manifest(t, root, res.BackupID)
	for _, e := range m.Files {
		if e.Stored != "" || e.Inode == 0 {
			t.Errorf("secret content copied or no inode: %+v", e)
		}
	}
	if raw := read(t, root, "/var/lib/falak/security-backups/"+res.BackupID+"/manifest.json"); strings.Contains(raw, "APP_KEY") {
		t.Fatal("secret in the manifest")
	}
	undo(t, s, "files.secret_permissions", res.BackupID)
	if mode(t, root, "/srv/falak/sites/shop/current/.env") != 0o644 || mode(t, root, "/srv/falak/sites/shop/shared/.env.production") != 0o604 {
		t.Fatal("undo")
	}

	// A file swapped in after the fix keeps its mode: undo refuses to touch it, even forced.
	res = fix(t, s, FixPayload{FixID: "files.secret_permissions"})
	p := filepath.Join(root, "/srv/falak/sites/shop/current/.env")
	if err := os.Remove(p); err != nil {
		t.Fatal(err)
	}
	put(t, root, "/srv/falak/sites/shop/current/.env", "other", 0o600)
	if _, err := s.Undo(context.Background(), UndoPayload{FixID: "files.secret_permissions", BackupID: res.BackupID, Force: true}, &stream{}); err == nil || !strings.Contains(err.Error(), "replaced") {
		t.Fatalf("err %v", err)
	}
	if mode(t, root, "/srv/falak/sites/shop/current/.env") != 0o600 {
		t.Fatal("swapped file changed")
	}
}

func TestSecretPermissionFixRefusesLinksAndForeignOwners(t *testing.T) {
	s, _, root := newSec(t)
	put(t, root, "/srv/falak/sites/shop/current/.env", "A=1", 0o644)
	// A hard link to a file elsewhere: refused (protected_hardlinks may be off).
	if err := os.Link(filepath.Join(root, "/srv/falak/sites/shop/current/.env"), filepath.Join(root, "/srv/falak/sites/shop/linked")); err != nil {
		t.Fatal(err)
	}
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "files.secret_permissions"}, &stream{}); err == nil || !strings.Contains(err.Error(), "hard links") {
		t.Fatalf("hard link: %v", err)
	}
	if mode(t, root, "/srv/falak/sites/shop/current/.env") != 0o644 {
		t.Fatal("changed")
	}

	// A site directory owned by "root" (here: the test's own uid as RootUID) is refused.
	s, _, root = newSec(t)
	s.d.RootUID = os.Getuid()
	put(t, root, "/srv/falak/sites/shop/.env", "A=1", 0o644)
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "files.secret_permissions"}, &stream{}); err == nil || !strings.Contains(err.Error(), "belongs to root") {
		t.Fatalf("root-owned site: %v", err)
	}
	// The tmpfs env files are root's: fixed then.
	put(t, root, "/run/falak/env/shop.env", "A=1", 0o604)
	res := fix(t, s, FixPayload{FixID: "files.secret_permissions"})
	if !res.Changed || mode(t, root, "/run/falak/env/shop.env") != 0o600 {
		t.Fatalf("%+v", res)
	}

	// Symlinked directories on the way are refused by openConfined.
	put(t, root, "/elsewhere/.env", "A=1", 0o644)
	if err := os.Symlink(filepath.Join(root, "/elsewhere"), filepath.Join(root, "/srv/falak/sites/shop/current")); err != nil {
		t.Fatal(err)
	}
	if _, err := s.openConfined("/srv/falak/sites", "/srv/falak/sites/shop/current/.env"); err == nil {
		t.Fatal("followed a symlinked directory")
	}
	if _, err := s.openConfined("/srv/falak/sites", "/srv/falak/sites/../../etc/shadow"); err == nil {
		t.Fatal("escaped")
	}
}

func TestDockerTCPFix(t *testing.T) {
	s, f, root := newSec(t)
	orig := `{"live-restore": true, "hosts": ["unix:///var/run/docker.sock", "tcp://0.0.0.0:2375"]}`
	put(t, root, DaemonJSON, orig, 0o600)
	res := fix(t, s, FixPayload{FixID: "docker.tcp_off"})
	if !res.Changed || !res.Disruptive {
		t.Fatalf("%+v", res)
	}
	var cfg map[string]any
	if err := json.Unmarshal([]byte(read(t, root, DaemonJSON)), &cfg); err != nil {
		t.Fatal(err)
	}
	if hosts := cfg["hosts"].([]any); len(hosts) != 1 || hosts[0] != "unix:///var/run/docker.sock" || cfg["live-restore"] != true || mode(t, root, DaemonJSON) != 0o600 {
		t.Fatalf("%v", cfg)
	}
	if !f.Ran("dockerd --validate --config-file "+DaemonJSON) || !f.Ran("systemctl restart docker.service") {
		t.Fatal(f.Lines())
	}
	undo(t, s, "docker.tcp_off", res.BackupID)
	if read(t, root, DaemonJSON) != orig {
		t.Fatal("undo")
	}

	// Docker does not come back: the old file is restored and Docker restarted with it.
	f.Reset()
	f.On("systemctl restart docker.service", fail(1))
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "docker.tcp_off"}, &stream{}); err == nil {
		t.Fatal("no error")
	}
	n := 0
	for _, l := range f.Lines() {
		if l == "systemctl restart docker.service" {
			n++
		}
	}
	if read(t, root, DaemonJSON) != orig || n != 2 {
		t.Fatalf("restarts %d, %v", n, f.Lines())
	}

	if out, changed, _ := DisableDaemonTCP([]byte(`{"hosts":["tcp://127.0.0.1:2375"],"debug":true}`)); !changed || strings.Contains(string(out), "hosts") {
		t.Fatalf("%s", out)
	}
}

func TestRebootTimeSyncFail2banAndUnattendedFixes(t *testing.T) {
	s, f, root := newSec(t)
	if res := fix(t, s, FixPayload{FixID: "updates.reboot"}); res.Changed {
		t.Fatalf("no reboot required: %+v", res)
	}
	put(t, root, RebootRequired, "", 0o644)
	f.On("systemctl is-active --quiet "+RebootUnit+".timer", fail(3))
	res := fix(t, s, FixPayload{FixID: "updates.reboot", RebootAt: "03:15"})
	if !res.Changed || res.BackupID == "" || !f.Ran("systemd-run --unit="+RebootUnit+" --on-calendar=*-*-* 03:15:00") {
		t.Fatalf("%+v %v", res, f.Lines())
	}
	undo(t, s, "updates.reboot", res.BackupID)
	if !f.Ran("systemctl stop " + RebootUnit + ".timer") {
		t.Fatal(f.Lines())
	}

	f.On("timedatectl show --property=NTP --value", ok("no\n"))
	f.On("systemctl cat systemd-timesyncd.service", ok(""))
	res = fix(t, s, FixPayload{FixID: "time.sync"})
	if !res.Changed || !f.Ran("timedatectl set-ntp true") {
		t.Fatalf("%+v", res)
	}
	undo(t, s, "time.sync", res.BackupID)
	if !f.Ran("timedatectl set-ntp false") {
		t.Fatal(f.Lines())
	}

	f.On("dpkg-query", runner.Result{ExitCode: 0, Stdout: []byte("fail2ban\tinstalled\t1.0\nunattended-upgrades\tinstalled\t2.9\n")})
	f.On("sshd -T", ok("port 2222\n"))
	f.On("fail2ban-client -t", fail(1))
	f.On("systemctl is-active --quiet fail2ban.service", fail(3))
	before := backups(t, root)
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "fail2ban.sshd"}, &stream{}); err == nil || fileExists(root, Fail2banJail) || len(backups(t, root)) != len(before) {
		t.Fatalf("fail2ban rollback: %v", err)
	}
}

func TestFail2banFixWritesTheJail(t *testing.T) {
	s, f, root := newSec(t)
	f.On("systemctl is-active --quiet fail2ban.service", fail(3))
	f.On("dpkg-query", ok("fail2ban\tinstalled\t1.0\n"))
	f.On("sshd -T", ok("port 2222\n"))
	res := fix(t, s, FixPayload{FixID: "fail2ban.sshd"})
	if !res.Changed || !strings.Contains(read(t, root, Fail2banJail), "[sshd]\nenabled = true\nport = 2222\nbackend = systemd\n") {
		t.Fatalf("%+v\n%s", res, read(t, root, Fail2banJail))
	}
	if !f.Ran("fail2ban-client -t") || !f.Ran("systemctl restart fail2ban.service") {
		t.Fatal(f.Lines())
	}
	undo(t, s, "fail2ban.sshd", res.BackupID)
	if fileExists(root, Fail2banJail) {
		t.Fatal("jail left after undo")
	}
}

func TestUnattendedFix(t *testing.T) {
	s, f, root := newSec(t)
	installed := false
	f.OnFunc("dpkg-query -W -f=${db:Status-Status}", func(runnertest.Call) (runner.Result, error) {
		if installed {
			return ok("installed"), nil
		}
		return fail(1), nil
	})
	f.OnFunc("dpkg-query", func(runnertest.Call) (runner.Result, error) {
		if installed {
			return ok("unattended-upgrades\tinstalled\t2.9\n"), nil
		}
		return fail(1), nil
	})
	f.OnFunc("apt-get install", func(runnertest.Call) (runner.Result, error) { installed = true; return ok(""), nil })
	put(t, root, AutoUpgrades, "APT::Periodic::Unattended-Upgrade \"0\";\n", 0o644)
	res := fix(t, s, FixPayload{FixID: "updates.unattended"})
	if !res.Changed || !strings.Contains(read(t, root, AutoUpgrades), `Unattended-Upgrade "1"`) || !fileExists(root, FalakUnattended) || !f.Ran("apt-config dump") {
		t.Fatalf("%+v %v", res, f.Lines())
	}
	if on, _ := s.unattendedOn(context.Background()); !on {
		t.Fatal("still off")
	}
	undo(t, s, "updates.unattended", res.BackupID)
	if read(t, root, AutoUpgrades) != "APT::Periodic::Unattended-Upgrade \"0\";\n" || fileExists(root, FalakUnattended) {
		t.Fatal("undo")
	}
}

func TestInstallUpdatesNeedsUnattendedAndIsNotUndoable(t *testing.T) {
	s, f, _ := newSec(t)
	f.On("apt-get -s", ok(aptOut))
	f.On("dpkg-query -W -f=${db:Status-Status} unattended-upgrades", fail(1))
	if _, err := s.Fix(context.Background(), FixPayload{FixID: "updates.install"}, &stream{}); err == nil || !strings.Contains(err.Error(), "unattended-upgrades is off") {
		t.Fatalf("%v", err)
	}
	s, f, root := newSec(t)
	f.On("apt-get -s", ok(aptOut))
	f.On("dpkg-query -W -f=${db:Status-Status} unattended-upgrades", ok("installed"))
	put(t, root, AutoUpgrades, "APT::Periodic::Unattended-Upgrade \"1\";\n", 0o644)
	res := fix(t, s, FixPayload{FixID: "updates.install"})
	if !res.Changed || res.Undoable || res.BackupID != "" || !f.Ran("apt-get update") || !f.Ran("unattended-upgrade -v") {
		t.Fatalf("%+v %v", res, f.Lines())
	}
}

func TestBackupsExpireAfterSevenDays(t *testing.T) {
	s, _, root := newSec(t)
	now := time.Date(2026, 10, 9, 12, 0, 0, 0, time.UTC)
	s.d.Now = func() time.Time { return now }
	sysctls(t, root, map[string]string{"kernel.dmesg_restrict": "0"})
	res := fix(t, s, FixPayload{FixID: "kernel.sysctl"})
	// A stray directory that is not a backup id is never touched.
	if err := os.MkdirAll(filepath.Join(root, "/var/lib/falak/security-backups/keep-me"), 0o700); err != nil {
		t.Fatal(err)
	}
	now = now.Add(BackupTTL - time.Minute)
	s.prune()
	if len(backups(t, root)) != 2 {
		t.Fatal("pruned too early")
	}
	now = now.Add(2 * time.Minute)
	if _, err := s.Undo(context.Background(), UndoPayload{FixID: "kernel.sysctl", BackupID: res.BackupID}, &stream{}); err == nil || !strings.Contains(err.Error(), "is gone") {
		t.Fatalf("expired undo: %v", err)
	}
	if b := backups(t, root); len(b) != 1 || b[0] != "keep-me" {
		t.Fatalf("%v", b)
	}
}

func TestUndoChecksTheBackupBelongsToTheFix(t *testing.T) {
	s, _, root := newSec(t)
	sysctls(t, root, map[string]string{"kernel.dmesg_restrict": "0"})
	res := fix(t, s, FixPayload{FixID: "kernel.sysctl"})
	if _, err := s.Undo(context.Background(), UndoPayload{FixID: "ssh.harden", BackupID: res.BackupID}, &stream{}); !commands.IsPayloadError(err) {
		t.Fatalf("%v", err)
	}
	// A tampered backup copy is refused instead of written over a system file.
	put(t, root, procPath("kernel.dmesg_restrict"), "0\n", 0o644)
	put(t, root, SysctlFile, "x = 1\n", 0o644)
	res2 := fix(t, s, FixPayload{FixID: "kernel.sysctl"})
	m := manifest(t, root, res2.BackupID)
	put(t, root, "/var/lib/falak/security-backups/"+res2.BackupID+"/"+m.Files[0].Stored, "kernel.modules_disabled = 1\n", 0o600)
	if _, err := s.Undo(context.Background(), UndoPayload{FixID: "kernel.sysctl", BackupID: res2.BackupID}, &stream{}); err == nil || !strings.Contains(err.Error(), "checksum") {
		t.Fatalf("%v", err)
	}
	if st, err := os.Stat(filepath.Join(root, "/var/lib/falak/security-backups/"+res2.BackupID)); err != nil || st.Mode().Perm() != 0o700 {
		t.Fatalf("backup dir %v %v", st, err)
	}
}
