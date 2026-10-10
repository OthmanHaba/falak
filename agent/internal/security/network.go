package security

import (
	"context"
	"fmt"
	"net"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// FirewallPolicy reads the input policy of table inet falak from `nft list table inet falak`; "" when the table or its
// input chain is missing.
func FirewallPolicy(out string) string {
	for _, line := range strings.Split(out, "\n") {
		if !strings.Contains(line, "hook input") {
			continue
		}
		if m := policyRe.FindStringSubmatch(line); m != nil {
			return m[1]
		}
	}
	return ""
}

var policyRe = regexp.MustCompile(`policy (\w+);`)

// firewallDropping reports the falak table's input policy ("" when the table is not loaded).
func (s *Security) firewallPolicy(ctx context.Context) (string, bool) {
	out, ok := s.output(ctx, "nft", "list", "table", "inet", "falak")
	if !ok {
		return "", false
	}
	return FirewallPolicy(out), true
}

// PortRange is one expected port range ("tcp/8000-8100"); protocol "any" matches both.
type PortRange struct {
	Proto    string
	From, To int
}

// ParseExpected parses the control plane's expected ports ("tcp/443", "udp/51820", "tcp/8000-8100", "any/*").
func ParseExpected(items []string) []PortRange {
	var rs []PortRange
	for _, it := range items {
		proto, ports, ok := strings.Cut(it, "/")
		if !ok {
			continue
		}
		if ports == "*" {
			rs = append(rs, PortRange{proto, 1, 65535})
			continue
		}
		a, b, isRange := strings.Cut(ports, "-")
		from, err1 := strconv.Atoi(a)
		to := from
		var err2 error
		if isRange {
			to, err2 = strconv.Atoi(b)
		}
		if err1 == nil && err2 == nil && from >= 1 && to <= 65535 && from <= to {
			rs = append(rs, PortRange{proto, from, to})
		}
	}
	return rs
}

func expected(rs []PortRange, proto string, port int) bool {
	for _, r := range rs {
		if (r.Proto == proto || r.Proto == "any") && port >= r.From && port <= r.To {
			return true
		}
	}
	return false
}

var cgnat = &net.IPNet{IP: net.IPv4(100, 64, 0, 0), Mask: net.CIDRMask(10, 32)}

// Public reports whether a listening address is reachable from outside: a wildcard or a global address (loopback,
// RFC 1918 / ULA, CGNAT and link-local addresses are not).
func Public(addr string) bool {
	if addr == "*" || addr == "" {
		return true
	}
	ip := net.ParseIP(addr)
	if ip == nil {
		return false
	}
	if ip.IsUnspecified() {
		return true
	}
	return !(ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast() || cgnat.Contains(ip))
}

// Listener is one listening socket of `ss -H -l{t,u}np`.
type Listener struct {
	Proto   string
	Address string
	Port    int
	Process string
}

var ssUsers = regexp.MustCompile(`\(\("([^"]+)",pid=\d+`)

// ParseSS parses `ss -H -ltnp` / `ss -H -lunp`, one entry per address and port.
func ParseSS(proto, out string) []Listener {
	seen := map[string]bool{}
	var ls []Listener
	for _, line := range strings.Split(out, "\n") {
		f := strings.Fields(line)
		if len(f) < 5 {
			continue
		}
		local := f[3]
		i := strings.LastIndex(local, ":")
		if i < 0 {
			continue
		}
		port, err := strconv.Atoi(local[i+1:])
		if err != nil {
			continue
		}
		addr := strings.Trim(local[:i], "[]")
		if j := strings.Index(addr, "%"); j >= 0 {
			addr = addr[:j]
		}
		if seen[addr+"|"+strconv.Itoa(port)] {
			continue
		}
		seen[addr+"|"+strconv.Itoa(port)] = true
		l := Listener{Proto: proto, Address: addr, Port: port}
		if m := ssUsers.FindStringSubmatch(line); m != nil {
			l.Process = m[1]
		}
		ls = append(ls, l)
	}
	return ls
}

// containerProxies publish container ports; those are checked against Docker (they bypass the input chain).
var containerProxies = map[string]bool{"docker-proxy": true, "rootlessport": true, "rootlesskit": true, "conmon": true, "slirp4netns": true, "pasta": true}

// clientPorts are UDP ports DHCP clients listen on.
var clientPorts = map[int]bool{68: true, 546: true}

func (s *Security) firewallChecks(ctx context.Context, p AuditPayload) []Check {
	var cs []Check
	c := Check{ID: "firewall.default_deny", Title: "The firewall drops what it doesn't allow", Area: "firewall", Status: Pass, Severity: High,
		Evidence: "table inet falak: input policy drop"}
	policy, loaded := s.firewallPolicy(ctx)
	dropping := policy == "drop"
	switch {
	case !loaded:
		c.Status, c.FixID, c.Evidence = Fail, "firewall.apply", "table inet falak is not loaded"
	case !dropping:
		c.Status, c.FixID, c.Evidence = Fail, "firewall.apply", "table inet falak: input policy "+orDash(policy)
	}
	cs = append(cs, c)

	if p.ExpectedPorts == nil {
		return append(cs, Check{ID: "firewall.ports", Title: "Only expected ports listen publicly", Area: "firewall", Status: Info, Severity: SevInfo,
			Evidence: "listeners were not compared"})
	}
	exp := ParseExpected(p.ExpectedPorts)
	if p.SSHPort > 0 {
		exp = append(exp, PortRange{"tcp", p.SSHPort, p.SSHPort})
	}
	var ls []Listener
	for _, proto := range []string{"tcp", "udp"} {
		if out, ok := s.output(ctx, "ss", "-H", "-l"+proto[:1]+"np"); ok {
			ls = append(ls, ParseSS(proto, out)...)
		}
	}
	type key struct {
		proto string
		port  int
	}
	unexpected := map[key][]string{}
	for _, l := range ls {
		if !Public(l.Address) || containerProxies[l.Process] || (l.Proto == "udp" && clientPorts[l.Port]) || expected(exp, l.Proto, l.Port) {
			continue
		}
		k := key{l.Proto, l.Port}
		if l.Process != "" && !contains(unexpected[k], l.Process) {
			unexpected[k] = append(unexpected[k], l.Process)
		} else if _, ok := unexpected[k]; !ok {
			unexpected[k] = nil
		}
	}
	keys := make([]key, 0, len(unexpected))
	for k := range unexpected {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool {
		if keys[i].port != keys[j].port {
			return keys[i].port < keys[j].port
		}
		return keys[i].proto < keys[j].proto
	})
	for _, k := range keys {
		who := "unknown process"
		if len(unexpected[k]) > 0 {
			who = strings.Join(unexpected[k], ", ")
		}
		f := Check{ID: fmt.Sprintf("firewall.port.%s.%d", k.proto, k.port), Title: fmt.Sprintf("Port %s/%d listens on a public interface", k.proto, k.port),
			Area: "firewall", Status: Fail, Severity: High, FixID: fmt.Sprintf("firewall.close_port:%s:%d", k.proto, k.port),
			Evidence: who + " listens on all interfaces; the firewall does not allow it and nothing at Falak expects it"}
		if dropping {
			// Not reachable while the default-deny input policy holds; a deny rule keeps it closed for good.
			f.Status, f.Severity = Warn, Low
			f.Evidence = who + " listens on all interfaces (blocked by the firewall's default deny)"
		}
		cs = append(cs, f)
	}
	if len(keys) == 0 {
		cs = append(cs, Check{ID: "firewall.ports", Title: "Only expected ports listen publicly", Area: "firewall", Status: Pass, Severity: Medium,
			Evidence: plural(len(ls), "listening socket", "listening sockets") + " checked"})
	}
	return cs
}

func orDash(s string) string {
	if s == "" {
		return "-"
	}
	return s
}

func contains(xs []string, x string) bool {
	for _, y := range xs {
		if y == x {
			return true
		}
	}
	return false
}
