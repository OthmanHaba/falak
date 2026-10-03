package inspect

import (
	"bufio"
	"context"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Units whose state the report includes (when they exist).
var Units = []string{
	"docker.service", "docker.socket", "containerd.service",
	"postgresql.service", "mysql.service", "mariadb.service", "redis-server.service", "redis.service", "valkey-server.service", "valkey.service",
	"nginx.service", "apache2.service", "caddy.service", "kiln-edge.service", "frankenphp.service",
	"ssh.service", "ssh.socket", "fail2ban.service", "unattended-upgrades.service",
	"ufw.service", "firewalld.service", "nftables.service", "kiln-firewall.service",
}

// services reports the state of Units that exist.
func (in *Inspector) services(ctx context.Context, r *Report) error {
	out, err := in.output(ctx, "systemctl", append([]string{"show", "--property=Id,LoadState,ActiveState,UnitFileState", "--"}, Units...)...)
	if err != nil {
		return err
	}
	r.Services = ParseSystemctlShow(out)
	return nil
}

// ParseSystemctlShow parses `systemctl show --property=Id,LoadState,ActiveState,UnitFileState <units>` (blank-line
// separated blocks); units that are not loaded (not-found) are left out.
func ParseSystemctlShow(out string) []Service {
	svcs := []Service{}
	var cur Service
	load := ""
	flush := func() {
		if cur.Unit != "" && load != "not-found" && load != "" {
			svcs = append(svcs, cur)
		}
		cur, load = Service{}, ""
	}
	for _, line := range strings.Split(out, "\n") {
		if strings.TrimSpace(line) == "" {
			flush()
			continue
		}
		k, v, _ := strings.Cut(line, "=")
		switch k {
		case "Id":
			cur.Unit = v
		case "LoadState":
			load = v
		case "ActiveState":
			cur.Active = v
		case "UnitFileState":
			cur.Enabled = v
		}
	}
	flush()
	return svcs
}

// containerProxies are the processes container runtimes listen with for published ports.
var containerProxies = map[string]bool{"docker-proxy": true, "rootlessport": true, "rootlesskit": true, "conmon": true, "slirp4netns": true, "pasta": true}

// listeners reports the listening TCP sockets with their process and its systemd unit.
func (in *Inspector) listeners(ctx context.Context, r *Report) error {
	out, err := in.output(ctx, "ss", "-H", "-ltnp")
	if err != nil {
		return err
	}
	ls := ParseSS(out)
	for i := range ls {
		if ls[i].PID > 0 {
			if b, err := in.d.FS.ReadFile("/proc/" + strconv.Itoa(ls[i].PID) + "/cgroup"); err == nil {
				ls[i].Unit = UnitFromCgroup(string(b))
			}
		}
	}
	r.Listeners = ls
	return nil
}

var ssUsers = regexp.MustCompile(`\(\("([^"]+)",pid=(\d+)`)

// ParseSS parses `ss -H -ltnp`: one Listener per address and port (the first process holding it), sorted by port.
func ParseSS(out string) []Listener {
	seen := map[string]bool{}
	ls := []Listener{}
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
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
		l := Listener{Port: port, Address: addr}
		if m := ssUsers.FindStringSubmatch(sc.Text()); m != nil {
			l.Process = m[1]
			l.PID, _ = strconv.Atoi(m[2])
			l.Container = containerProxies[l.Process]
		}
		ls = append(ls, l)
	}
	sort.SliceStable(ls, func(i, j int) bool { return ls[i].Port < ls[j].Port })
	return ls
}

// UnitFromCgroup returns the systemd unit of a /proc/<pid>/cgroup ("0::/system.slice/nginx.service" → nginx.service).
func UnitFromCgroup(cg string) string {
	for _, line := range strings.Split(cg, "\n") {
		parts := strings.SplitN(line, ":", 3)
		if len(parts) != 3 {
			continue
		}
		segs := strings.Split(parts[2], "/")
		for k := len(segs) - 1; k >= 0; k-- {
			if s := segs[k]; strings.HasSuffix(s, ".service") || strings.HasSuffix(s, ".scope") {
				return s
			}
		}
	}
	return ""
}

var publishedPort = regexp.MustCompile(`(?:([0-9a-fA-F.:\[\]]*):)?(\d+)->(\d+)/(tcp|udp|sctp)`)

// containers lists running containers and their published ports (none when Docker is absent or stopped).
func (in *Inspector) containers(ctx context.Context, r *Report, cli string) error {
	res, err := in.run(ctx, cli, "ps", "--format", "{{.Names}}\t{{.Image}}\t{{.Ports}}")
	if err != nil || res.ExitCode != 0 {
		return nil
	}
	r.Containers = ParseDockerPS(string(res.Stdout))
	return nil
}

// ParseDockerPS parses `docker ps --format '{{.Names}}\t{{.Image}}\t{{.Ports}}'`.
func ParseDockerPS(out string) []Container {
	cs := []Container{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.Split(line, "\t")
		if len(f) < 2 || f[0] == "" {
			continue
		}
		c := Container{Name: f[0], Image: f[1], Ports: []PublishedPort{}}
		seen := map[string]bool{}
		if len(f) > 2 {
			for _, m := range publishedPort.FindAllStringSubmatch(f[2], -1) {
				hp, _ := strconv.Atoi(m[2])
				cp, _ := strconv.Atoi(m[3])
				key := m[2] + "/" + m[4]
				if seen[key] {
					continue // the IPv4 and IPv6 bindings of one port
				}
				seen[key] = true
				c.Ports = append(c.Ports, PublishedPort{HostIP: strings.Trim(m[1], "[]"), HostPort: hp, ContainerPort: cp, Protocol: m[4]})
			}
		}
		cs = append(cs, c)
	}
	return cs
}
