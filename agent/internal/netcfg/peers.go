package netcfg

import (
	"context"
	"net"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/kiln/agent/internal/runner"
)

// Which interface a container_ports peer (another server using a Redis instance over a private network) arrives on, so
// its accept rule names it (iifname: it matches by name, also before the interface exists):
//
//  1. a Kiln WireGuard network whose address range (/etc/wireguard/<interface>.conf, Address) contains the peer — known
//     even while the interface is down;
//  2. a local interface whose subnet contains it (the provider's private NIC), container bridges and veths excluded;
//  3. the interface the kernel routes it through (`ip -o route get`: a provider private network reached through a
//     gateway, such as Hetzner's /32 addresses).
//
// None of them: the peer is accepted on any interface, as before.

// localNet is one interface address with its subnet.
type localNet struct {
	iface string
	net   *net.IPNet
}

// localNets lists the addresses of the interfaces that are up; overridable in tests.
var localNets = func() ([]localNet, error) {
	ifs, err := net.Interfaces()
	if err != nil {
		return nil, err
	}
	var out []localNet
	for _, i := range ifs {
		if i.Flags&net.FlagUp == 0 {
			continue
		}
		addrs, err := i.Addrs()
		if err != nil {
			continue
		}
		for _, a := range addrs {
			if n, ok := a.(*net.IPNet); ok {
				out = append(out, localNet{iface: i.Name, net: n})
			}
		}
	}
	return out, nil
}

var routeDevRe = regexp.MustCompile(`\bdev (\S+)`)

// notPeerInterface: loopback and container interfaces never carry another server's traffic.
func notPeerInterface(name string) bool {
	return name == "lo" || strings.HasPrefix(name, "docker") || strings.HasPrefix(name, "br-") || strings.HasPrefix(name, "veth")
}

func (n *Net) peerInterfaces(ctx context.Context, ports []ContainerPorts) map[string]string {
	var peers []string
	for _, c := range ports {
		peers = append(peers, c.Peers...)
	}
	if len(peers) == 0 {
		return nil
	}
	var wg []localNet
	if entries, err := os.ReadDir(n.d.FS.P("/etc/wireguard")); err == nil {
		for _, e := range entries {
			iface, ok := strings.CutSuffix(e.Name(), ".conf")
			if !ok || !wgIfRe.MatchString(iface) {
				continue
			}
			b, err := n.d.FS.ReadFile("/etc/wireguard/" + e.Name())
			if err != nil || !strings.HasPrefix(string(b), "# Managed by Kiln (net.wireguard.apply)") {
				continue
			}
			for _, a := range strings.Split(confLine(string(b), "Address"), ",") {
				if _, ipn, err := net.ParseCIDR(strings.TrimSpace(a)); err == nil {
					wg = append(wg, localNet{iface: iface, net: ipn})
				}
			}
		}
	}
	local, _ := localNets()
	out := map[string]string{}
	for _, peer := range peers {
		ip := net.ParseIP(peer)
		if ip == nil {
			continue // RenderRuleset refuses it
		}
		if iface := containing(wg, ip); iface != "" {
			out[peer] = iface
			continue
		}
		if iface := containing(local, ip); iface != "" && !notPeerInterface(iface) {
			out[peer] = iface
			continue
		}
		cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		res, err := n.d.Runner.Run(cctx, runner.Cmd{Name: "ip", Args: []string{"-o", "route", "get", ip.String()}})
		cancel()
		if err == nil && res.ExitCode == 0 {
			if m := routeDevRe.FindStringSubmatch(string(res.Stdout)); m != nil && ifaceRe.MatchString(m[1]) && !notPeerInterface(m[1]) {
				out[peer] = m[1]
			}
		}
	}
	return out
}

// containing names the interface of the most specific subnet containing ip ("" when none does).
func containing(nets []localNet, ip net.IP) string {
	best, bestOnes := "", -1
	for _, n := range nets {
		if ones, _ := n.net.Mask.Size(); n.net.Contains(ip) && ones > bestOnes && !n.net.IP.Equal(ip) {
			best, bestOnes = n.iface, ones
		}
	}
	return best
}
