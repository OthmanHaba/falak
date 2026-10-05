package db

import (
	"fmt"
	"net"
	"slices"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/facts"
)

// Network access of Redis / Valkey instances (feature db.redis.network).
//
// An instance always listens on 127.0.0.1. The control plane adds the server's private addresses that other servers
// of the project reach it on (its WireGuard address, or its provider private IPv4), and `containers` asks for the
// Docker default bridge's address (docker0, 172.17.0.1 out of the box): every container on the server reaches it
// through its own network's gateway, whatever bridge network it is on (packets to a local address are delivered by
// the host's input path). Who may connect is the firewall's decision (net.firewall.apply container_ports).
//
// An instance never listens on a public address: an address is accepted only when it is loopback, private (RFC 1918,
// CGNAT 100.64.0.0/10, IPv6 ULA fc00::/7) or assigned to a WireGuard interface (a private network whose range is
// not one of those). 0.0.0.0 / :: and anything else is refused. A valid address the host does not have (yet) is left
// out and reported (`skipped`): Redis refuses to start when it can't bind one, and the control plane applies again
// when the address appears (a private network converged, Docker installed).

// redisInterfaces lists the host's interfaces that are up; overridable in tests.
var redisInterfaces = facts.Interfaces

// dockerBridge is the interface whose address containers reach the host on.
const dockerBridge = "docker0"

var redisPrivateNets = func() []*net.IPNet {
	var out []*net.IPNet
	for _, c := range []string{"10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10", "fc00::/7"} {
		_, n, _ := net.ParseCIDR(c)
		out = append(out, n)
	}
	return out
}()

// redisPrivateAddr reports whether ip is in a private range an instance may listen on.
func redisPrivateAddr(ip net.IP) bool {
	for _, n := range redisPrivateNets {
		if n.Contains(ip) {
			return true
		}
	}
	return false
}

// redisBind is the bind list an apply converges to.
type redisBind struct {
	addrs         []string // 127.0.0.1 first
	containerHost string   // docker0's address when `containers` and the bridge exists
	skipped       []string // valid addresses the host doesn't have
}

// resolveBind checks the wanted addresses against the host's interfaces. A public address is a payload error.
func (db *DB) resolveBind(requested []string, containers bool) (redisBind, error) {
	out := redisBind{addrs: []string{"127.0.0.1"}}
	ifs, err := redisInterfaces()
	if err != nil {
		return out, fmt.Errorf("list network interfaces: %w", err)
	}
	owner := map[string]string{} // address → interface
	for _, it := range ifs {
		for _, a := range it.Addrs {
			if _, ok := owner[a.String()]; !ok {
				owner[a.String()] = it.Name
			}
		}
	}
	add := func(s string) {
		if !slices.Contains(out.addrs, s) {
			out.addrs = append(out.addrs, s)
		}
	}
	for _, a := range requested {
		ip := net.ParseIP(a)
		if ip == nil || ip.IsUnspecified() {
			return out, &commands.PayloadError{Err: fmt.Errorf("invalid bind address %q", a)}
		}
		s := ip.String()
		iface, present := owner[s]
		if ip.IsLoopback() {
			if ip.To4() != nil || present {
				add(s)
			} else {
				out.skipped = append(out.skipped, s)
			}
			continue
		}
		if !redisPrivateAddr(ip) && !(present && db.isWireGuard(iface)) {
			return out, &commands.PayloadError{Err: fmt.Errorf("refusing to listen on %s: not a private address (RFC 1918, 100.64.0.0/10, fc00::/7) or a WireGuard interface's", s)}
		}
		if !present {
			out.skipped = append(out.skipped, s)
			continue
		}
		add(s)
	}
	if containers {
		for _, it := range ifs {
			if it.Name != dockerBridge {
				continue
			}
			for _, a := range it.Addrs {
				if v4 := a.To4(); v4 != nil && redisPrivateAddr(v4) {
					out.containerHost = v4.String()
					add(out.containerHost)
					break
				}
			}
		}
	}
	return out, nil
}

// isWireGuard tells a WireGuard interface by its kernel device type, else (no sysfs) by Falak's wg- name prefix.
func (db *DB) isWireGuard(iface string) bool {
	if b, err := db.d.FS.ReadFile("/sys/class/net/" + iface + "/uevent"); err == nil {
		return strings.Contains(string(b), "DEVTYPE=wireguard")
	}
	return strings.HasPrefix(iface, "wg")
}
