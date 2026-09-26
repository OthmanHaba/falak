package provision

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

// hostSim is a fake host: tracks installed packages and systemd state.
type hostSim struct {
	pkgs    map[string]bool
	enabled map[string]bool
	active  map[string]bool
	users   map[string]bool
}

func newHost(f *runnertest.Fake, root string) *hostSim {
	h := &hostSim{pkgs: map[string]bool{}, enabled: map[string]bool{}, active: map[string]bool{}, users: map[string]bool{}}
	f.OnFunc("dpkg-query", func(c runnertest.Call) (runner.Result, error) {
		var b strings.Builder
		for _, a := range c.Args[2:] {
			if h.pkgs[a] {
				b.WriteString(a + "\tinstalled\t1\n")
			}
		}
		return runner.Result{ExitCode: 1, Stdout: []byte(b.String())}, nil
	})
	f.OnFunc("apt-get install", func(c runnertest.Call) (runner.Result, error) {
		for _, a := range c.Args {
			h.pkgs[strings.SplitN(a, "=", 2)[0]] = true
		}
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl is-enabled", func(c runnertest.Call) (runner.Result, error) {
		if h.enabled[c.Args[2]] {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 1}, nil
	})
	f.OnFunc("systemctl is-active", func(c runnertest.Call) (runner.Result, error) {
		if h.active[c.Args[2]] {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl enable", func(c runnertest.Call) (runner.Result, error) {
		n := c.Args[len(c.Args)-1]
		h.enabled[n] = true
		if c.Args[1] == "--now" {
			h.active[n] = true
		}
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl start", func(c runnertest.Call) (runner.Result, error) {
		h.active[c.Args[1]] = true
		return runner.Result{}, nil
	})
	f.OnFunc("systemctl restart", func(c runnertest.Call) (runner.Result, error) {
		h.active[c.Args[1]] = true
		return runner.Result{}, nil
	})
	f.OnFunc("getent passwd", func(c runnertest.Call) (runner.Result, error) {
		if h.users[c.Args[1]] {
			shell := "/bin/bash"
			if c.Args[1] == "caddy" {
				shell = "/usr/sbin/nologin"
			}
			return runner.Result{Stdout: []byte(c.Args[1] + ":x:1001:1001::/home/" + c.Args[1] + ":" + shell + "\n")}, nil
		}
		return runner.Result{ExitCode: 2}, nil
	})
	f.OnFunc("useradd", func(c runnertest.Call) (runner.Result, error) {
		h.users[c.Args[len(c.Args)-1]] = true
		return runner.Result{}, nil
	})
	// Default host is a VM; container tests override this.
	f.OnFunc("systemd-detect-virt", func(c runnertest.Call) (runner.Result, error) { return runner.Result{ExitCode: 1}, nil })
	f.OnFunc("hostnamectl set-hostname", func(c runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "etc"), 0o755)
		return runner.Result{}, os.WriteFile(filepath.Join(root, "etc/hostname"), []byte(c.Args[1]+"\n"), 0o644)
	})
	f.OnFunc("timedatectl set-timezone", func(c runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "etc"), 0o755)
		return runner.Result{}, os.Symlink("/usr/share/zoneinfo/"+c.Args[1], filepath.Join(root, "etc/localtime"))
	})
	f.OnFunc("fallocate", func(c runnertest.Call) (runner.Result, error) {
		fh, _ := os.Create(filepath.Join(root, "swapfile"))
		fh.Truncate(1 << 20)
		return runner.Result{}, fh.Close()
	})
	f.OnFunc("swapon", func(c runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "proc"), 0o755)
		return runner.Result{}, os.WriteFile(filepath.Join(root, "proc/swaps"), []byte("Filename Type\n/swapfile file 1024 0 -2\n"), 0o644)
	})
	return h
}

const planJSON = `{
  "hostname": "web-1", "timezone": "UTC", "swap_mb": 1,
  "apt": {"packages": ["git", "curl"], "remove": ["snapd"]},
  "users": [{"name": "shop", "groups": []}],
  "runtimes": {"caddy": {"enabled": true}},
  "services": [{"name": "cron", "enabled": true, "state": "started"}],
  "unattended_upgrades": {"enabled": true, "auto_reboot": true, "reboot_time": "03:30"},
  "ssh": {"port": 22, "permit_root_login": "prohibit-password", "password_authentication": false}
}`

