package netcfg

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

var st = commands.NewTestStream("c", &commands.Collector{})

func payload() FirewallPayload {
	return FirewallPayload{SSHPort: 2222, Rules: []Rule{
		{ID: "web", Ports: []string{"80", "443"}, Comment: `public "web"`},
		{ID: "pg", Ports: []string{"5432"}, Sources: []string{"10.0.0.0/8", "fd00::/8", "192.0.2.4"}},
		{ID: "dns", Protocol: "any", Ports: []string{"53"}, Interface: "wg-kiln"},
		{ID: "block", Action: "drop", Protocol: "any", Sources: []string{"198.51.100.0/24"}},
		{ID: "range", Protocol: "udp", Ports: []string{"60000-61000"}},
	}}
}

func TestRenderRuleset(t *testing.T) {
	rs, err := RenderRuleset(payload())
	if err != nil {
		t.Fatal(err)
	}
	for _, w := range []string{
		"table inet kiln {}\ndelete table inet kiln\n",
		"type filter hook input priority filter; policy drop;",
		"ct state established,related accept",
		`iif "lo" accept`,
		"meta l4proto { icmp, ipv6-icmp } accept",
		`tcp dport 2222 accept comment "kiln:ssh"`,
		`tcp dport { 80, 443 } accept comment "kiln:web public web"`,
		`ip saddr { 10.0.0.0/8, 192.0.2.4 } tcp dport 5432 accept comment "kiln:pg"`,
		`ip6 saddr fd00::/8 tcp dport 5432 accept comment "kiln:pg"`,
		`iifname "wg-kiln" meta l4proto { tcp, udp } th dport 53 accept comment "kiln:dns"`,
		`ip saddr 198.51.100.0/24 drop comment "kiln:block"`,
		`udp dport 60000-61000 accept comment "kiln:range"`,
	} {
		if !strings.Contains(rs, w) {
			t.Fatalf("missing %q in\n%s", w, rs)
		}
	}
	rs2, _ := RenderRuleset(payload())
	if rs != rs2 {
		t.Fatal("not deterministic")
	}
	for _, bad := range []Rule{{ID: "x", Ports: []string{"70000"}}, {ID: "x", Ports: []string{"9-3"}}, {ID: "x", Sources: []string{"nope"}}, {ID: "x;y"}, {ID: "x", Interface: `eth0" accept`}} {
		if _, err := RenderRuleset(FirewallPayload{Rules: []Rule{bad}}); !commands.IsPayloadError(err) {
			t.Fatalf("accepted %+v", bad)
		}
	}
}

// Container access opens a port to the Docker bridges only, ahead of the user's rules (a deny rule can't cut it).
func TestRenderRulesetContainerPorts(t *testing.T) {
	p := payload()
	p.Rules = append([]Rule{{ID: "wg-net-interface", Protocol: "any", Interface: "wg-kiln"}}, p.Rules...)
	p.ContainerPorts = []ContainerPorts{{ID: "postgresql", Protocol: "tcp", Ports: []string{"5432"}, Sources: []string{"172.16.0.0/12", "192.168.0.0/16"}, Comment: "PostgreSQL for containers"}}
	rs, err := RenderRuleset(p)
	if err != nil {
		t.Fatal(err)
	}
	docker0 := `iifname "docker0" ip saddr { 172.16.0.0/12, 192.168.0.0/16 } tcp dport 5432 accept comment "kiln:containers-postgresql PostgreSQL for containers"`
	bridges := `iifname "br-*" ip saddr { 172.16.0.0/12, 192.168.0.0/16 } tcp dport 5432 accept comment "kiln:containers-postgresql PostgreSQL for containers"`
	drop := `tcp dport 5432 drop comment "kiln:containers-postgresql-only only containers"`
	for _, w := range []string{docker0, bridges, drop} {
		if !strings.Contains(rs, w) {
			t.Fatalf("missing %q in\n%s", w, rs)
		}
	}
	// Only loopback (accepted first) and containers: the drop precedes the private network and the user's rules
	// (rule "pg" would otherwise open 5432 to 10.0.0.0/8).
	if !(strings.Index(rs, bridges) < strings.Index(rs, drop) && strings.Index(rs, drop) < strings.Index(rs, `"kiln:wg-net-interface"`) && strings.Index(rs, drop) < strings.Index(rs, `"kiln:pg"`)) {
		t.Fatalf("order:\n%s", rs)
	}
	for _, bad := range []ContainerPorts{{ID: "x", Sources: []string{"172.16.0.0/12"}}, {ID: "x;y", Ports: []string{"5432"}, Sources: []string{"172.16.0.0/12"}}, {ID: "x", Ports: []string{"0"}, Sources: []string{"172.16.0.0/12"}}, {ID: "x", Ports: []string{"5432"}}, {ID: "x", Ports: []string{"5432"}, Sources: []string{"nope"}}} {
		if _, err := RenderRuleset(FirewallPayload{ContainerPorts: []ContainerPorts{bad}}); !commands.IsPayloadError(err) {
			t.Fatalf("accepted %+v", bad)
		}
	}
}

