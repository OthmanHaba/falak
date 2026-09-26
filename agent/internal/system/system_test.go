package system

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

func newSys(t *testing.T, f *runnertest.Fake) (*System, string, commands.Stream, *commands.Collector) {
	root := t.TempDir()
	col := &commands.Collector{}
	return New(Deps{Runner: f, FS: hostfs.FS{Root: root}, AgentVersion: "v1.0.0"}), root, commands.NewTestStream("c1", col), col
}

func TestExecStreamsAndExitCode(t *testing.T) {
	f := (&runnertest.Fake{}).OnFunc("/bin/bash -c", func(c runnertest.Call) (runner.Result, error) {
		if c.Stdin != "in" || c.User != "shop" || c.Dir != "/tmp" {
			t.Errorf("bad call %+v", c)
		}
		return runner.Result{ExitCode: 3, Stdout: []byte("hello\n")}, nil
	})
	s, _, st, col := newSys(t, f)
	in := "in"
	res, err := s.Exec(context.Background(), ExecPayload{Script: "echo hello", User: "shop", Cwd: "/tmp", Stdin: &in, Env: map[string]string{"A": "1"}}, st)
	var ee *commands.ExitError
	if !errors.As(err, &ee) || ee.Code != 3 || res.(ExecResult).ExitCode != 3 {
		t.Fatalf("err=%v res=%v", err, res)
	}
	st.(interface{ Flush() }).Flush()
	if col.Output("stdout") != "hello\n" {
		t.Fatalf("output %q", col.Output(""))
	}
	if got := f.Calls()[0].Env; len(got) != 1 || got[0] != "A=1" {
		t.Fatalf("env %v", got)
	}
}

