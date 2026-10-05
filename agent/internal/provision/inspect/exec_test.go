package inspect

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func inspectWith(t *testing.T, f *runnertest.Fake, files map[string]string, owner int) *Report {
	t.Helper()
	out, err := New(Deps{Runner: f, FS: writeFiles(t, files), OwnerUID: owner}).Inspect(context.Background(), Payload{}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err)
	}
	return out.(*Report)
}

// A machine where nothing is root-owned (the test's files belong to another uid than OwnerUID): the inspector runs
// nothing at all, and still reports what files and directory names tell.
func TestInspectNeverExecutesFilesRootDoesNotOwn(t *testing.T) {
	f, fs := incidentHost(t)
	out, err := New(Deps{Runner: f, FS: fs, OwnerUID: os.Getuid() + 1}).Inspect(context.Background(), Payload{}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err)
	}
	if lines := f.Lines(); len(lines) != 0 {
		t.Fatalf("executed files root does not own:\n%s", strings.Join(lines, "\n"))
	}
	r := out.(*Report)
	versions := map[string]string{}
	for _, n := range r.Node {
		versions[n.Path] = n.Version
	}
	if versions["/root/.nvm/versions/node/v20.11.0/bin/node"] != "20.11.0" || versions["/usr/local/bin/node"] != "22.20.0" {
		t.Fatalf("versions from directory names: %v", versions)
	}
	for _, e := range r.Errors {
		if strings.Contains(e.Error, "not executed") {
			return
		}
	}
	t.Fatalf("refusals are reported: %+v", r.Errors)
}

func TestInspectRefusesGroupOrWorldWritableBinaries(t *testing.T) {
	f := (&runnertest.Fake{}).OnFunc("", func(c runnertest.Call) (runner.Result, error) { return runner.Result{Stdout: []byte("8.2")}, nil })
	files := map[string]string{"/usr/local/bin/php": "", "/usr/local/bin/frankenphp": "", "/home/dev/.nvm/versions/node/v18.20.4/bin/node": "", "/usr/bin/nodejs-unused": ""}
	fs := writeFiles(t, files)
	os.Chmod(fs.P("/usr/local/bin/php"), 0o775)                                         // group-writable file
	os.Chmod(fs.P("/usr/local/bin"), 0o777)                                             // world-writable directory
	os.Symlink("/home/dev/.nvm/versions/node/v18.20.4/bin/node", fs.P("/usr/bin/node")) // PATH node into a user's tree
	out, err := New(Deps{Runner: f, FS: fs, OwnerUID: os.Getuid()}).Inspect(context.Background(), Payload{}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err)
	}
	for _, l := range f.Lines() {
		if strings.Contains(l, "/usr/local/bin/") || strings.Contains(l, "node") {
			t.Fatalf("ran %q", l)
		}
	}
	r := out.(*Report)
	if len(r.PHP) != 1 || r.PHP[0].Version != "" || len(r.FrankenPHP) != 1 || r.FrankenPHP[0].Version != "" {
		t.Fatalf("%+v %+v", r.PHP, r.FrankenPHP)
	}
	// /usr/bin/node itself is fine, but it resolves into /home/dev: not executed; nvm's version comes from its directory.
	for _, n := range r.Node {
		if n.Path == "/usr/bin/node" && n.Version != "" {
			t.Fatalf("%+v", n)
		}
		if strings.Contains(n.Path, ".nvm") && n.Version != "18.20.4" {
			t.Fatalf("%+v", n)
		}
	}
}

func TestSafeChecksTheWholePath(t *testing.T) {
	fs := writeFiles(t, map[string]string{"/usr/bin/docker": "", "/opt/tools/bin/docker": ""})
	in := New(Deps{FS: fs, OwnerUID: os.Getuid()})
	if _, err := in.safe("/usr/bin/docker"); err != nil {
		t.Fatal(err)
	}
	os.Chmod(fs.P("/opt/tools"), 0o757)
	if _, err := in.safe("/opt/tools/bin/docker"); err == nil || !strings.Contains(err.Error(), "/opt/tools is writable by group or others") {
		t.Fatalf("%v", err)
	}
	os.Symlink("/opt/tools/bin/docker", fs.P("/usr/bin/docker2"))
	if _, err := in.safe("/usr/bin/docker2"); err == nil {
		t.Fatal("a symlink into a writable tree is unsafe")
	}
	if _, err := New(Deps{FS: fs, OwnerUID: os.Getuid() + 1}).safe("/usr/bin/docker"); err == nil || !strings.Contains(err.Error(), "not owned by root") {
		t.Fatalf("%v", err)
	}
}