// A Redis instance used over a private network: its consumers' addresses are accepted (any interface) besides the
// containers, then the port is dropped for everyone else — the private network's accept-all rule included.
func TestRenderRulesetContainerPortsWithPeers(t *testing.T) {
	p := payload()
	p.Rules = append([]Rule{{ID: "wg-net-interface", Protocol: "any", Interface: "wg-kiln"}}, p.Rules...)
	p.ContainerPorts = []ContainerPorts{
		{ID: "redis-cache", Protocol: "tcp", Ports: []string{"6380"}, Sources: []string{"172.16.0.0/12"}, Peers: []string{"10.90.0.2", "10.0.1.7"}, Comment: "Redis cache"},
		// Only peers (container access off: no Docker ranges configured).
		{ID: "redis-jobs", Protocol: "tcp", Ports: []string{"6381"}, Peers: []string{"10.90.0.4"}, Comment: "Redis jobs"},
	}
	rs, err := RenderRuleset(p)
	if err != nil {
		t.Fatal(err)
	}
	bridge := `iifname "br-*" ip saddr 172.16.0.0/12 tcp dport 6380 accept comment "kiln:containers-redis-cache Redis cache"`
	peers := `ip saddr { 10.90.0.2, 10.0.1.7 } tcp dport 6380 accept comment "kiln:containers-redis-cache-peers Redis cache"`
	drop := `tcp dport 6380 drop comment "kiln:containers-redis-cache-only only containers"`
	for _, w := range []string{bridge, peers, drop, `ip saddr 10.90.0.4 tcp dport 6381 accept`, `tcp dport 6381 drop`} {
		if !strings.Contains(rs, w) {
			t.Fatalf("missing %q in\n%s", w, rs)
		}
	}
	if strings.Contains(rs, `tcp dport 6381 accept comment "kiln:containers-redis-jobs Redis jobs"`) {
		t.Fatalf("bridge rule without sources:\n%s", rs)
	}
	if !(strings.Index(rs, peers) < strings.Index(rs, drop) && strings.Index(rs, drop) < strings.Index(rs, `"kiln:wg-net-interface"`)) {
		t.Fatalf("order:\n%s", rs)
	}
	for _, bad := range []ContainerPorts{{ID: "x", Ports: []string{"6380"}, Peers: []string{"nope"}}, {ID: "x", Ports: []string{"6380"}, Peers: []string{}}} {
		if _, err := RenderRuleset(FirewallPayload{ContainerPorts: []ContainerPorts{bad}}); !commands.IsPayloadError(err) {
			t.Fatalf("accepted %+v", bad)
		}
	}
}

func TestFirewallApply(t *testing.T) {
	root := t.TempDir()
	applied := false
	f := &runnertest.Fake{}
	f.OnFunc("nft list table inet kiln", func(runnertest.Call) (runner.Result, error) {
		if applied {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 1}, nil
	})
	f.OnFunc("nft -f", func(runnertest.Call) (runner.Result, error) { applied = true; return runner.Result{}, nil })
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	r, err := n.FirewallApply(context.Background(), payload(), st)
	if err != nil || !r.(FirewallResult).Changed {
		t.Fatal(r, err)
	}
	conf := filepath.Join(root, "etc/kiln/nftables.conf")
	want := []string{"nft list table inet kiln", "nft -c -f " + conf + ".new", "nft -f " + conf, "systemctl daemon-reload", "systemctl enable kiln-firewall.service"}
	if strings.Join(f.Lines(), "|") != strings.Join(want, "|") {
		t.Fatalf("%v", f.Lines())
	}
	f.Reset()
	r, _ = n.FirewallApply(context.Background(), payload(), st)
	if r.(FirewallResult).Changed || len(f.Lines()) != 1 {
		t.Fatal("not idempotent", f.Lines())
	}
	// table flushed out-of-band (e.g. reboot without unit) → re-applied even though file matches
	applied = false
	r, _ = n.FirewallApply(context.Background(), payload(), st)
	if !r.(FirewallResult).Changed {
		t.Fatal("did not re-apply missing table")
	}
}

