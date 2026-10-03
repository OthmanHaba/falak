package inspect

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

// The incident machine (Ubuntu 26.04, Docker from Docker's repository) as the tools print it.
const (
	incidentDpkg = "containerd.io\tinstalled\t1.7.27-1\n" +
		"docker-buildx-plugin\tinstalled\t0.23.0-1~ubuntu.26.04~resolute\n" +
		"docker-ce\tinstalled\t5:28.1.1-1~ubuntu.26.04~resolute\n" +
		"docker-ce-cli\tinstalled\t5:28.1.1-1~ubuntu.26.04~resolute\n" +
		"docker-compose-plugin\tinstalled\t2.35.1-1~ubuntu.26.04~resolute\n" +
		"docker.io\tconfig-files\t27.5.1-0ubuntu3\n" +
		"openssh-server\tinstalled\t1:9.9p1-3ubuntu3\n" +
		"postgresql-17\tinstalled\t17.5-1.pgdg24.04+1\n" +
		"tool\tinstalled\t1.0\n"

	incidentReleases = `Package files:
 100 /var/lib/dpkg/status
     release a=now
 500 https://download.docker.com/linux/ubuntu resolute/stable amd64 Packages
     release o=Docker,a=resolute,l=Docker CE,c=stable,b=amd64
     origin download.docker.com
 500 http://apt.postgresql.org/pub/repos/apt resolute-pgdg/main amd64 Packages
     release o=apt.postgresql.org,a=resolute-pgdg,n=resolute-pgdg,l=PostgreSQL for Debian/Ubuntu repository,c=main,b=amd64
     origin apt.postgresql.org
 500 http://de.archive.ubuntu.com/ubuntu resolute/main amd64 Packages
     release v=26.04,o=Ubuntu,a=resolute,n=resolute,l=Ubuntu,c=main,b=amd64
     origin de.archive.ubuntu.com
Pinned packages:
`

	incidentPolicy = `containerd.io:
  Installed: 1.7.27-1
  Candidate: 1.7.27-1
  Version table:
 *** 1.7.27-1 500
        500 https://download.docker.com/linux/ubuntu resolute/stable amd64 Packages
        100 /var/lib/dpkg/status
docker-ce:
  Installed: 5:28.1.1-1~ubuntu.26.04~resolute
  Candidate: 5:28.1.1-1~ubuntu.26.04~resolute
  Version table:
 *** 5:28.1.1-1~ubuntu.26.04~resolute 500
        500 https://download.docker.com/linux/ubuntu resolute/stable amd64 Packages
        100 /var/lib/dpkg/status
     5:28.1.0-1~ubuntu.26.04~resolute 500
        500 https://download.docker.com/linux/ubuntu resolute/stable amd64 Packages
openssh-server:
  Installed: 1:9.9p1-3ubuntu3
  Candidate: 1:9.9p1-3ubuntu3
  Version table:
 *** 1:9.9p1-3ubuntu3 500
        500 http://de.archive.ubuntu.com/ubuntu resolute/main amd64 Packages
        100 /var/lib/dpkg/status
postgresql-17:
  Installed: 17.5-1.pgdg24.04+1
  Candidate: 17.5-1.pgdg24.04+1
  Version table:
 *** 17.5-1.pgdg24.04+1 500
        500 http://apt.postgresql.org/pub/repos/apt resolute-pgdg/main amd64 Packages
        100 /var/lib/dpkg/status
tool:
  Installed: 1.0
  Candidate: 2.0
  Version table:
     2.0 500
        500 http://de.archive.ubuntu.com/ubuntu resolute/main amd64 Packages
 *** 1.0 100
        100 /var/lib/dpkg/status
`

	incidentSS = `LISTEN 0      511          0.0.0.0:80        0.0.0.0:*    users:(("nginx",pid=1234,fd=6),("nginx",pid=1235,fd=6))
LISTEN 0      511             [::]:80           [::]:*    users:(("nginx",pid=1234,fd=7))
LISTEN 0      4096   127.0.0.53%lo:53        0.0.0.0:*    users:(("systemd-resolve",pid=500,fd=14))
LISTEN 0      4096         0.0.0.0:6379      0.0.0.0:*    users:(("docker-proxy",pid=999,fd=4))
LISTEN 0      200        127.0.0.1:5432      0.0.0.0:*    users:(("postgres",pid=700,fd=6))
LISTEN 0      4096               *:22              *:*    users:(("sshd",pid=1,fd=3),("systemd",pid=1,fd=50))
`

	incidentSystemctl = `Id=docker.service
LoadState=loaded
ActiveState=active
UnitFileState=enabled

Id=nginx.service
LoadState=loaded
ActiveState=active
UnitFileState=enabled

Id=caddy.service
LoadState=not-found
ActiveState=inactive
UnitFileState=

Id=fail2ban.service
LoadState=loaded
ActiveState=active
UnitFileState=enabled
`

	incidentSSHDT = "port 22\npermitrootlogin without-password\npubkeyauthentication yes\npasswordauthentication yes\nkbdinteractiveauthentication no\nauthorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\nx11forwarding yes\n"
)