// The CLI runs the first plugin file it finds: a writable one anywhere on its search path stops the plugin command.
func TestInspectDoesNotRunWritableDockerPlugins(t *testing.T) {
	f := (&runnertest.Fake{}).On("/usr/bin/docker version --format {{.Server.Version}}", runner.Result{Stdout: []byte("28.1.1\n")})
	fs := writeFiles(t, map[string]string{"/usr/bin/docker": "", "/root/.docker/cli-plugins/docker-compose": "", "/usr/libexec/docker/cli-plugins/docker-compose": ""})
	os.Chmod(fs.P("/root/.docker/cli-plugins/docker-compose"), 0o777)
	out, err := New(Deps{Runner: f, FS: fs, OwnerUID: os.Getuid()}).Inspect(context.Background(), Payload{}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err)
	}
	if f.Ran("/usr/bin/docker compose") {
		t.Fatal("ran the compose plugin")
	}
	d := out.(*Report).Docker
	if d.Compose == nil || d.Compose.Version != "" || d.Compose.Path != "/root/.docker/cli-plugins/docker-compose" {
		t.Fatalf("%+v", d.Compose)
	}
}

func TestReportCarriesNoAptCredentials(t *testing.T) {
	secret := "https://deploy:s3cr3t-t0ken@apt.example.com/repo"
	f := (&runnertest.Fake{}).
		On("/usr/bin/dpkg-query", runner.Result{Stdout: []byte("acme-agent\tinstalled\t1.2.3\n")}).
		OnFunc("/usr/bin/apt-cache policy", func(c runnertest.Call) (runner.Result, error) {
			if len(c.Args) == 1 {
				return runner.Result{Stdout: []byte("Package files:\n 500 " + secret + " stable/main amd64 Packages\n     release o=Acme,a=stable\n")}, nil
			}
			return runner.Result{Stdout: []byte("acme-agent:\n  Installed: 1.2.3\n  Version table:\n *** 1.2.3 500\n        500 " + secret + " stable/main amd64 Packages\n")}, nil
		}).
		On("/usr/bin/systemctl", runner.Result{ExitCode: 1, Stderr: []byte("Failed to fetch " + secret + "/dists/stable/InRelease  401  Unauthorized\n")})
	r := inspectWith(t, f, map[string]string{
		"/usr/bin/dpkg-query": "", "/usr/bin/apt-cache": "", "/usr/bin/systemctl": "",
		"/etc/apt/sources.list.d/acme.list": "deb [signed-by=/etc/apt/keyrings/acme.gpg] " + secret + " stable main\n",
	}, os.Getuid())
	b, _ := json.Marshal(r)
	if strings.Contains(string(b), "s3cr3t") || strings.Contains(string(b), "deploy:") {
		t.Fatalf("credentials in the report: %s", b)
	}
	if r.Packages[0].Repo != "https://apt.example.com/repo" || r.AptSources[0].URIs[0] != "https://apt.example.com/repo" {
		t.Fatalf("%+v %+v", r.Packages, r.AptSources)
	}
	if redact("E: Failed to fetch https://u:p@h/x and ftp://a@b/y") != "E: Failed to fetch https://h/x and ftp://b/y" {
		t.Fatal(redact("E: Failed to fetch https://u:p@h/x and ftp://a@b/y"))
	}
}

func TestPolicyPhasedVersionsAndMissingLists(t *testing.T) {
	policy := ParsePolicy(`curl:
  Installed: 8.5.0-2ubuntu10.5
  Candidate: 8.5.0-2ubuntu10.5
  Version table:
     8.5.0-2ubuntu10.6 1 (phased 10%)
        500 http://archive.ubuntu.com/ubuntu noble-updates/main amd64 Packages
 *** 8.5.0-2ubuntu10.5 500
        500 http://security.ubuntu.com/ubuntu noble-security/main amd64 Packages
        100 /var/lib/dpkg/status
`)
	if got := policy["curl"]; len(got.Installed) != 1 || !strings.HasPrefix(got.Installed[0], "http://security.ubuntu.com") || len(got.Other) != 1 {
		t.Fatalf("%+v", got)
	}
	pkgs := []Package{{Name: "curl"}, {Name: "x"}}
	ApplyOrigins(pkgs, nil, map[string]PolicyFiles{}, false)
	if pkgs[0].Origin != OriginUnknown || pkgs[1].Origin != OriginUnknown {
		t.Fatalf("no package lists yet: %+v", pkgs)
	}
	ApplyOrigins(pkgs, nil, map[string]PolicyFiles{}, true)
	if pkgs[0].Origin != OriginManual {
		t.Fatalf("%+v", pkgs)
	}
}

func TestSSHAllowListsAndGroups(t *testing.T) {
	eff := ParseSSHDT("allowusers deploy\nallowusers ops@10.0.0.*\ndenygroups nossh\npermitrootlogin no\n")
	if eff["allowusers"] != "deploy ops@10.0.0.*" || eff["denygroups"] != "nossh" || eff["permitrootlogin"] != "no" {
		t.Fatalf("%v", eff)
	}
}