func TestFirewallValidationFailureKeepsOldFile(t *testing.T) {
	root := t.TempDir()
	f := (&runnertest.Fake{}).On("nft -c", runner.Result{ExitCode: 1, Stderr: []byte("Error: syntax error")})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	os.MkdirAll(filepath.Join(root, "etc/kiln"), 0o755)
	os.WriteFile(filepath.Join(root, "etc/kiln/nftables.conf"), []byte("old"), 0o600)
	if _, err := n.FirewallApply(context.Background(), payload(), st); err == nil || !strings.Contains(err.Error(), "untouched") {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, "etc/kiln/nftables.conf")); string(b) != "old" {
		t.Fatal("old ruleset replaced")
	}
	if f.Ran("nft -f") {
		t.Fatal("applied invalid ruleset")
	}
	if _, err := os.Stat(filepath.Join(root, "etc/kiln/nftables.conf.new")); err == nil {
		t.Fatal("tmp left")
	}
}

func TestWireGuard(t *testing.T) {
	root := t.TempDir()
	active := false
	f := &runnertest.Fake{}
	f.OnFunc("systemctl is-active", func(runnertest.Call) (runner.Result, error) {
		if active {
			return runner.Result{}, nil
		}
		return runner.Result{ExitCode: 3}, nil
	})
	f.OnFunc("systemctl enable --now", func(runnertest.Call) (runner.Result, error) { active = true; return runner.Result{}, nil })
	f.On("wg-quick strip", runner.Result{Stdout: []byte("[Interface]\nPrivateKey = x\n")})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	os.MkdirAll(filepath.Join(root, "usr/bin"), 0o755)
	os.WriteFile(filepath.Join(root, "usr/bin/wg-quick"), []byte("#!/bin/bash\n"), 0o755)
	peerKey := base64.StdEncoding.EncodeToString(make([]byte, 32))
	p := WireGuardPayload{Address: "10.90.0.3/24", Peers: []Peer{{PublicKey: peerKey, Endpoint: "203.0.113.1:51820", AllowedIPs: []string{"10.90.0.1/32"}, PersistentKeepalive: 25}}}
	r, err := n.WireGuardApply(context.Background(), p, st)
	if err != nil {
		t.Fatal(err)
	}
	res := r.(WireGuardResult)
	if !res.Changed || len(res.PublicKey) != 44 || !f.Ran("systemctl enable --now wg-quick@wg-kiln") {
		t.Fatal(res, f.Lines())
	}
	keyFile := filepath.Join(root, "etc/kiln/wireguard/wg-kiln.key")
	if fi, _ := os.Stat(keyFile); fi.Mode().Perm() != 0o600 {
		t.Fatal("key mode")
	}
	conf, _ := os.ReadFile(filepath.Join(root, "etc/wireguard/wg-kiln.conf"))
	if !strings.Contains(string(conf), "Endpoint = 203.0.113.1:51820") || !strings.Contains(string(conf), "PersistentKeepalive = 25") {
		t.Fatal(string(conf))
	}
	// key persists and derives the same public key
	f.Reset()
	r, _ = n.WireGuardApply(context.Background(), p, st)
	if r.(WireGuardResult).Changed || r.(WireGuardResult).PublicKey != res.PublicKey || len(f.Lines()) != 1 {
		t.Fatal("not idempotent", r, f.Lines())
	}
	// peer change → live syncconf with stripped config on stdin
	p.Peers[0].PersistentKeepalive = 15
	f.Reset()
	r, _ = n.WireGuardApply(context.Background(), p, st)
	calls := f.Calls()
	last := calls[len(calls)-1]
	if !r.(WireGuardResult).Changed || last.Line != "wg syncconf wg-kiln /dev/stdin" || !strings.Contains(last.Stdin, "[Interface]") {
		t.Fatal(f.Lines())
	}
	// address change → restart
	p.Address = "10.90.0.4/24"
	f.Reset()
	n.WireGuardApply(context.Background(), p, st)
	if !f.Ran("systemctl restart wg-quick@wg-kiln") {
		t.Fatal(f.Lines())
	}
	p.State = "absent"
	r, _ = n.WireGuardApply(context.Background(), p, st)
	if !r.(WireGuardResult).Changed || !f.Ran("systemctl disable --now wg-quick@wg-kiln") {
		t.Fatal("absent")
	}
	if _, err := os.Stat(keyFile); err != nil {
		t.Fatal("key should be kept for identity stability")
	}
}