func writeFiles(t *testing.T, files map[string]string) hostfs.FS {
	t.Helper()
	root := t.TempDir()
	for p, c := range files {
		full := filepath.Join(root, p)
		if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(full, []byte(c), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	return hostfs.FS{Root: root}
}

func TestParseDpkgQueryKeepsInstalledOnly(t *testing.T) {
	pkgs := ParseDpkgQuery(incidentDpkg)
	var names []string
	for _, p := range pkgs {
		names = append(names, p.Name)
	}
	if strings.Join(names, ",") != "containerd.io,docker-buildx-plugin,docker-ce,docker-ce-cli,docker-compose-plugin,openssh-server,postgresql-17,tool" {
		t.Fatal(names)
	}
}

func TestOriginsFromAptCachePolicy(t *testing.T) {
	pkgs := ParseDpkgQuery(incidentDpkg)
	ApplyOrigins(pkgs, ParseReleases(incidentReleases), ParsePolicy(incidentPolicy), true)
	got := map[string]Package{}
	for _, p := range pkgs {
		got[p.Name] = p
	}
	for name, want := range map[string]Package{
		"docker-ce":      {Origin: OriginVendor, Repo: "https://download.docker.com/linux/ubuntu", Label: "Docker"},
		"containerd.io":  {Origin: OriginVendor, Repo: "https://download.docker.com/linux/ubuntu", Label: "Docker"},
		"postgresql-17":  {Origin: OriginVendor, Repo: "http://apt.postgresql.org/pub/repos/apt", Label: "apt.postgresql.org"},
		"openssh-server": {Origin: OriginArchive, Repo: "http://de.archive.ubuntu.com/ubuntu", Label: "Ubuntu"},
		// Installed 1.0 is only in dpkg's status, but the archive offers the package (lists newer than the install,
		// as curl on a cloud image): it comes from the archive, not a manual install.
		"tool": {Origin: OriginArchive, Repo: "http://de.archive.ubuntu.com/ubuntu", Label: "Ubuntu"},
		// Not in the policy output at all.
		"docker-compose-plugin": {Origin: OriginManual},
	} {
		g := got[name]
		if g.Origin != want.Origin || g.Repo != want.Repo || g.Label != want.Label {
			t.Errorf("%s: got %+v, want %+v", name, g, want)
		}
	}
}

func TestReleasesKeepCommasInLabels(t *testing.T) {
	rel := ParseReleases(" 500 http://x/ubuntu a/main amd64 Packages\n     release o=Acme, Inc.,a=stable,l=Acme\n")
	if r := rel["http://x/ubuntu a/main amd64 Packages"]; r.Origin != "Acme, Inc." || r.Label != "Acme" {
		t.Fatalf("%+v", rel)
	}
}

func TestArchiveHostWithoutReleaseInfo(t *testing.T) {
	pkgs := []Package{{Name: "curl"}, {Name: "x"}}
	ApplyOrigins(pkgs, nil, map[string]PolicyFiles{"curl": {Installed: []string{"http://archive.ubuntu.com/ubuntu noble/main amd64 Packages"}}, "x": {Installed: []string{"https://repo.example.com/deb stable/main amd64 Packages"}}}, true)
	if pkgs[0].Origin != OriginArchive || pkgs[1].Origin != OriginVendor || pkgs[1].Repo != "https://repo.example.com/deb" {
		t.Fatalf("%+v", pkgs)
	}
}

func TestParseSnapList(t *testing.T) {
	s := ParseSnapList("Name    Version    Rev    Tracking       Publisher   Notes\ncore22  20240111   1122   latest/stable  canonical✓  base\ndocker  27.2.0     2963   latest/stable  canonical✓  -\n")
	if len(s) != 2 || s[1].Name != "docker" || s[1].Version != "27.2.0" || s[1].Channel != "latest/stable" {
		t.Fatalf("%+v", s)
	}
}

func TestParseSSOwnersAndContainers(t *testing.T) {
	ls := ParseSS(incidentSS)
	var got []string
	for _, l := range ls {
		got = append(got, l.Address+":"+itoa(l.Port)+"="+l.Process+map[bool]string{true: "(container)"}[l.Container])
	}
	want := "*:22=sshd,127.0.0.53:53=systemd-resolve,0.0.0.0:80=nginx,:::80=nginx,127.0.0.1:5432=postgres,0.0.0.0:6379=docker-proxy(container)"
	if strings.Join(got, ",") != want {
		t.Fatalf("got  %s\nwant %s", strings.Join(got, ","), want)
	}
	if ls[2].PID != 1234 {
		t.Fatal(ls[2])
	}
}

func itoa(i int) string { b, _ := json.Marshal(i); return string(b) }

func TestUnitFromCgroup(t *testing.T) {
	for in, want := range map[string]string{
		"0::/system.slice/nginx.service\n":                                      "nginx.service",
		"0::/system.slice/kiln-edge.service\n":                                  "kiln-edge.service",
		"12:pids:/system.slice/docker.service\n0::/system.slice/docker.service": "docker.service",
		"0::/user.slice/user-1000.slice/session-3.scope\n":                      "session-3.scope",
		"0::/\n": "",
	} {
		if got := UnitFromCgroup(in); got != want {
			t.Errorf("%q: %q", in, got)
		}
	}
}

func TestParseDockerPS(t *testing.T) {
	cs := ParseDockerPS("cache\tredis:7\t0.0.0.0:6379->6379/tcp, [::]:6379->6379/tcp\nworker\tapp:latest\t\nweb\tnginx\t127.0.0.1:8080->80/tcp, 443/tcp\n")
	if len(cs) != 3 || len(cs[0].Ports) != 1 || cs[0].Ports[0].HostPort != 6379 || cs[0].Ports[0].HostIP != "0.0.0.0" || len(cs[1].Ports) != 0 {
		t.Fatalf("%+v", cs)
	}
	if p := cs[2].Ports; len(p) != 1 || p[0].HostIP != "127.0.0.1" || p[0].HostPort != 8080 || p[0].ContainerPort != 80 {
		t.Fatalf("an exposed but unpublished port is no host port: %+v", p)
	}
}

func TestParseSystemctlShowSkipsMissingUnits(t *testing.T) {
	s := ParseSystemctlShow(incidentSystemctl)
	if len(s) != 3 || s[1] != (Service{Unit: "nginx.service", Active: "active", Enabled: "enabled"}) {
		t.Fatalf("%+v", s)
	}
}

func TestPluginVersion(t *testing.T) {
	for in, want := range map[string]string{"2.35.1\n": "2.35.1", "v2.29.7": "2.29.7", "github.com/docker/buildx v0.23.0 28c90ea\n": "0.23.0", "github.com/docker/buildx 0.12.1 \n": "0.12.1", "": ""} {
		if got := PluginVersion(in); got != want {
			t.Errorf("%q: %q", in, got)
		}
	}
}

func TestParseDaemonJSON(t *testing.T) {
	d := ParseDaemonJSON([]byte(`{"bip": "172.26.0.1/16", "default-address-pools": [{"base": "10.200.0.0/16", "size": 24}], "iptables": false, "userns-remap": "default", "log-driver": "json-file"}`))
	if d.BIP != "172.26.0.1/16" || len(d.DefaultAddressPools) != 1 || d.IPTables == nil || *d.IPTables || d.UsernsRemap != "default" {
		t.Fatalf("%+v", d)
	}
	if d := ParseDaemonJSON([]byte("{oops")); d.Error == "" {
		t.Fatal("invalid JSON is reported, not fatal")
	}
}

func TestSSHEffectiveFromFilesFirstValueWins(t *testing.T) {
	dropIns := []SSHDropIn{
		{File: "/etc/ssh/sshd_config.d/50-cloud-init.conf", Settings: ParseSSHDConfig("PasswordAuthentication yes\n")},
		{File: "/etc/ssh/sshd_config.d/50-kiln.conf", Settings: ParseSSHDConfig("# Managed by Kiln\nPort 22\nPasswordAuthentication no\n")},
	}
	eff := EffectiveFromFiles("Include /etc/ssh/sshd_config.d/*.conf\nPermitRootLogin yes\nMatch User backup\n  PasswordAuthentication no\n", dropIns)
	if eff["passwordauthentication"] != "yes" || eff["permitrootlogin"] != "yes" || eff["port"] != "22" || eff["authorizedkeysfile"] != ".ssh/authorized_keys .ssh/authorized_keys2" {
		t.Fatalf("%v", eff)
	}
	if got := ParseSSHDT(incidentSSHDT + "port 2222\n"); got["port"] != "22 2222" || got["passwordauthentication"] != "yes" || got["x11forwarding"] != "" {
		t.Fatalf("%v", got)
	}
}

func TestParseProcSwapsAndNftTables(t *testing.T) {
	s := ParseProcSwaps("Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n/dev/vda3                               partition\t2097148\t\t0\t\t-2\n/var/swap\\040file file 1024 0 -3\n")
	if len(s) != 2 || s[0].Name != "/dev/vda3" || s[0].SizeBytes != 2097148<<10 || s[1].Name != "/var/swap file" {
		t.Fatalf("%+v", s)
	}
	if ts := ParseNftTables("table inet filter\ntable ip nat\ntable inet kiln\n"); strings.Join(ts, ",") != "inet filter,ip nat,inet kiln" {
		t.Fatal(ts)
	}
}

// incidentHost wires a fake runner and file tree for the incident machine (plus nginx on :80 and a Redis container).
func incidentHost(t *testing.T) (*runnertest.Fake, hostfs.FS) {
	t.Helper()
	fs := writeFiles(t, map[string]string{
		"/etc/os-release":                                "ID=ubuntu\nVERSION_ID=\"26.04\"\nVERSION_CODENAME=resolute\n",
		"/etc/hostname":                                  "customer-vm\n",
		"/etc/apt/sources.list.d/docker.list":            "deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu resolute stable\n",
		"/etc/apt/sources.list.d/ubuntu.sources":         "Types: deb\nURIs: http://de.archive.ubuntu.com/ubuntu/\nSuites: resolute\n",
		"/usr/bin/docker":                                "",
		"/usr/libexec/docker/cli-plugins/docker-compose": "",
		"/etc/docker/daemon.json":                        `{"default-address-pools": [{"base": "10.200.0.0/16", "size": 24}]}`,
		"/proc/1234/cgroup":                              "0::/system.slice/nginx.service\n",
		"/proc/999/cgroup":                               "0::/system.slice/docker.service\n",
		"/proc/swaps":                                    "Filename Type Size Used Priority\n/swap.img file 4194300 0 -2\n",
		"/etc/passwd":                                    "root:x:0:0:root:/root:/bin/bash\ndaemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin\nubuntu:x:1000:1000::/home/ubuntu:/bin/bash\nnobody:x:65534:65534::/nonexistent:/usr/sbin/nologin\n",
		"/etc/group":                                     "root:x:0:\nsudo:x:27:ubuntu\nubuntu:x:1000:\n",
		"/home/ubuntu/.ssh/authorized_keys":              "# added by cloud-init\nssh-ed25519 AAAAC3Nza laptop\n\n",
		"/etc/ssh/sshd_config.d/50-cloud-init.conf":      "PasswordAuthentication yes\n",
		"/etc/fail2ban/jail.d/sshd.local":                "[sshd]\nenabled = true\n",
		"/etc/apt/apt.conf.d/20auto-upgrades":            "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"0\";\n",
		"/usr/local/bin/php":                             "",
		"/root/.nvm/versions/node/v20.11.0/bin/node":     "",
		"/opt/kiln/node/22.20.0/bin/node":                "",
		"/usr/sbin/ufw":                                  "",
		"/usr/sbin/nft":                                  "",
		"/usr/sbin/sshd":                                 "",
		"/usr/bin/dpkg-query":                            "",
		"/usr/bin/apt-cache":                             "",
		"/usr/bin/systemctl":                             "",
		"/usr/bin/systemd-detect-virt":                   "",
		"/usr/bin/ss":                                    "",
		"/var/lib/apt/lists/de.archive.ubuntu.com_ubuntu_dists_resolute_main_binary-amd64_Packages": "",
	})
	os.MkdirAll(fs.P("/usr/local/bin"), 0o755)
	if err := os.Symlink("/opt/kiln/node/22.20.0/bin/node", fs.P("/usr/local/bin/node")); err != nil {
		t.Fatal(err)
	}
	f := (&runnertest.Fake{}).
		On("/usr/bin/dpkg-query", runner.Result{ExitCode: 1, Stdout: []byte(incidentDpkg + "php8.3-cli\tinstalled\t8.3.6-0ubuntu0.24.04.1\n")}).
		OnFunc("/usr/bin/apt-cache policy", func(c runnertest.Call) (runner.Result, error) {
			if len(c.Args) == 1 {
				return runner.Result{Stdout: []byte(incidentReleases)}, nil
			}
			return runner.Result{Stdout: []byte(incidentPolicy)}, nil
		}).
		On("/usr/bin/systemd-detect-virt", runner.Result{ExitCode: 1}).
		On("/usr/bin/systemctl show", runner.Result{Stdout: []byte(incidentSystemctl)}).
		On("/usr/bin/ss -H -ltnp", runner.Result{Stdout: []byte(incidentSS)}).
		On("/usr/bin/docker version --format {{.Client.Version}}", runner.Result{Stdout: []byte("28.1.1\n")}).
		On("/usr/bin/docker version --format {{.Server.Version}}", runner.Result{Stdout: []byte("28.1.1\n")}).
		On("/usr/bin/docker compose version --short", runner.Result{Stdout: []byte("2.35.1\n")}).
		On("/usr/bin/docker buildx version", runner.Result{Stdout: []byte("github.com/docker/buildx v0.23.0 28c90ea\n")}).
		On("/usr/bin/docker ps", runner.Result{Stdout: []byte("cache\tredis:7\t0.0.0.0:6379->6379/tcp, [::]:6379->6379/tcp\n")}).
		On("/usr/sbin/sshd -T", runner.Result{Stdout: []byte(incidentSSHDT)}).
		On("/usr/sbin/ufw status", runner.Result{Stdout: []byte("Status: active\n\nTo                         Action      From\n22/tcp                     ALLOW       Anywhere\n")}).
		On("/usr/sbin/nft list tables", runner.Result{Stdout: []byte("table ip filter\ntable ip nat\n")}).
		On("/usr/local/bin/php", runner.Result{Stdout: []byte("8.2")})
	return f, fs
}

func TestInspectTheIncidentMachine(t *testing.T) {
	f, fs := incidentHost(t)
	out, err := New(Deps{Runner: f, FS: fs, OwnerUID: os.Getuid()}).Inspect(context.Background(), Payload{Packages: []string{"acl", "bad pattern!"}}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err)
	}
	r := out.(*Report)
	if len(r.Errors) != 0 {
		t.Fatalf("errors: %+v", r.Errors)
	}
	if r.Hostname != "customer-vm" || r.OS != (OS{ID: "ubuntu", Version: "26.04", Codename: "resolute"}) || r.InContainer {
		t.Fatalf("%+v %+v", r.Hostname, r.OS)
	}
	if dq := f.Calls()[1]; dq.Name != "/usr/bin/dpkg-query" || !strings.Contains(dq.Line, " acl") || strings.Contains(dq.Line, "bad pattern") || !strings.Contains(dq.Line, " postgresql-[0-9]*") {
		t.Fatalf("dpkg-query patterns: %s", dq.Line)
	}

	d := r.Docker
	if d == nil || d.EnginePackage != "docker-ce" || d.ServerVersion != "28.1.1" || !d.SystemDaemon || d.Snap || d.Rootless {
		t.Fatalf("docker %+v", d)
	}
	if d.Compose == nil || d.Compose.Version != "2.35.1" || d.Compose.Package != "docker-compose-plugin" || d.Compose.Path != "/usr/libexec/docker/cli-plugins/docker-compose" {
		t.Fatalf("compose %+v", d.Compose)
	}
	if d.Buildx == nil || d.Buildx.Version != "0.23.0" || d.Buildx.Package != "docker-buildx-plugin" {
		t.Fatalf("buildx %+v", d.Buildx)
	}
	if d.Daemon == nil || len(d.Daemon.DefaultAddressPools) != 1 || d.Daemon.IPTables != nil {
		t.Fatalf("daemon %+v", d.Daemon)
	}
	if len(r.Containers) != 1 || r.Containers[0].Image != "redis:7" {
		t.Fatalf("%+v", r.Containers)
	}

	var l80 *Listener
	for i := range r.Listeners {
		if r.Listeners[i].Port == 80 {
			l80 = &r.Listeners[i]
			break
		}
	}
	if l80 == nil || l80.Process != "nginx" || l80.Unit != "nginx.service" {
		t.Fatalf("%+v", r.Listeners)
	}

	s := r.SSH
	if s.EffectiveSource != "sshd -T" || s.Effective["passwordauthentication"] != "yes" || s.Effective["port"] != "22" || s.Effective["authorizedkeysfile"] != "" {
		t.Fatalf("%+v", s)
	}
	if len(s.DropIns) != 1 || s.DropIns[0].Settings["passwordauthentication"] != "yes" {
		t.Fatalf("%+v", s.DropIns)
	}
	if len(s.Users) != 2 || fmt.Sprint(s.Users) != fmt.Sprint([]LoginUser{{Name: "root", UID: 0, Groups: []string{"root"}}, {Name: "ubuntu", UID: 1000, AuthorizedKeys: 1, Groups: []string{"ubuntu", "sudo"}}}) {
		t.Fatalf("%+v", s.Users)
	}

	if r.Firewall.UFW != "active" || r.Firewall.Firewalld != "absent" || strings.Join(r.Firewall.Tables, ",") != "ip filter,ip nat" {
		t.Fatalf("%+v", r.Firewall)
	}
	if len(r.Swap) != 1 || r.Swap[0].Name != "/swap.img" {
		t.Fatalf("%+v", r.Swap)
	}
	sources := map[string]string{}
	for _, n := range r.Node {
		sources[n.Path] = n.Source + " " + n.Version
	}
	if sources["/usr/local/bin/node"] != "kiln 22.20.0" || sources["/root/.nvm/versions/node/v20.11.0/bin/node"] != "nvm 20.11.0" || sources["/opt/kiln/node/22.20.0/bin/node"] != "kiln 22.20.0" {
		t.Fatalf("%v", sources)
	}
	if len(r.PHP) != 2 || r.PHP[0].Version != "8.3" || r.PHP[0].Package != "php8.3-cli" || r.PHP[1] != (Binary{Path: "/usr/local/bin/php", Version: "8.2", Source: "manual"}) {
		t.Fatalf("%+v", r.PHP)
	}
	if u := r.UnattendedUpgrades; u.Installed || u.ManagedByKiln || u.Periodic["Unattended-Upgrade"] != "0" {
		t.Fatalf("%+v", u)
	}
	if f2b := r.Fail2ban; f2b.Installed || !f2b.Active || len(f2b.Jails) != 1 {
		t.Fatalf("%+v", f2b)
	}
	if len(r.AptSources) != 2 || r.AptSources[0].File != "/etc/apt/sources.list.d/docker.list" {
		t.Fatalf("%+v", r.AptSources)
	}

	// Read-only: no command that changes the machine.
	for _, l := range f.Lines() {
		for _, bad := range []string{"/apt-get", "/systemctl start", "/systemctl enable", "/systemctl stop", "/mkdir", "/hostnamectl", "/swapon", "/ufw enable", "/ufw disable", "/node", ".nvm"} {
			if strings.Contains(l, bad) {
				t.Fatalf("inspect ran %q", l)
			}
		}
	}
}

func TestInspectDegradesPerDetector(t *testing.T) {
	fs := writeFiles(t, map[string]string{"/etc/hostname": "bare\n", "/etc/passwd": "root:x:0:0:root:/root:/bin/bash\n", "/etc/ssh/sshd_config": "PasswordAuthentication no\n"})
	f := (&runnertest.Fake{}).
		On("dpkg-query", runner.Result{ExitCode: 2, Stderr: []byte("dpkg-query: error: cannot access archive\n")}).
		On("systemctl show", runner.Result{ExitCode: 1, Stderr: []byte("System has not been booted with systemd\n")}).
		On("ss", runner.Result{ExitCode: 127, Stderr: []byte("ss: not found\n")}).
		On("sshd -T", runner.Result{ExitCode: 255, Stderr: []byte("Missing privilege separation directory: /run/sshd\n")})
	out, err := New(Deps{Runner: f, FS: fs}).Inspect(context.Background(), Payload{}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err)
	}
	r := out.(*Report)
	var failed []string
	for _, e := range r.Errors {
		failed = append(failed, e.Detector)
	}
	if strings.Join(failed, ",") != "packages,services,listeners,swap" {
		t.Fatalf("%+v", r.Errors)
	}
	if r.Docker != nil || r.SSH.EffectiveSource != "files" || r.SSH.Effective["passwordauthentication"] != "no" || len(r.SSH.Users) != 1 {
		t.Fatalf("%+v %+v", r.Docker, r.SSH)
	}
	b, _ := json.Marshal(r)
	for _, null := range []string{`"packages":null`, `"listeners":null`, `"services":null`, `"swap":null`, `"drop_ins":null`} {
		if strings.Contains(string(b), null) {
			t.Fatalf("%s in %s", null, b)
		}
	}
}

func TestInspectCancelled(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := New(Deps{Runner: &runnertest.Fake{}, FS: writeFiles(t, nil)}).Inspect(ctx, Payload{}, commands.NewTestStream("c", &commands.Collector{})); err == nil {
		t.Fatal("cancellation must fail the command")
	}
}

// Snap and rootless Docker are reported without a CLI on the system PATH.
func TestInspectSnapAndRootlessDocker(t *testing.T) {
	fs := writeFiles(t, map[string]string{"/usr/bin/snap": "", "/home/dev/.config/systemd/user/docker.service": ""})
	f := (&runnertest.Fake{}).On("/usr/bin/snap list", runner.Result{Stdout: []byte("Name Version Rev Tracking Publisher Notes\ndocker 27.2.0 2963 latest/stable canonical -\n")})
	out, _ := New(Deps{Runner: f, FS: fs, OwnerUID: os.Getuid()}).Inspect(context.Background(), Payload{}, commands.NewTestStream("c", &commands.Collector{}))
	d := out.(*Report).Docker
	if d == nil || !d.Snap || !d.Rootless || d.SystemDaemon || d.EnginePackage != "" {
		t.Fatalf("%+v", d)
	}
}