func TestApplyConvergesAndIsIdempotent(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	h := newHost(f, root)
	h.pkgs["snapd"] = true
	f.OnFunc("apt-get remove", func(c runnertest.Call) (runner.Result, error) { delete(h.pkgs, "snapd"); return runner.Result{}, nil })
	h.users["caddy"] = true
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("-----BEGIN PGP PUBLIC KEY BLOCK-----\n"))
	}))
	defer srv.Close()
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), Arch: "amd64", CaddyKeyURL: srv.URL + "/gpg.key"})
	plan, err := commands.Decode[Plan](json.RawMessage(planJSON))
	if err != nil {
		t.Fatal(err)
	}
	col := &commands.Collector{}
	st := commands.NewTestStream("c", col)
	r, err := p.Apply(context.Background(), plan, st)
	if err != nil {
		t.Fatal(err, r)
	}
	res := r.(Result)
	if !res.Changed {
		t.Fatal("expected changes")
	}
	var names []string
	for _, s := range res.Steps {
		names = append(names, s.Name)
		if s.Error != "" {
			t.Fatal(s)
		}
	}
	if strings.Join(names, ",") != "hostname,timezone,swap,apt,user:shop,caddy,service:cron,unattended_upgrades,ssh" {
		t.Fatal(names)
	}
	for _, w := range []string{"hostnamectl set-hostname web-1", "timedatectl set-timezone UTC", "mkswap /swapfile", "swapon /swapfile",
		"apt-get remove -y", "useradd", "systemctl enable cron", "systemctl start cron", "sshd -t", "systemctl reload ssh", "systemctl restart kiln-edge.service"} {
		if !f.Ran(w) {
			t.Fatalf("missing %q:\n%s", w, strings.Join(f.Lines(), "\n"))
		}
	}
	if !h.pkgs["caddy"] || !h.pkgs["unattended-upgrades"] || !h.pkgs["git"] {
		t.Fatal(h.pkgs)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/apt/apt.conf.d/52kiln-unattended"))
	if !strings.Contains(string(b), `Automatic-Reboot "true"`) || !strings.Contains(string(b), `"03:30"`) {
		t.Fatal(string(b))
	}
	b, _ = os.ReadFile(filepath.Join(root, "etc/ssh/sshd_config.d/50-kiln.conf"))
	if !strings.Contains(string(b), "PasswordAuthentication no") {
		t.Fatal(string(b))
	}
	b, _ = os.ReadFile(filepath.Join(root, "etc/fstab"))
	if string(b) != "/swapfile none swap sw 0 0\n" {
		t.Fatal(string(b))
	}
	var progress int
	for _, e := range col.Snapshot() {
		if e.Kind == commands.KindProgress {
			progress++
		}
	}
	if progress != 9 {
		t.Fatal("progress events", progress)
	}

	// Second run: nothing to do, no mutating commands.
	f.Reset()
	r, err = p.Apply(context.Background(), plan, st)
	if err != nil || r.(Result).Changed {
		t.Fatalf("not idempotent: %+v %v\n%s", r, err, strings.Join(f.Lines(), "\n"))
	}
	for _, l := range f.Lines() {
		for _, bad := range []string{"apt-get", "useradd", "hostnamectl", "timedatectl", "mkswap", "swapon", "systemctl start", "systemctl restart", "systemctl reload", "sshd"} {
			if strings.HasPrefix(l, bad) {
				t.Fatalf("mutating command on second run: %s", l)
			}
		}
	}
}

func TestFailedStepContinuesAndSSHRestores(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	f.On("hostnamectl", runner.Result{ExitCode: 1, Stderr: []byte("denied")})
	f.On("sshd -t", runner.Result{ExitCode: 255, Stderr: []byte("bad config")})
	newHost(f, root)
	os.MkdirAll(filepath.Join(root, "etc/ssh/sshd_config.d"), 0o755)
	os.WriteFile(filepath.Join(root, "etc/ssh/sshd_config.d/50-kiln.conf"), []byte("Port 22\n"), 0o644)
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	r, err := p.Apply(context.Background(), Plan{Hostname: "x", Timezone: "UTC", SSH: &SSH{Port: 2222}}, commands.NewTestStream("c", &commands.Collector{}))
	if err == nil || !strings.Contains(err.Error(), "hostname, ssh") {
		t.Fatal(err)
	}
	res := r.(Result)
	if len(res.Steps) != 3 || res.Steps[1].Error != "" || !res.Steps[1].Changed {
		t.Fatalf("%+v", res)
	}
	b, _ := os.ReadFile(filepath.Join(root, "etc/ssh/sshd_config.d/50-kiln.conf"))
	if string(b) != "Port 22\n" {
		t.Fatal("not restored", string(b))
	}
	if f.Ran("systemctl reload ssh") {
		t.Fatal("reloaded broken ssh config")
	}
}

func TestContainerHostSkipsHostnameAndSwapButProvisionsTheRest(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	// Rules match in registration order, so these win over newHost's defaults.
	f.OnFunc("systemd-detect-virt", func(c runnertest.Call) (runner.Result, error) { return runner.Result{ExitCode: 0}, nil })
	f.OnFunc("hostnamectl set-hostname", func(c runnertest.Call) (runner.Result, error) {
		return runner.Result{ExitCode: 1}, errors.New("Could not set static hostname: Device or resource busy")
	})
	h := newHost(f, root)
	h.users["caddy"] = true
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("-----BEGIN PGP PUBLIC KEY BLOCK-----\n"))
	}))
	defer srv.Close()
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client(), Arch: "amd64", CaddyKeyURL: srv.URL + "/gpg.key"})
	plan, err := commands.Decode[Plan](json.RawMessage(planJSON))
	if err != nil {
		t.Fatal(err)
	}
	r, err := p.Apply(context.Background(), plan, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err, r)
	}
	for _, s := range r.(Result).Steps {
		if s.Error != "" {
			t.Fatalf("step %s failed in a container: %s", s.Name, s.Error)
		}
	}
	for _, w := range []string{"fallocate", "swapon"} {
		if f.Ran(w) {
			t.Fatalf("%s must not run in a container", w)
		}
	}
	if !f.Ran("sshd -t") || !f.Ran("useradd") {
		t.Fatal("the rest of the plan must still converge")
	}
}

func TestSSHCreatesPrivilegeSeparationDirBeforeValidating(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	f.OnFunc("sshd -t", func(c runnertest.Call) (runner.Result, error) {
		if _, err := os.Stat(filepath.Join(root, "run/sshd")); err != nil {
			return runner.Result{ExitCode: 255}, errors.New("Missing privilege separation directory: /run/sshd")
		}
		return runner.Result{}, nil
	})
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	changed, err := p.ssh(context.Background(), commands.NewTestStream("c", &commands.Collector{}), SSH{Port: 22, PermitRootLogin: "prohibit-password"})
	if err != nil || !changed {
		t.Fatalf("changed=%v err=%v", changed, err)
	}
}