// A fresh Ubuntu server has no wireguard-tools (provisioning doesn't install them): the first apply installs them
// (apt-get update first) before it enables wg-quick@<interface>; once installed nothing is installed again.
func TestWireGuardInstallsTheToolsWhenMissing(t *testing.T) {
	root := t.TempDir()
	f := &runnertest.Fake{}
	f.On("systemctl is-active", runner.Result{ExitCode: 3})
	f.OnFunc("apt-get install", func(runnertest.Call) (runner.Result, error) {
		os.MkdirAll(filepath.Join(root, "usr/bin"), 0o755)
		os.WriteFile(filepath.Join(root, "usr/bin/wg-quick"), []byte("#!/bin/bash\n"), 0o755)
		return runner.Result{}, nil
	})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	p := WireGuardPayload{Interface: "wg-a1b2c3d4", Address: "10.90.0.3/24"}
	if _, err := n.WireGuardApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	lines := f.Lines()
	idx := func(prefix string) int {
		return slices.IndexFunc(lines, func(l string) bool { return strings.HasPrefix(l, prefix) })
	}
	inst, upd, enable := idx("apt-get install"), idx("apt-get update"), idx("systemctl enable --now wg-quick@wg-a1b2c3d4")
	if upd < 0 || inst < upd || enable < inst || !strings.Contains(lines[inst], "wireguard-tools") {
		t.Fatal(lines)
	}
	f.Reset()
	if _, err := n.WireGuardApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	if idx := slices.IndexFunc(f.Lines(), func(l string) bool { return strings.HasPrefix(l, "apt-get") || strings.HasPrefix(l, "dpkg-query") }); idx >= 0 {
		t.Fatal(f.Lines())
	}

	// The install failing fails the command before anything is written.
	root2 := t.TempDir()
	f2 := &runnertest.Fake{}
	f2.On("apt-get install", runner.Result{ExitCode: 100, Stderr: []byte("E: Unable to locate package wireguard-tools")})
	n2 := New(Deps{Runner: f2, FS: hostfs.FS{Root: root2}})
	if _, err := n2.WireGuardApply(context.Background(), p, st); err == nil || !strings.Contains(err.Error(), "install wireguard-tools") {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root2, "etc/wireguard/wg-a1b2c3d4.conf")); err == nil {
		t.Fatal("config written although the tools are missing")
	}
}

func TestPublicKeyMatchesWG(t *testing.T) {
	// Known vector (RFC 7748 §6.1 Alice).
	priv, _ := base64.StdEncoding.DecodeString("dwdtCnMYpX08FsFyUbJmRd9ML4frwJkqsXf7pR25LCo=")
	pub, err := PublicKey(priv)
	if err != nil || pub != "hSDwCYkwp1R0i33ctD73Wg2/Og0mOBr066SpjqqbTmo=" {
		t.Fatal(pub, err)
	}
	k, _ := GenerateKey()
	if k[0]&7 != 0 || k[31]&128 != 0 || k[31]&64 == 0 {
		t.Fatal("not clamped")
	}
}

func TestTunnelApplyInstallsRunsAndRemoves(t *testing.T) {
	bin := []byte("#!/bin/sh\necho cloudflared\n")
	sum := sha256.Sum256(bin)
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write(bin) }))
	defer srv.Close()
	root := t.TempDir()
	f := &runnertest.Fake{}
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}, HTTP: srv.Client()})
	p := TunnelPayload{State: "present", Version: "2026.9.3", URL: srv.URL + "/cloudflared-linux-amd64", SHA256: hex.EncodeToString(sum[:]), Token: "tok-123"}

	r, err := n.TunnelApply(context.Background(), p, st)
	if err != nil || !r.(TunnelResult).Changed || !r.(TunnelResult).Active {
		t.Fatal(r, err)
	}
	token, _ := os.ReadFile(filepath.Join(root, TunnelTokenPath))
	info, _ := os.Stat(filepath.Join(root, TunnelTokenPath))
	unit, _ := os.ReadFile(filepath.Join(root, TunnelUnitPath))
	if string(token) != "tok-123" || info.Mode().Perm() != 0o600 || !strings.Contains(string(unit), "LoadCredential=token:"+TunnelTokenPath) || !strings.Contains(string(unit), "DynamicUser=yes") {
		t.Fatalf("token %q mode %v unit %s", token, info.Mode(), unit)
	}
	if want := "systemctl daemon-reload|systemctl enable kiln-cloudflared.service|systemctl restart kiln-cloudflared.service|systemctl is-active --quiet kiln-cloudflared.service"; strings.Join(f.Lines(), "|") != want {
		t.Fatalf("%v", f.Lines())
	}

	// Same payload: nothing downloaded or restarted.
	f.Reset()
	r, _ = n.TunnelApply(context.Background(), p, st)
	if r.(TunnelResult).Changed || strings.Contains(strings.Join(f.Lines(), "|"), "restart") {
		t.Fatal("not idempotent", f.Lines())
	}

	// A wrong checksum is refused before anything is installed.
	if _, err := New(Deps{Runner: f, FS: hostfs.FS{Root: t.TempDir()}, HTTP: srv.Client()}).TunnelApply(context.Background(), TunnelPayload{State: "present", Version: p.Version, URL: p.URL, SHA256: strings.Repeat("0", 64), Token: "t"}, st); err == nil {
		t.Fatal("checksum mismatch accepted")
	}

	r, _ = n.TunnelApply(context.Background(), TunnelPayload{State: "absent"}, st)
	if !r.(TunnelResult).Changed {
		t.Fatal("absent did not remove")
	}
	for _, p := range []string{TunnelUnitPath, TunnelTokenPath, CloudflaredBinary} {
		if _, err := os.Stat(filepath.Join(root, p)); err == nil {
			t.Errorf("%s left behind", p)
		}
	}
}

