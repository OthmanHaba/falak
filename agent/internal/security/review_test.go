package security

import (
	"context"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// Undo never silently throws away an edit made after the fix: it lists the files and needs force.
func TestUndoRefusesFilesChangedSinceTheFix(t *testing.T) {
	s, _, root := newSec(t)
	sysctls(t, root, map[string]string{"kernel.dmesg_restrict": "0"})
	res := fix(t, s, FixPayload{FixID: "kernel.sysctl"})
	if e := manifest(t, root, res.BackupID).Files[0]; !e.AfterExists || e.AfterSHA256 == "" || e.AfterMode != 0o644 {
		t.Fatalf("not sealed: %+v", e)
	}
	put(t, root, SysctlFile, "# edited by hand\nkernel.dmesg_restrict = 1\n", 0o644)
	_, err := s.Undo(context.Background(), UndoPayload{FixID: "kernel.sysctl", BackupID: res.BackupID}, &stream{})
	if err == nil || !strings.Contains(err.Error(), "changed since the fix: "+SysctlFile) {
		t.Fatalf("%v", err)
	}
	if !fileExists(root, SysctlFile) || len(backups(t, root)) != 1 {
		t.Fatal("undo went ahead")
	}
	if _, err := s.Undo(context.Background(), UndoPayload{FixID: "kernel.sysctl", BackupID: res.BackupID, Force: true}, &stream{}); err != nil {
		t.Fatal(err)
	}
	if fileExists(root, SysctlFile) {
		t.Fatal("forced undo did not restore")
	}
}

func harden(s *Security, p FixPayload) error {
	p.FixID = "ssh.harden"
	_, err := s.Fix(context.Background(), p, &stream{})
	return err
}

// Only a key a person can use for a shell, for a user sshd lets in, keeps the SSH fix from locking everyone out.
func TestSSHFixNeedsAUsableWayIn(t *testing.T) {
	// Root's only key has a forced command; falak's only key is the one Falak installs for itself.
	s, _, root := sshHost(t)
	put(t, root, "/root/.ssh/authorized_keys", `command="/usr/local/bin/backup" `+keyA+"\n", 0o600)
	put(t, root, "/home/falak/.ssh/authorized_keys", keyB+"\n", 0o600)
	before := read(t, root, SSHDDropIn)
	err := harden(s, FixPayload{ManagedKeys: map[string][]string{"falak": {keyB}}})
	if err == nil || !strings.Contains(err.Error(), "of their own") || read(t, root, SSHDDropIn) != before {
		t.Fatalf("%v", err)
	}

	// Root has a usable key, but sshd refuses root logins (its settings for root: sshd -T -C).
	s, _, root = sshHostWith(t, func(f *runnertest.Fake) {
		f.On("sshd -T -C user=root", ok("permitrootlogin no\n"))
		f.On("sshd -T -C user=ubuntu", ok("allowgroups admins\nallowgroups wheel\n"))
	})
	if err := harden(s, FixPayload{}); err == nil || !strings.Contains(err.Error(), "lets none of the users with a key in (root:") {
		t.Fatalf("%v", err)
	}

	// AllowGroups decides too: ubuntu, in the admins group, is let in.
	put(t, root, "/home/ubuntu/.ssh/authorized_keys", keyC+"\n", 0o600)
	put(t, root, "/etc/group", "root:x:0:\nadmins:x:2000:ubuntu\n", 0o644)
	if err := harden(s, FixPayload{}); err != nil {
		t.Fatalf("ubuntu can log in: %v", err)
	}

	// Two-factor logins would be locked out.
	s, _, _ = sshHostWith(t, func(f *runnertest.Fake) {
		f.On("sshd -T", ok("authenticationmethods publickey,keyboard-interactive\npasswordauthentication yes\n"))
	})
	if err := harden(s, FixPayload{}); err == nil || !strings.Contains(err.Error(), "two-factor") {
		t.Fatalf("%v", err)
	}
}

func TestSSHAllows(t *testing.T) {
	for _, c := range []struct {
		cfg    map[string]string
		user   string
		groups []string
		want   bool
	}{
		{map[string]string{}, "root", nil, true},
		{map[string]string{"permitrootlogin": "forced-commands-only"}, "root", nil, false},
		{map[string]string{"allowusers": "deploy@10.0.0.* ops"}, "deploy", nil, true},
		{map[string]string{"allowusers": "ops"}, "deploy", nil, false},
		{map[string]string{"denyusers": "dep*"}, "deploy", nil, false},
		{map[string]string{"denygroups": "nossh"}, "deploy", []string{"deploy", "nossh"}, false},
		{map[string]string{"allowgroups": "sudo"}, "deploy", []string{"deploy"}, false},
		{map[string]string{"pubkeyauthentication": "no"}, "deploy", nil, false},
	} {
		if got := SSHAllows(c.cfg, c.user, c.groups); got != c.want {
			t.Errorf("%v %s %v: %v", c.cfg, c.user, c.groups, got)
		}
	}
	if g := UserGroups("root:x:0:\nshop:x:1001:\nsudo:x:27:shop,ubuntu\n", "shop", "1001"); strings.Join(g, ",") != "shop,sudo" {
		t.Fatal(g)
	}
	if got := ParseSSHDT("allowusers a\nallowusers b\nport 22\nport 2222\n"); got["allowusers"] != "a b" || got["port"] != "22" {
		t.Fatal(got)
	}
}

// After a rollback sshd is restarted on the restored config and checked: the service, or the socket under socket
// activation; an sshd that does not come back is reported.
func TestSSHRollbackRestartsSSH(t *testing.T) {
	s, f, _ := sshHostWith(t, func(f *runnertest.Fake) { f.On("sshd -t", runner.Result{ExitCode: 255}) })
	if err := harden(s, FixPayload{}); err == nil {
		t.Fatal("no error")
	}
	if !f.Ran("systemctl restart ssh.service") || f.Ran("systemctl restart ssh.socket") {
		t.Fatal(f.Lines())
	}

	s, f, _ = sshHostWith(t, func(f *runnertest.Fake) {
		f.On("sshd -t", runner.Result{ExitCode: 255})
		f.On("systemctl is-active --quiet ssh.socket", ok(""))
	})
	if err := harden(s, FixPayload{}); err == nil {
		t.Fatal("no error")
	}
	if !f.Ran("systemctl restart ssh.socket") {
		t.Fatal(f.Lines())
	}

	s, _, _ = sshHostWith(t, func(f *runnertest.Fake) {
		f.On("sshd -t", runner.Result{ExitCode: 255})
		f.On("systemctl is-active --quiet ssh.service", fail(3))
	})
	if err := harden(s, FixPayload{}); err == nil || !strings.Contains(err.Error(), "ssh.service is not active after a restart") {
		t.Fatalf("%v", err)
	}
}

// A failed undo of ssh.harden restarts sshd too.
func TestSSHUndoFailureRestartsSSH(t *testing.T) {
	s, f, _ := sshHost(t)
	res := fix(t, s, FixPayload{FixID: "ssh.harden"})
	f.Reset()
	s2, f2, _ := sshHostWith(t, func(f *runnertest.Fake) { f.On("sshd -t", runner.Result{ExitCode: 255}) })
	s2.d.FS = s.d.FS
	if _, err := s2.Undo(context.Background(), UndoPayload{FixID: "ssh.harden", BackupID: res.BackupID}, &stream{}); err == nil {
		t.Fatal("no error")
	}
	if !f2.Ran("systemctl restart ssh.service") {
		t.Fatal(f2.Lines())
	}
}