func TestWriteFileIdempotent(t *testing.T) {
	s, root, st, _ := newSys(t, &runnertest.Fake{})
	p := WriteFilePayload{Path: "/etc/app/x.conf", Content: "aGVsbG8=", Encoding: "base64", Mode: "0600"}
	r1, err := s.WriteFile(context.Background(), p, st)
	if err != nil || !r1.(WriteFileResult).Changed {
		t.Fatal(r1, err)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/app/x.conf"))
	fi, _ := os.Stat(filepath.Join(root, "etc/app/x.conf"))
	if string(b) != "hello" || fi.Mode().Perm() != 0o600 {
		t.Fatal(string(b), fi.Mode())
	}
	r2, _ := s.WriteFile(context.Background(), p, st)
	if r2.(WriteFileResult).Changed {
		t.Fatal("second write changed")
	}
	p.State = "absent"
	r3, _ := s.WriteFile(context.Background(), p, st)
	r4, _ := s.WriteFile(context.Background(), p, st)
	if !r3.(WriteFileResult).Changed || r4.(WriteFileResult).Changed {
		t.Fatal("absent")
	}
}

func dpkgFake(installed map[string]string) *runnertest.Fake {
	return (&runnertest.Fake{}).OnFunc("dpkg-query", func(c runnertest.Call) (runner.Result, error) {
		var out strings.Builder
		for _, a := range c.Args[2:] {
			if v, ok := installed[a]; ok {
				out.WriteString(a + "\tinstalled\t" + v + "\n")
			}
		}
		return runner.Result{ExitCode: 1, Stdout: []byte(out.String())}, nil
	})
}

func TestPackageInstall(t *testing.T) {
	inst := map[string]string{"curl": "8.5"}
	f := dpkgFake(inst)
	f.OnFunc("apt-get install", func(c runnertest.Call) (runner.Result, error) {
		if !contains(c.Env, "DEBIAN_FRONTEND=noninteractive") {
			t.Error("no noninteractive env")
		}
		for _, a := range c.Args {
			if !strings.HasPrefix(a, "-") && a != "install" && !strings.Contains(a, "=") {
				inst[a] = "1"
			}
		}
		return runner.Result{}, nil
	})
	s, _, st, _ := newSys(t, f)
	r, err := s.PackageInstall(context.Background(), PackagePayload{Packages: []string{"curl", "git"}}, st)
	if err != nil {
		t.Fatal(err)
	}
	pr := r.(PackageResult)
	if !pr.Changed || len(pr.Installed) != 1 || pr.Installed[0] != "git" || !f.Ran("apt-get update") {
		t.Fatalf("%+v %v", pr, f.Lines())
	}
	f.Reset()
	r, _ = s.PackageInstall(context.Background(), PackagePayload{Packages: []string{"curl", "git"}}, st)
	if r.(PackageResult).Changed || f.Ran("apt-get") {
		t.Fatalf("not idempotent: %v", f.Lines())
	}
	f.Reset()
	r, _ = s.PackageInstall(context.Background(), PackagePayload{Packages: []string{"git", "nope"}, State: "absent"}, st)
	if rr := r.(PackageResult); !rr.Changed || len(rr.Removed) != 1 || rr.Removed[0] != "git" || !f.Ran("apt-get remove -y") {
		t.Fatalf("%+v %v", rr, f.Lines())
	}
}

func TestPackageLatest(t *testing.T) {
	f := dpkgFake(map[string]string{"nginx": "1.0", "curl": "8.5"})
	f.On("apt-cache policy", runner.Result{Stdout: []byte("nginx:\n  Installed: 1.0\n  Candidate: 1.1\n  Version table:\ncurl:\n  Installed: 8.5\n  Candidate: 8.5\n")})
	s, _, st, _ := newSys(t, f)
	r, err := s.PackageInstall(context.Background(), PackagePayload{Packages: []string{"nginx", "curl"}, State: "latest"}, st)
	if err != nil {
		t.Fatal(err)
	}
	if rr := r.(PackageResult); len(rr.Installed) != 1 || rr.Installed[0] != "nginx" {
		t.Fatalf("%+v", rr)
	}
}

func contains(l []string, s string) bool {
	for _, x := range l {
		if x == s {
			return true
		}
	}
	return false
}

func TestUserCreate(t *testing.T) {
	var exists atomic.Bool
	f := &runnertest.Fake{}
	f.OnFunc("getent passwd shop", func(runnertest.Call) (runner.Result, error) {
		if !exists.Load() {
			return runner.Result{ExitCode: 2}, nil
		}
		return runner.Result{Stdout: []byte("shop:x:1001:1001::/home/shop:/bin/bash\n")}, nil
	})
	f.On("getent group", runner.Result{ExitCode: 0})
	f.OnFunc("useradd", func(runnertest.Call) (runner.Result, error) { exists.Store(true); return runner.Result{}, nil })
	f.On("id -nG shop", runner.Result{Stdout: []byte("shop www-data\n")})
	s, root, st, _ := newSys(t, f)
	os.MkdirAll(filepath.Join(root, "home/shop"), 0o755)
	p := UserSpec{Name: "shop", Groups: []string{"www-data"}, Sudo: "nopasswd", Isolated: true}
	r, err := EnsureUser(context.Background(), f, s.d.FS, p, st)
	if err != nil || !r.Changed || r.UID != 1001 {
		t.Fatal(r, err)
	}
	if !f.Ran("useradd --create-home --user-group --shell /bin/bash --groups www-data shop") || !f.Ran("visudo -cf") {
		t.Fatal(f.Lines())
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/sudoers.d/kiln-shop"))
	if !strings.Contains(string(b), "shop ALL=(ALL:ALL) NOPASSWD:ALL") {
		t.Fatal(string(b))
	}
	if fi, _ := os.Stat(filepath.Join(root, "home/shop")); fi.Mode().Perm() != 0o750 {
		t.Fatal(fi.Mode())
	}
	f.Reset()
	r, err = EnsureUser(context.Background(), f, s.d.FS, p, st)
	if err != nil || r.Changed || f.Ran("useradd") || f.Ran("usermod") || f.Ran("visudo") {
		t.Fatal(r, err, f.Lines())
	}
	// sudo none removes the file
	p.Sudo = "none"
	r, _ = EnsureUser(context.Background(), f, s.d.FS, p, st)
	if !r.Changed || s.d.FS.Exists("/etc/sudoers.d/kiln-shop") {
		t.Fatal("sudoers not removed")
	}
}

func TestSSHKeySync(t *testing.T) {
	f := (&runnertest.Fake{}).On("getent passwd deploy", runner.Result{Stdout: []byte("deploy:x:1000:1000::/home/deploy:/bin/bash\n")})
	s, root, st, _ := newSys(t, f)
	keys := []SSHKey{{ID: "k1", PublicKey: "ssh-ed25519 AAAAC3Nza laptop"}}
	r, err := s.SSHKeySync(context.Background(), SSHKeyPayload{User: "deploy", Keys: keys}, st)
	if err != nil || !r.(SSHKeyResult).Changed || r.(SSHKeyResult).Count != 1 {
		t.Fatal(r, err)
	}
	file := filepath.Join(root, "home/deploy/.ssh/authorized_keys")
	b, _ := os.ReadFile(file)
	if !strings.Contains(string(b), "ssh-ed25519 AAAAC3Nza kiln:k1\n") {
		t.Fatal(string(b))
	}
	if fi, _ := os.Stat(file); fi.Mode().Perm() != 0o600 {
		t.Fatal(fi.Mode())
	}
	r, _ = s.SSHKeySync(context.Background(), SSHKeyPayload{User: "deploy", Keys: keys}, st)
	if r.(SSHKeyResult).Changed {
		t.Fatal("not idempotent")
	}
	// managed block mode preserves foreign keys
	os.WriteFile(file, []byte("ssh-rsa FOREIGN me\n"), 0o600)
	no := false
	s.SSHKeySync(context.Background(), SSHKeyPayload{User: "deploy", Keys: keys, Exclusive: &no}, st)
	s.SSHKeySync(context.Background(), SSHKeyPayload{User: "deploy", Keys: append(keys, SSHKey{ID: "k2", PublicKey: "ssh-ed25519 BBBB"}), Exclusive: &no}, st)
	b, _ = os.ReadFile(file)
	if !strings.HasPrefix(string(b), "ssh-rsa FOREIGN me\n# BEGIN kiln-managed\n") || strings.Count(string(b), blockBegin) != 1 || !strings.Contains(string(b), "kiln:k2") {
		t.Fatal(string(b))
	}
}

func TestUpgradeAgent(t *testing.T) {
	newBin := []byte("#!new-binary")
	sum := sha256.Sum256(newBin)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write(newBin) }))
	defer srv.Close()
	dir := t.TempDir()
	bin := filepath.Join(dir, "kiln-agent")
	os.WriteFile(bin, []byte("old"), 0o755)
	restarted := make(chan struct{}, 1)
	s := New(Deps{Runner: &runnertest.Fake{}, HTTP: srv.Client(), BinaryPath: bin, AgentVersion: "v1.0.0",
		RestartDelay: time.Millisecond, Restart: func() error { restarted <- struct{}{}; return nil }})
	st := commands.NewTestStream("c", &commands.Collector{})
	// sha mismatch is rejected and leaves the binary untouched
	if _, err := s.UpgradeAgent(context.Background(), UpgradePayload{Version: "v1.1.0", URL: srv.URL, SHA256: strings.Repeat("0", 64)}, st); err == nil {
		t.Fatal("expected mismatch")
	}
	if b, _ := os.ReadFile(bin); string(b) != "old" {
		t.Fatal("binary modified")
	}
	p := UpgradePayload{Version: "v1.1.0", URL: srv.URL, SHA256: hex.EncodeToString(sum[:])}
	r, err := s.UpgradeAgent(context.Background(), p, st)
	if err != nil || !r.(UpgradeResult).Changed {
		t.Fatal(r, err)
	}
	if b, _ := os.ReadFile(bin); string(b) != string(newBin) {
		t.Fatal("not replaced")
	}
	if b, _ := os.ReadFile(bin + ".prev"); string(b) != "old" {
		t.Fatal("no backup")
	}
	select {
	case <-restarted:
	case <-time.After(2 * time.Second):
		t.Fatal("no restart")
	}
	r, _ = s.UpgradeAgent(context.Background(), p, st)
	if r.(UpgradeResult).Changed {
		t.Fatal("not idempotent")
	}
	if _, _, err := Download(context.Background(), nil, "http://x", "", filepath.Join(dir, "y"), 0o644, nil); err == nil {
		t.Fatal("http allowed")
	}
}

func TestFactsRegistered(t *testing.T) {
	reg := commands.NewRegistry()
	s, _, st, _ := newSys(t, (&runnertest.Fake{}).On("docker", runner.Result{ExitCode: 1}))
	s.Register(reg)
	ex, _ := reg.Get("system.facts")
	r, err := ex.Execute(context.Background(), commands.Envelope{Payload: []byte(`{}`)}, st)
	if err != nil || r == nil {
		t.Fatal(err)
	}
	if len(reg.Types()) != 7 {
		t.Fatal(reg.Types())
	}
}