// Peers are accepted on the interface they arrive on: a Kiln WireGuard network's (from its config, even while it is
// down), the provider NIC whose subnet holds them, or the one the kernel routes them through; else on any interface.
func TestFirewallApplyRestrictsPeersToTheirInterface(t *testing.T) {
	root := t.TempDir()
	os.MkdirAll(filepath.Join(root, "etc/wireguard"), 0o700)
	os.WriteFile(filepath.Join(root, "etc/wireguard/wg-a1b2c3d4.conf"), []byte("# Managed by Kiln (net.wireguard.apply) — do not edit\n[Interface]\nPrivateKey = x\nAddress = 10.90.0.1/24\nListenPort = 51820\n"), 0o600)
	os.WriteFile(filepath.Join(root, "etc/wireguard/wg0.conf"), []byte("[Interface]\nAddress = 10.91.0.1/24\n"), 0o600)
	old := localNets
	localNets = func() ([]localNet, error) {
		parse := func(iface, cidr string) localNet {
			ip, n, _ := net.ParseCIDR(cidr)
			n.IP = ip
			return localNet{iface: iface, net: n}
		}
		return []localNet{parse("lo", "127.0.0.1/8"), parse("eth0", "203.0.113.5/24"), parse("eth1", "10.114.0.2/20"), parse("docker0", "172.17.0.1/16"), parse("enp7s0", "10.0.0.2/32")}, nil
	}
	t.Cleanup(func() { localNets = old })
	f := &runnertest.Fake{}
	f.On("ip -o route get 10.0.1.7", runner.Result{Stdout: []byte("10.0.1.7 via 10.0.0.1 dev enp7s0 src 10.0.0.2 uid 0 \\    cache \n")})
	f.On("ip -o route get 172.17.0.9", runner.Result{Stdout: []byte("172.17.0.9 dev docker0 src 172.17.0.1 uid 0 \\    cache \n")})
	f.On("ip -o route get", runner.Result{ExitCode: 2})
	n := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	p := payload()
	p.ContainerPorts = []ContainerPorts{{ID: "redis-cache", Protocol: "tcp", Ports: []string{"6380"},
		Peers: []string{"10.90.0.2", "10.114.0.3", "10.0.1.7", "10.91.0.2", "172.17.0.9", "10.90.0.5"}, Comment: "Redis cache"}}
	if _, err := n.FirewallApply(context.Background(), p, st); err != nil {
		t.Fatal(err)
	}
	rs, _ := os.ReadFile(filepath.Join(root, RulesetPath))
	for _, w := range []string{
		`iifname "wg-a1b2c3d4" ip saddr { 10.90.0.2, 10.90.0.5 } tcp dport 6380 accept comment "kiln:containers-redis-cache-peers Redis cache"`,
		`iifname "eth1" ip saddr 10.114.0.3 tcp dport 6380 accept`,
		`iifname "enp7s0" ip saddr 10.0.1.7 tcp dport 6380 accept`,
		// Not Kiln's WireGuard, no route, or a container bridge: any interface, as before.
		"\t\tip saddr { 10.91.0.2, 172.17.0.9 } tcp dport 6380 accept",
	} {
		if !strings.Contains(string(rs), w) {
			t.Fatalf("missing %q in\n%s", w, rs)
		}
	}
	if strings.Index(string(rs), `kiln:containers-redis-cache-peers`) > strings.Index(string(rs), `kiln:containers-redis-cache-only`) {
		t.Fatalf("peers after the drop:\n%s", rs)
	}
}
