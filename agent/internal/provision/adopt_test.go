package provision

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner/runnertest"
)

const adoptPlan = `{
  "hostname": "app-1", "swap_mb": 2048,
  "apt": {"packages": ["git", "docker-ce", "docker-compose-plugin"]},
  "services": [{"name": "docker", "enabled": true, "state": "started"}],
  "unattended_upgrades": {"enabled": true},
  "components": [
    {"name": "docker", "decision": "adopt", "packages": ["docker-ce", "docker-compose-plugin", "docker-buildx-plugin"], "service": "docker"},
    {"name": "hostname", "decision": "adopt"},
    {"name": "swap", "decision": "adopt"},
    {"name": "unattended_upgrades", "decision": "adopt"},
    {"name": "base", "decision": "complete", "packages": ["git"]}
  ]}`

// The incident machine: Docker from Docker's repository, adopted. The apt step must not ask for any Docker package
// (Ubuntu's docker-buildx overwrites docker-buildx-plugin's files), only verify the adopted ones.
func TestApplyAdoptedComponentsAreVerifiedNeverInstalled(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	h := newHost(f, root)
	for _, p := range []string{"docker-ce", "docker-compose-plugin", "docker-buildx-plugin", "unattended-upgrades"} {
		h.pkgs[p] = true
	}
	os.MkdirAll(filepath.Join(root, "etc/apt/apt.conf.d"), 0o755)
	custom := "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n"
	os.WriteFile(filepath.Join(root, "etc/apt/apt.conf.d/20auto-upgrades"), []byte(custom), 0o644)
	os.WriteFile(filepath.Join(root, "etc/hostname"), []byte("customer-vm\n"), 0o644)
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, Arch: "amd64"})
	plan, err := commands.Decode[Plan](json.RawMessage(adoptPlan))
	if err != nil {
		t.Fatal(err)
	}
	r, err := p.Apply(context.Background(), plan, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err, r)
	}
	var names []string
	for _, s := range r.(Result).Steps {
		names = append(names, s.Name)
	}
	if strings.Join(names, ",") != "swap,apt,adopt:docker,service:docker,unattended_upgrades" {
		t.Fatal(names)
	}
	installs := 0
	for _, l := range f.Lines() {
		if strings.HasPrefix(l, "apt-get install") {
			installs++
			if strings.Contains(l, "docker") || !strings.HasSuffix(l, " git") {
				t.Fatalf("installed an adopted package: %s", l)
			}
		}
		for _, bad := range []string{"hostnamectl", "fallocate", "mkswap", "swapon"} {
			if strings.HasPrefix(l, bad) {
				t.Fatalf("touched an adopted component: %s", l)
			}
		}
	}
	if installs != 1 || !h.pkgs["git"] {
		t.Fatalf("git (complete) installed once:\n%s", strings.Join(f.Lines(), "\n"))
	}
	if !f.Ran("systemctl enable docker") || !f.Ran("systemctl start docker") {
		t.Fatalf("the adopted service is enabled and started:\n%s", strings.Join(f.Lines(), "\n"))
	}
	if b, _ := os.ReadFile(filepath.Join(root, "etc/apt/apt.conf.d/20auto-upgrades")); string(b) != custom {
		t.Fatal("adopted unattended-upgrades config overwritten:", string(b))
	}
	if _, err := os.Stat(filepath.Join(root, "etc/apt/apt.conf.d/52kiln-unattended")); err == nil {
		t.Fatal("Kiln's unattended config written for an adopted one")
	}

	// The machine changed after the check: an adopted package is gone. The step fails; nothing installs it.
	delete(h.pkgs, "docker-buildx-plugin")
	f.Reset()
	r, err = p.Apply(context.Background(), plan, commands.NewTestStream("c", &commands.Collector{}))
	if err == nil || !strings.Contains(err.Error(), "adopt:docker") {
		t.Fatal(err)
	}
	for _, s := range r.(Result).Steps {
		if s.Name == "adopt:docker" && !strings.Contains(s.Error, "docker-buildx-plugin is no longer installed; run the machine check again") {
			t.Fatal(s.Error)
		}
	}
	if h.pkgs["docker-buildx-plugin"] || f.Ran("apt-get install -y -q -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold --no-install-recommends docker") {
		t.Fatal("reinstalled an adopted package")
	}
}

// Without components (agents of control planes before provision.v2 plans, or no machine check) nothing changes.
func TestApplyWithoutComponentsKeepsTodaysSteps(t *testing.T) {
	plan, err := commands.Decode[Plan](json.RawMessage(planJSON))
	if err != nil {
		t.Fatal(err)
	}
	p := New(Deps{Runner: &runnertest.Fake{}, FS: hostfs.FS{Root: t.TempDir()}})
	var names []string
	for _, s := range p.steps(plan) {
		names = append(names, s.name)
	}
	if strings.Join(names, ",") != "hostname,timezone,swap,apt,user:shop,caddy,service:cron,unattended_upgrades,ssh" {
		t.Fatal(names)
	}
	if got := withoutAdopted([]string{"git", "docker-ce=5:28.1.1"}, map[string]Component{"docker": {Packages: []string{"docker-ce"}}}); strings.Join(got, ",") != "git" {
		t.Fatal(got)
	}
}
