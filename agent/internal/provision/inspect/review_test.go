package inspect

import (
	"context"
	"os"
	"strings"
	"syscall"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

// The checked path is the one executed: a symlink is resolved once and its target runs, so swapping the link after
// the check cannot redirect the exec.
func TestRunExecutesTheResolvedPath(t *testing.T) {
	fs := writeFiles(t, map[string]string{"/opt/php/bin/php": "", "/usr/lib/snapd/snap": ""})
	os.MkdirAll(fs.P("/usr/local/bin"), 0o755)
	os.Symlink("../../../opt/php/bin/php", fs.P("/usr/local/bin/php")) // relative: the test filesystem is not /
	os.MkdirAll(fs.P("/snap/bin"), 0o755)
	os.Symlink("../../usr/lib/snapd/snap", fs.P("/snap/bin/docker"))
	f := (&runnertest.Fake{}).On("/opt/php/bin/php", runner.Result{Stdout: []byte("8.3")})
	in := New(Deps{Runner: f, FS: fs, OwnerUID: os.Getuid()})

	if out, err := in.output(context.Background(), "/usr/local/bin/php", "-v"); err != nil || out != "8.3" {
		t.Fatalf("%q %v", out, err)
	}
	if l := f.Lines(); len(l) != 1 || l[0] != "/opt/php/bin/php -v" {
		t.Fatalf("%v", l)
	}
	// A multi-call binary would lose its name when run by its target: refused.
	if _, err := in.run(context.Background(), "/snap/bin/docker", "version"); err == nil || !strings.Contains(err.Error(), "is a link to /usr/lib/snapd/snap") {
		t.Fatalf("%v", err)
	}
}

func TestCountKeysTrustsNothingInAHome(t *testing.T) {
	fs := writeFiles(t, map[string]string{
		"/etc/shadow":                       "root:$6$hash:19000:0:99999:7:::\nubuntu:$6$hash:19000:0:99999:7:::\n",
		"/home/eve/.ssh/authorized_keys":    "ssh-ed25519 AAAAC3Nza eve\n",
		"/home/ok/.ssh/authorized_keys":     "# comment\nno-pty,command=\"/bin/true\" ssh-rsa AAAAB3Nza backup\nnot a key at all\nssh-ed25519 notbase64\necdsa-sha2-nistp256 AAAAE2Vj ops\nsk-ssh-ed25519@openssh.com AAAAGnNr yubikey\n",
		"/home/big/.ssh/authorized_keys":    strings.Repeat("x", maxKeysFile) + "\nssh-ed25519 AAAAC3Nza late\n",
		"/home/linked/.ssh/placeholder":     "",
		"/home/dirlink/.ssh-real/ak":        "ssh-ed25519 AAAAC3Nza hidden\n",
		"/home/fifo/.ssh/placeholder":       "",
		"/home/eve/.ssh/authorized_keys2.x": "",
	})
	os.Symlink("/etc/shadow", fs.P("/home/linked/.ssh/authorized_keys"))
	os.Symlink(fs.P("/home/dirlink/.ssh-real"), fs.P("/home/dirlink/.ssh"))
	os.Rename(fs.P("/home/dirlink/.ssh-real/ak"), fs.P("/home/dirlink/.ssh-real/authorized_keys"))
	if err := syscall.Mkfifo(fs.P("/home/fifo/.ssh/authorized_keys"), 0o644); err != nil {
		t.Fatal(err)
	}
	// OwnerUID is not the test's uid: the symlinks the test created count as a user's.
	in := New(Deps{FS: fs, OwnerUID: os.Getuid() + 1})

	for path, want := range map[string]int{
		"/home/eve/.ssh/authorized_keys":     1,
		"/home/ok/.ssh/authorized_keys":      3, // options + rsa, ecdsa, sk; comments, text and a bad blob do not count
		"/home/big/.ssh/authorized_keys":     0, // only the first MiB is read
		"/home/linked/.ssh/authorized_keys":  0, // symlink to /etc/shadow: not followed
		"/home/dirlink/.ssh/authorized_keys": 0, // user-owned directory symlink: not followed
		"/home/fifo/.ssh/authorized_keys":    0, // FIFO: not a regular file, and opening it does not block
		"/home/none/.ssh/authorized_keys":    0,
	} {
		if got := in.countKeys(path); got != want {
			t.Errorf("%s: %d keys, want %d", path, got, want)
		}
	}
}
