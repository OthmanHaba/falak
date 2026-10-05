package netcfg

import (
	"net"
	"os"
	"strings"
)

// Which interface a container_ports peer (another server using a Redis instance over a private network) arrives on, so
// its accept rule names it (iifname: it matches by name, also before the interface exists). The control plane names a
// Kiln WireGuard peer's interface (peer_interfaces); for the others the agent looks at:
//
//  1. a Kiln WireGuard network whose address range (/etc/wireguard/<interface>.conf, Address) contains the peer;
//  2. a local interface whose subnet contains it (the provider's private NIC, DigitalOcean's eth1 or Lightsail's eth0),
//     container bridges and veths excluded.
//
// None of them: the peer is accepted on any interface. The route (`ip route get`) is not asked: a private address with
// no specific route resolves through the default route, which would pin a WireGuard peer to the public NIC before its
// interface exists.

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

// notPeerInterface: loopback and container interfaces never carry another server's traffic.
func notPeerInterface(name string) bool {
	return name == "lo" || strings.HasPrefix(name, "docker") || strings.HasPrefix(name, "br-") || strings.HasPrefix(name, "veth")
}

func (n *Net) peerInterfaces(ports []ContainerPorts) map[string]string {
	var peers []string
	for _, c := range ports {
		for _, peer := range c.Peers {
			if _, named := c.PeerInterfaces[peer]; !named {
				peers = append(peers, peer)
			}
		}
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
