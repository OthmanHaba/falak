package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/provision/inspect"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
	"github.com/kiln/agent/internal/system"
)

// provision.inspect reports validate against $defs.result: a bare host where every detector degrades, and a full one.
func TestInspectReportsValidate(t *testing.T) {
	sch, err := compiler(t).Compile(idBase + "commands/provision.inspect.schema.json#/$defs/result")
	if err != nil {
		t.Fatal(err)
	}
	bare, err := inspect.New(inspect.Deps{Runner: (&runnertest.Fake{}).On("dpkg-query", runner.Result{ExitCode: 2}), FS: hostfs.FS{Root: t.TempDir()}}).
		Inspect(context.Background(), inspect.Payload{}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil {
		t.Fatal(err)
	}
	no, yes := false, true
	full := &inspect.Report{
		Version: inspect.ReportVersion, Hostname: "vm", OS: inspect.OS{ID: "ubuntu", Version: "26.04", Codename: "resolute"},
		Packages:   []inspect.Package{{Name: "docker-ce", Version: "5:28.1.1-1", Origin: inspect.OriginVendor, Repo: "https://download.docker.com/linux/ubuntu", Label: "Docker"}, {Name: "x", Version: "1", Origin: inspect.OriginManual}},
		Snaps:      []inspect.Snap{{Name: "lxd", Version: "5.21", Channel: "5.21/stable"}},
		AptSources: []system.AptSource{{File: "/etc/apt/sources.list.d/docker.list", URIs: []string{"https://download.docker.com/linux/ubuntu"}}},
		Services:   []inspect.Service{{Unit: "nginx.service", Active: "active", Enabled: "enabled"}},
		Listeners:  []inspect.Listener{{Port: 80, Address: "0.0.0.0", Process: "nginx", PID: 12, Unit: "nginx.service"}, {Port: 6379, Address: "0.0.0.0", Process: "docker-proxy", Container: true}},
		Containers: []inspect.Container{{Name: "cache", Image: "redis:7", Ports: []inspect.PublishedPort{{HostIP: "0.0.0.0", HostPort: 6379, ContainerPort: 6379, Protocol: "tcp"}}}},
		Docker: &inspect.Docker{EnginePackage: "docker-ce", ClientVersion: "28.1.1", ServerVersion: "28.1.1", Compose: &inspect.Plugin{Version: "2.35.1", Package: "docker-compose-plugin", Path: "/usr/libexec/docker/cli-plugins/docker-compose"},
			SystemDaemon: true, Daemon: &inspect.DaemonConfig{BIP: "172.26.0.1/16", DefaultAddressPools: []json.RawMessage{json.RawMessage(`{"base":"10.200.0.0/16","size":24}`)}, IPTables: &no, UsernsRemap: "default"}},
		SSH: inspect.SSH{DropIns: []inspect.SSHDropIn{{File: "/etc/ssh/sshd_config.d/50-cloud-init.conf", Settings: map[string]string{"passwordauthentication": "yes"}}},
			Effective: map[string]string{"passwordauthentication": "yes", "port": "22"}, EffectiveSource: "sshd -T", Users: []inspect.LoginUser{{Name: "root", UID: 0, AuthorizedKeys: 1}}},
		Firewall:           inspect.Firewall{UFW: "active", Firewalld: "absent", Tables: []string{"ip filter"}},
		Swap:               []inspect.Swap{{Name: "/swap.img", Type: "file", SizeBytes: 1 << 30}},
		Node:               []inspect.Binary{{Path: "/usr/bin/node", Version: "20.1.0", Source: "nodesource", Package: "nodejs", Repo: "https://deb.nodesource.com/node_20.x"}},
		PHP:                []inspect.Binary{{Path: "/usr/bin/php8.3", Version: "8.3", Source: inspect.OriginArchive, Package: "php8.3-cli"}},
		FrankenPHP:         []inspect.Binary{{Path: "/usr/local/bin/frankenphp", Version: "1.9.1", Source: "kiln"}},
		UnattendedUpgrades: inspect.Unattended{Installed: true, Periodic: map[string]string{"Unattended-Upgrade": "1"}},
		Fail2ban:           inspect.Fail2ban{Installed: true, Active: yes, Jails: []string{"/etc/fail2ban/jail.local"}},
		Errors:             []inspect.DetectorError{{Detector: "swap", Error: "open /proc/swaps: no such file"}},
	}
	for name, r := range map[string]any{"bare": bare, "full": full} {
		b, _ := json.Marshal(r)
		v, _ := jsonschema.UnmarshalJSON(bytes.NewReader(b))
		if err := sch.Validate(v); err != nil {
			t.Errorf("%s report invalid: %v\n%s", name, err, b)
		}
	}
}
