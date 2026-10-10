package security

import (
	"bytes"
	"context"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

type stream struct {
	mu  sync.Mutex
	out bytes.Buffer
}

type sw struct{ s *stream }

func (w sw) Write(p []byte) (int, error) {
	w.s.mu.Lock()
	defer w.s.mu.Unlock()
	return w.s.out.Write(p)
}

func (s *stream) Stdout() io.Writer                { return sw{s} }
func (s *stream) Stderr() io.Writer                { return sw{s} }
func (s *stream) Progress(float64)                 {}
func (s *stream) Emit(string, string)              {}
func (s *stream) String() string                   { s.mu.Lock(); defer s.mu.Unlock(); return s.out.String() }
func fail(code int) runner.Result                  { return runner.Result{ExitCode: code} }
func ok(out string) runner.Result                  { return runner.Result{Stdout: []byte(out)} }
func onOut(f *runnertest.Fake, prefix, out string) { f.On(prefix, ok(out)) }

// newSec builds the executors on a temp host root. Unmatched commands fail (exit 1), as on a host without the tool.
func newSec(t *testing.T) (*Security, *runnertest.Fake, string) {
	t.Helper()
	root := t.TempDir()
	f := &runnertest.Fake{}
	s := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	return s, f, root
}

// catchAll makes every command not scripted by the test fail; call it after the test's own rules.
func catchAll(f *runnertest.Fake) { f.On("", fail(1)) }

func put(t *testing.T, root, p, content string, mode os.FileMode) {
	t.Helper()
	real := filepath.Join(root, p)
	if err := os.MkdirAll(filepath.Dir(real), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(real, []byte(content), mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(real, mode); err != nil {
		t.Fatal(err)
	}
}

func byID(t *testing.T, cs []Check, id string) Check {
	t.Helper()
	for _, c := range cs {
		if c.ID == id {
			return c
		}
	}
	t.Fatalf("no check %s in %+v", id, cs)
	return Check{}
}

func hasID(cs []Check, id string) bool {
	for _, c := range cs {
		if c.ID == id {
			return true
		}
	}
	return false
}

const (
	keyA = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGkq1Qk0bJ0m7xYk6o8y2e8b5gKq0bJ8m1e3Yx5qZ8Vw a@laptop"
	keyB = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB b@elsewhere"
	keyC = "ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBCCCC c@cloud"
)

func passwd(root string, t *testing.T) {
	put(t, root, "/etc/passwd", "root:x:0:0:root:/root:/bin/bash\ndaemon:x:1:1::/usr/sbin:/usr/sbin/nologin\nubuntu:x:1000:1000::/home/ubuntu:/bin/bash\nfalak:x:1001:1001::/home/falak:/bin/bash\n", 0o644)
}

func TestSSHChecksReadTheFilesTheWaySSHDDoes(t *testing.T) {
	s, f, root := newSec(t)
	catchAll(f) // sshd -T fails: no /run/sshd
	put(t, root, SSHDConfig, "Include /etc/ssh/sshd_config.d/*.conf\nPermitRootLogin yes\n#PasswordAuthentication no\nMatch User x\n  PermitEmptyPasswords yes\n", 0o644)
	// 50-cloud-init.conf sorts before 50-falak.conf: its value wins.
	put(t, root, SSHDDir+"/50-cloud-init.conf", "PasswordAuthentication yes\n", 0o600)
	put(t, root, SSHDDropIn, "# Managed by Falak\nPasswordAuthentication no\n", 0o644)
	cs := s.sshChecks(context.Background(), AuditPayload{})
	for id, want := range map[string]string{"ssh.permit_root_login": Fail, "ssh.password_authentication": Fail, "ssh.kbd_interactive": Fail, "ssh.permit_empty_passwords": Pass} {
		c := byID(t, cs, id)
		if c.Status != want {
			t.Errorf("%s = %s (%s), want %s", id, c.Status, c.Evidence, want)
		}
		if (c.Status == Fail) != (c.FixID == "ssh.harden") {
			t.Errorf("%s fix %q", id, c.FixID)
		}
	}
	if c := byID(t, cs, "ssh.password_authentication"); !strings.Contains(c.Evidence, "config files") {
		t.Errorf("evidence %q", c.Evidence)
	}
	if byID(t, cs, "ssh.authorized_keys").Status != Info {
		t.Error("keys are compared without managed_keys")
	}
}

func TestSSHChecksUseSSHDT(t *testing.T) {
	s, f, _ := newSec(t)
	onOut(f, "sshd -T", "port 22\npermitrootlogin without-password\npasswordauthentication no\npermitemptypasswords no\nkbdinteractiveauthentication no\n")
	for _, c := range s.sshChecks(context.Background(), AuditPayload{}) {
		if c.Area == "ssh" && c.ID != "ssh.authorized_keys" && (c.Status != Pass || c.FixID != "") {
			t.Errorf("%+v", c)
		}
	}
}

func TestUnknownAuthorizedKeys(t *testing.T) {
	s, f, root := newSec(t)
	catchAll(f)
	passwd(root, t)
	put(t, root, "/root/.ssh/authorized_keys", keyA+"\n# a comment\n"+`command="x" `+keyB+"\n", 0o600)
	put(t, root, "/home/ubuntu/.ssh/authorized_keys2", keyC+"\n", 0o600)
	// A symlink in place of the file is never followed.
	put(t, root, "/etc/secret-keys", keyB+"\n", 0o600)
	if err := os.MkdirAll(filepath.Join(root, "/home/falak/.ssh"), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(root, "/etc/secret-keys"), filepath.Join(root, "/home/falak/.ssh/authorized_keys")); err != nil {
		t.Fatal(err)
	}
	c := byID(t, s.sshChecks(context.Background(), AuditPayload{ManagedKeys: map[string][]string{"root": {keyA}, "falak": {}}}), "ssh.authorized_keys")
	if c.Status != Warn || !strings.Contains(c.Evidence, "root: 1 unknown key ("+Fingerprint(KeyBlob(keyB))+")") || !strings.Contains(c.Evidence, "ubuntu: 1 unknown key") || strings.Contains(c.Evidence, "falak") {
		t.Fatalf("%+v", c)
	}
	if strings.Contains(c.Evidence, "AAAA") {
		t.Errorf("evidence carries key material: %s", c.Evidence)
	}
	c = byID(t, s.sshChecks(context.Background(), AuditPayload{ManagedKeys: map[string][]string{"root": {keyA, keyB}, "ubuntu": {keyC}}}), "ssh.authorized_keys")
	if c.Status != Pass || c.Evidence != "3 authorized keys, all managed by Falak" {
		t.Fatalf("%+v", c)
	}
}

const aptOut = `NOTE: This is only a simulation!
Reading package lists...
Inst libssl3 [3.0.2-0ubuntu1.10] (3.0.2-0ubuntu1.12 Ubuntu:22.04/jammy-updates, Ubuntu:22.04/jammy-security [amd64])
Inst openssl [3.0.2-0ubuntu1.10] (3.0.2-0ubuntu1.12 Ubuntu:22.04/jammy-updates, Ubuntu:22.04/jammy-security [amd64]) []
Inst docker-ce [5:28.0.0-1~ubuntu.22.04~jammy] (5:28.1.0-1~ubuntu.22.04~jammy Docker CE:jammy [amd64])
Inst tzdata [2024a-0ubuntu0.22.04] (2024b-0ubuntu0.22.04 Debian-Security:12/stable-security [all])
Conf libssl3 (3.0.2-0ubuntu1.12 Ubuntu:22.04/jammy-security [amd64])
`

func TestParseSecurityUpgrades(t *testing.T) {
	if got := strings.Join(ParseSecurityUpgrades(aptOut), ","); got != "libssl3,openssl,tzdata" {
		t.Fatal(got)
	}
	if len(ParseSecurityUpgrades("Reading package lists...\n0 upgraded, 0 newly installed\n")) != 0 {
		t.Fatal("no upgrades")
	}
}

func TestUpdateChecks(t *testing.T) {
	s, f, root := newSec(t)
	onOut(f, "apt-get -s", aptOut)
	onOut(f, "dpkg-query -W -f=${db:Status-Status} unattended-upgrades", "installed")
	catchAll(f)
	put(t, root, AutoUpgrades, "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"0\";\n", 0o644)
	put(t, root, RebootRequired, "*** System restart required ***\n", 0o644)
	put(t, root, rebootPkgs, "linux-image-6.8\nlinux-base\nlinux-base\n", 0o644)
	cs := s.updateChecks(context.Background(), AuditPayload{})
	if c := byID(t, cs, "updates.security"); c.Status != Fail || c.FixID != "updates.install" || !strings.Contains(c.Evidence, "3 security updates pending: libssl3, openssl, tzdata") {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "updates.unattended"); c.Status != Fail || c.FixID != "updates.unattended" {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "updates.reboot"); c.Status != Warn || c.FixID != "updates.reboot" || c.Evidence != "a reboot is required by linux-base, linux-image-6.8" {
		t.Errorf("%+v", c)
	}
	// apt unavailable: info, never a failure.
	s2, f2, _ := newSec(t)
	catchAll(f2)
	if c := byID(t, s2.updateChecks(context.Background(), AuditPayload{}), "updates.security"); c.Status != Info {
		t.Errorf("%+v", c)
	}
}

const ssTCP = `LISTEN 0 4096 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=1,fd=3))
LISTEN 0 4096 [::]:22 [::]:* users:(("sshd",pid=1,fd=4))
LISTEN 0 4096 *:443 *:* users:(("caddy",pid=2,fd=7))
LISTEN 0 4096 127.0.0.1:5432 0.0.0.0:* users:(("postgres",pid=3,fd=5))
LISTEN 0 511 0.0.0.0:8080 0.0.0.0:* users:(("node",pid=4,fd=20))
LISTEN 0 4096 0.0.0.0:9000 0.0.0.0:* users:(("docker-proxy",pid=5,fd=4))
LISTEN 0 80 10.90.0.2:3306 0.0.0.0:* users:(("mariadbd",pid=6,fd=21))
LISTEN 0 80 203.0.113.7:6379 0.0.0.0:* users:(("redis-server",pid=7,fd=6))
`
const ssUDP = `UNCONN 0 0 0.0.0.0:68 0.0.0.0:* users:(("dhclient",pid=8,fd=6))
UNCONN 0 0 127.0.0.53%lo:53 0.0.0.0:* users:(("systemd-resolve",pid=9,fd=13))
UNCONN 0 0 0.0.0.0:51820 0.0.0.0:*
`
const nftDrop = `table inet falak {
	chain input {
		type filter hook input priority filter; policy drop;
		ct state established,related accept
		tcp dport { 80, 443 } accept comment "web"
	}
}
`

func TestFirewallChecks(t *testing.T) {
	s, f, _ := newSec(t)
	onOut(f, "nft list table inet falak", nftDrop)
	onOut(f, "ss -H -ltnp", ssTCP)
	onOut(f, "ss -H -lunp", ssUDP)
	p := AuditPayload{ExpectedPorts: []string{"tcp/80", "tcp/443", "udp/51820"}, SSHPort: 22}
	cs := s.firewallChecks(context.Background(), p)
	if c := byID(t, cs, "firewall.default_deny"); c.Status != Pass {
		t.Errorf("%+v", c)
	}
	var ports []string
	for _, c := range cs {
		if strings.HasPrefix(c.ID, "firewall.port.") {
			ports = append(ports, c.ID)
			if c.Status != Warn || c.Severity != Low {
				t.Errorf("behind default deny: %+v", c)
			}
		}
	}
	if strings.Join(ports, ",") != "firewall.port.tcp.6379,firewall.port.tcp.8080" {
		t.Fatalf("ports %v", ports)
	}
	if c := byID(t, cs, "firewall.port.tcp.8080"); c.FixID != "firewall.close_port:tcp:8080" || !strings.HasPrefix(c.Evidence, "node ") {
		t.Errorf("%+v", c)
	}
	if hasID(cs, "firewall.ports") {
		t.Error("pass check alongside findings")
	}

	// Without a default deny the same ports are reachable: high failures, and the firewall fix.
	s, f, _ = newSec(t)
	onOut(f, "nft list table inet falak", strings.Replace(nftDrop, "policy drop", "policy accept", 1))
	onOut(f, "ss -H -ltnp", ssTCP)
	cs = s.firewallChecks(context.Background(), p)
	if c := byID(t, cs, "firewall.default_deny"); c.Status != Fail || c.FixID != "firewall.apply" || !strings.Contains(c.Evidence, "policy accept") {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "firewall.port.tcp.8080"); c.Status != Fail || c.Severity != High {
		t.Errorf("%+v", c)
	}

	s, f, _ = newSec(t)
	catchAll(f)
	cs = s.firewallChecks(context.Background(), AuditPayload{})
	if c := byID(t, cs, "firewall.default_deny"); c.Status != Fail || c.Evidence != "table inet falak is not loaded" {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "firewall.ports"); c.Status != Info {
		t.Errorf("not compared: %+v", c)
	}
}

func TestPublicAddresses(t *testing.T) {
	for addr, want := range map[string]bool{"0.0.0.0": true, "::": true, "*": true, "203.0.113.7": true, "2001:db8::1": true,
		"127.0.0.1": false, "::1": false, "10.0.0.1": false, "192.168.1.2": false, "172.17.0.1": false, "fd00::1": false, "100.100.1.1": false, "fe80::1": false} {
		if Public(addr) != want {
			t.Errorf("Public(%s) != %v", addr, want)
		}
	}
	rs := ParseExpected([]string{"tcp/8000-8100", "any/53", "udp/*", "tcp/x", "tcp/9-1"})
	if len(rs) != 3 || !expected(rs, "tcp", 8050) || !expected(rs, "tcp", 53) || !expected(rs, "udp", 1) || expected(rs, "tcp", 8101) {
		t.Fatalf("%+v", rs)
	}
}

const inspectJSON = `[
 {"Name": "/shop-app", "HostConfig": {"Privileged": false}, "Mounts": [{"Source": "/srv/falak/sites/shop", "Destination": "/app"}],
  "NetworkSettings": {"Ports": {"8080/tcp": [{"HostIp": "127.0.0.1", "HostPort": "3001"}]}}},
 {"Name": "/portainer", "HostConfig": {"Privileged": true}, "Mounts": [{"Source": "/var/run/docker.sock", "Destination": "/var/run/docker.sock"}],
  "NetworkSettings": {"Ports": {"9000/tcp": [{"HostIp": "0.0.0.0", "HostPort": "9000"}, {"HostIp": "::", "HostPort": "9000"}]}}},
 {"Name": "/edge", "HostConfig": {"Privileged": false}, "Mounts": [],
  "NetworkSettings": {"Ports": {"443/tcp": [{"HostIp": "0.0.0.0", "HostPort": "443"}], "5432/tcp": null}}}
]`

func TestDockerChecks(t *testing.T) {
	s, f, root := newSec(t)
	put(t, root, "/usr/bin/docker", "", 0o755)
	put(t, root, DaemonJSON, `{"live-restore": true, "hosts": ["unix:///var/run/docker.sock", "tcp://0.0.0.0:2375"]}`, 0o644)
	put(t, root, "/etc/group", "root:x:0:\ndocker:x:999:ubuntu,root\n", 0o644)
	onOut(f, "docker ps -q --no-trunc", "aaa\nbbb\nccc\n")
	onOut(f, "docker inspect aaa bbb ccc", inspectJSON)
	catchAll(f)
	cs := s.dockerChecks(context.Background(), AuditPayload{ExpectedPorts: []string{"tcp/80", "tcp/443"}})
	want := map[string]string{"docker.tcp": Fail, "docker.privileged": Fail, "docker.socket_mounts": Fail, "firewall.docker_published": Fail, "docker.group": Fail}
	for id, st := range want {
		if c := byID(t, cs, id); c.Status != st {
			t.Errorf("%s: %+v", id, c)
		}
	}
	if c := byID(t, cs, "docker.tcp"); c.FixID != "docker.tcp_off" || c.Severity != Critical {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "firewall.docker_published"); c.Evidence != "published on all interfaces (not filtered by the firewall): portainer (tcp/9000); publish them on 127.0.0.1" {
		t.Errorf("%q", c.Evidence)
	}
	if c := byID(t, cs, "docker.group"); c.Evidence != "the docker group (root-equivalent) has ubuntu" {
		t.Errorf("%q", c.Evidence)
	}
	// TLS on: not a finding.
	put(t, root, DaemonJSON, `{"hosts": ["tcp://0.0.0.0:2376"], "tlsverify": true}`, 0o644)
	if c := byID(t, s.dockerChecks(context.Background(), AuditPayload{}), "docker.tcp"); c.Status != Pass {
		t.Errorf("%+v", c)
	}
	// No Docker at all.
	s2, f2, _ := newSec(t)
	catchAll(f2)
	if cs := s2.dockerChecks(context.Background(), AuditPayload{}); len(cs) != 1 || cs[0].Status != Info {
		t.Errorf("%+v", cs)
	}
}

func TestFileChecks(t *testing.T) {
	s, _, root := newSec(t)
	s.d.RootUID = os.Getuid() // Falak's own directories belong to "root"
	put(t, root, "/srv/falak/sites/shop/current/.env", "APP_KEY=secret\n", 0o644)
	put(t, root, "/srv/falak/sites/shop/current/.env.example", "APP_KEY=\n", 0o644)
	put(t, root, "/srv/falak/sites/blog/shared/.env", "APP_KEY=secret\n", 0o640)
	put(t, root, "/srv/falak/sites/blog/shared/node_modules/x/.env", "X=1\n", 0o644) // skipped
	put(t, root, "/run/falak/env/shop.env", "pw", 0o604)
	put(t, root, "/run/falak/env/blog.env", "A=1", 0o600)
	// Container secrets are world-readable inside a private directory, by design.
	put(t, root, "/run/falak/secrets/shop-app/db_password", "pw", 0o444)
	_ = os.Chmod(filepath.Join(root, "/run/falak/secrets/shop-app"), 0o555)
	t.Cleanup(func() { _ = os.Chmod(filepath.Join(root, "/run/falak/secrets/shop-app"), 0o755) })
	_ = os.Chmod(filepath.Join(root, "/run/falak/secrets"), 0o700)
	put(t, root, "/srv/falak/sites/shop/current/public/up.php", "<?php", 0o666)
	if err := os.MkdirAll(filepath.Join(root, "/srv/falak/sites/shop/tmp"), 0o777); err != nil {
		t.Fatal(err)
	}
	_ = os.Chmod(filepath.Join(root, "/srv/falak/sites/shop/tmp"), 0o777|os.ModeSticky) // sticky: fine
	put(t, root, "/etc/shadow", "root:*:", 0o644)
	if err := os.Symlink(filepath.Join(root, "/etc/shadow"), filepath.Join(root, "/srv/falak/sites/blog/.env")); err != nil {
		t.Fatal(err)
	}
	put(t, root, "/tmp/xmrig", "\x7fELF", 0o755)
	put(t, root, "/tmp/notes.txt", "", 0o644)
	cs := s.fileChecks(context.Background(), AuditPayload{})
	c := byID(t, cs, "files.secret_permissions")
	if c.Status != Fail || c.FixID != "files.secret_permissions" || c.Evidence != "2 secret files are readable by other users: /run/falak/env/shop.env, /srv/falak/sites/shop/current/.env" {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "files.container_secrets"); c.Status != Pass {
		t.Errorf("%+v", c)
	}
	_ = os.Chmod(filepath.Join(root, "/run/falak/secrets"), 0o755)
	_ = os.Chmod(filepath.Join(root, "/run/falak/secrets/shop-app"), 0o777)
	if c := s.containerSecretsCheck(); c.Status != Fail || !strings.Contains(c.Evidence, "/run/falak/secrets (-rwxr-xr-x") || !strings.Contains(c.Evidence, "shop-app") {
		t.Errorf("%+v", c)
	}
	_ = os.Chmod(filepath.Join(root, "/run/falak/secrets"), 0o700)
	_ = os.Chmod(filepath.Join(root, "/run/falak/secrets/shop-app"), 0o555)
	if c := byID(t, cs, "files.world_writable"); c.Status != Warn || c.Evidence != "1 world-writable path: /srv/falak/sites/shop/current/public/up.php" {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "files.tmp_executables"); c.Status != Warn || !strings.Contains(c.Evidence, "/tmp/xmrig") {
		t.Errorf("%+v", c)
	}
}

func TestWalkIsBounded(t *testing.T) {
	s, _, root := newSec(t)
	for i := 0; i < 30; i++ {
		put(t, root, filepath.Join("/srv/falak/sites/s", strings.Repeat("d/", i%10), "f"+string(rune('a'+i))), "", 0o644)
	}
	n := 0
	cut := s.walk(context.Background(), "/srv/falak/sites", 3, 10, nil, func(string, os.DirEntry) { n++ })
	if !cut || n == 0 || n > 10 {
		t.Fatalf("cut=%v n=%d", cut, n)
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	n = 0
	if cut := s.walk(ctx, "/srv/falak/sites", 10, 1000, nil, func(string, os.DirEntry) { n++ }); !cut || n != 0 {
		t.Fatalf("cancelled walk: cut=%v n=%d", cut, n)
	}
}

func sysctls(t *testing.T, root string, values map[string]string) {
	for _, k := range Sysctls {
		v := k.Want
		if o, ok := values[k.Key]; ok {
			v = o
		}
		if v == "-" {
			continue
		}
		put(t, root, procPath(k.Key), v+"\n", 0o644)
	}
}

func TestKernelChecks(t *testing.T) {
	s, _, root := newSec(t)
	sysctls(t, root, map[string]string{"net.ipv4.conf.all.rp_filter": "0", "kernel.kptr_restrict": "2", "net.ipv4.tcp_syncookies": "0", "net.ipv6.conf.all.accept_redirects": "-"})
	cs := s.kernelChecks(context.Background(), AuditPayload{})
	if hasID(cs, "kernel.net.ipv6.conf.all.accept_redirects") {
		t.Error("a setting the kernel lacks is reported")
	}
	if c := byID(t, cs, "kernel.net.ipv4.conf.all.rp_filter"); c.Status != Fail || c.FixID != "kernel.sysctl" || c.Evidence != "net.ipv4.conf.all.rp_filter = 0 (want 2)" {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "kernel.kernel.kptr_restrict"); c.Status != Pass {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "kernel.net.ipv4.tcp_syncookies"); c.Status != Fail {
		t.Errorf("%+v", c)
	}
}

func TestAccountChecks(t *testing.T) {
	s, _, root := newSec(t)
	put(t, root, "/etc/passwd", "root:x:0:0::/root:/bin/bash\ntoor:x:0:0::/root:/bin/sh\nubuntu:x:1000:1000::/home/ubuntu:/bin/bash\nshop:x:1001:1001::/srv/falak/sites/shop:/bin/bash\nsvc:x:1002:1002::/:/usr/sbin/nologin\n", 0o644)
	put(t, root, "/etc/sudoers", "root ALL=(ALL:ALL) ALL\n# %wheel ALL=(ALL) NOPASSWD: ALL\n", 0o440)
	put(t, root, "/etc/sudoers.d/90-cloud-init-users", "ubuntu ALL=(ALL) NOPASSWD:ALL\n", 0o440)
	put(t, root, "/etc/sudoers.d/falak-falak", "# Managed by Falak\nfalak ALL=(ALL:ALL) NOPASSWD:ALL\n", 0o440)
	put(t, root, "/etc/sudoers.d/README", "# nothing\n", 0o440)
	put(t, root, "/etc/sudoers.d/old.bak", "x ALL=(ALL) NOPASSWD:ALL\n", 0o440) // sudo skips names with a dot
	cs := s.accountChecks(context.Background(), AuditPayload{KnownUsers: []string{"shop", "falak"}})
	if c := byID(t, cs, "accounts.uid0"); c.Status != Fail || c.Severity != Critical || c.Evidence != "UID 0 accounts besides root: toor" {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "accounts.sudo_nopasswd"); c.Status != Warn || c.Evidence != "NOPASSWD rules in /etc/sudoers.d/90-cloud-init-users" {
		t.Errorf("%+v", c)
	}
	if c := byID(t, cs, "accounts.unknown_users"); c.Status != Info || c.Evidence != "not managed by Falak: ubuntu" {
		t.Errorf("%+v", c)
	}
}

func TestTimeAndFail2banChecks(t *testing.T) {
	for out, want := range map[string]string{"NTP=yes\nNTPSynchronized=yes\n": Pass, "NTP=no\nNTPSynchronized=no\n": Fail, "NTP=yes\nNTPSynchronized=no\n": Warn} {
		s, f, _ := newSec(t)
		onOut(f, "timedatectl show", out)
		if c := s.timeChecks(context.Background(), AuditPayload{})[0]; c.Status != want || (want == Fail) != (c.FixID == "time.sync") {
			t.Errorf("%q: %+v", out, c)
		}
	}
	s, f, _ := newSec(t)
	f.On("systemctl is-active --quiet fail2ban.service", ok(""))
	f.On("fail2ban-client status sshd", fail(255))
	if c := s.fail2banChecks(context.Background(), AuditPayload{})[0]; c.Status != Fail || c.Evidence != "fail2ban runs without an sshd jail" || c.FixID != "fail2ban.sshd" {
		t.Errorf("%+v", c)
	}
	s, f, _ = newSec(t)
	f.On("systemctl is-active --quiet fail2ban.service", ok(""))
	if c := s.fail2banChecks(context.Background(), AuditPayload{})[0]; c.Status != Pass {
		t.Errorf("%+v", c)
	}
}

// A whole audit on a bare host: every check is well-formed and disruptive comes from the allowlist.
func TestAuditOnABareHost(t *testing.T) {
	s, f, root := newSec(t)
	catchAll(f)
	passwd(root, t)
	sysctls(t, root, map[string]string{"kernel.dmesg_restrict": "0"})
	res, err := s.Audit(context.Background(), AuditPayload{}, &stream{})
	if err != nil {
		t.Fatal(err)
	}
	r := res.(AuditResult)
	areas := map[string]bool{}
	for _, c := range r.Checks {
		areas[c.Area] = true
		if c.ID == "" || c.Title == "" || c.Severity == "" || !map[string]bool{Pass: true, Warn: true, Fail: true, Info: true}[c.Status] {
			t.Errorf("malformed %+v", c)
		}
		if fix, ok := fixFor(c.FixID); c.FixID != "" && (!ok || fix.Disruptive != c.Disruptive) {
			t.Errorf("fix %+v", c)
		}
	}
	for _, a := range []string{"ssh", "updates", "firewall", "intrusion", "docker", "files", "kernel", "accounts", "time"} {
		if !areas[a] {
			t.Errorf("no %s checks", a)
		}
	}
	if c := byID(t, r.Checks, "ssh.password_authentication"); !c.Disruptive {
		t.Errorf("ssh.harden is disruptive: %+v", c)
	}
}

// The audit stops at its budget instead of running past it.
func TestAuditStopsAtItsBudget(t *testing.T) {
	s, f, _ := newSec(t)
	f.OnFunc("", func(c runnertest.Call) (runner.Result, error) { return fail(1), nil })
	ctx, cancel := context.WithDeadline(context.Background(), time.Now().Add(-time.Second))
	defer cancel()
	res, err := s.Audit(ctx, AuditPayload{}, &stream{})
	if err != nil {
		t.Fatal(err)
	}
	if cs := res.(AuditResult).Checks; len(cs) != 1 || cs[0].ID != "audit.incomplete" {
		t.Fatalf("%+v", cs)
	}
}
