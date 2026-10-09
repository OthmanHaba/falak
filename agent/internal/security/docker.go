package security

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
)

// DaemonJSON is Docker's daemon configuration.
const DaemonJSON = "/etc/docker/daemon.json"

// maxContainers bounds how many containers one audit inspects.
const maxContainers = 200

// Container is what the checks need from `docker inspect`.
type Container struct {
	Name       string
	Privileged bool
	SocketPath string // the Docker socket bind-mounted into it, "" when none
	// PublicPorts are "tcp/8080" ports published on a public (wildcard) address.
	PublicPorts []PublishedPort
}

// PublishedPort is a host port published by a container.
type PublishedPort struct {
	Proto  string
	HostIP string
	Port   int
}

// ParseDockerInspect parses `docker inspect <ids>`.
func ParseDockerInspect(b []byte) ([]Container, error) {
	var raw []struct {
		Name       string `json:"Name"`
		HostConfig struct {
			Privileged bool `json:"Privileged"`
		} `json:"HostConfig"`
		Mounts []struct {
			Source      string `json:"Source"`
			Destination string `json:"Destination"`
		} `json:"Mounts"`
		NetworkSettings struct {
			Ports map[string][]struct {
				HostIP   string `json:"HostIp"`
				HostPort string `json:"HostPort"`
			} `json:"Ports"`
		} `json:"NetworkSettings"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return nil, err
	}
	var cs []Container
	for _, r := range raw {
		c := Container{Name: strings.TrimPrefix(r.Name, "/"), Privileged: r.HostConfig.Privileged}
		for _, m := range r.Mounts {
			if m.Source == "/var/run/docker.sock" || m.Source == "/run/docker.sock" {
				c.SocketPath = m.Source
			}
		}
		for spec, binds := range r.NetworkSettings.Ports {
			_, proto, _ := strings.Cut(spec, "/")
			for _, b := range binds {
				port, err := strconv.Atoi(b.HostPort)
				if err != nil || !Public(b.HostIP) {
					continue
				}
				pp := PublishedPort{Proto: proto, HostIP: b.HostIP, Port: port}
				if !containsPort(c.PublicPorts, pp) {
					c.PublicPorts = append(c.PublicPorts, pp)
				}
			}
		}
		cs = append(cs, c)
	}
	return cs, nil
}

func containsPort(ps []PublishedPort, p PublishedPort) bool {
	for _, q := range ps {
		if q.Proto == p.Proto && q.Port == p.Port {
			return true
		}
	}
	return false
}

// DaemonTCP returns the TCP sockets of daemon.json "hosts" and whether TLS verification is on.
func DaemonTCP(b []byte) (hosts []string, tlsVerify bool, err error) {
	var cfg struct {
		Hosts     []string `json:"hosts"`
		TLSVerify bool     `json:"tlsverify"`
	}
	if err := json.Unmarshal(b, &cfg); err != nil {
		return nil, false, err
	}
	for _, h := range cfg.Hosts {
		if strings.HasPrefix(h, "tcp://") {
			hosts = append(hosts, h)
		}
	}
	return hosts, cfg.TLSVerify, nil
}

func (s *Security) dockerChecks(ctx context.Context, p AuditPayload) []Check {
	if !s.d.FS.Exists("/usr/bin/docker") && !s.d.FS.Exists("/usr/local/bin/docker") && !s.d.FS.Exists(DaemonJSON) {
		return []Check{{ID: "docker.installed", Title: "Docker", Area: "docker", Status: Info, Severity: SevInfo, Evidence: "Docker is not installed"}}
	}
	var cs []Check

	c := Check{ID: "docker.tcp", Title: "The Docker API is not exposed over plain TCP", Area: "docker", Status: Pass, Severity: Critical,
		Evidence: "the daemon listens on its unix socket only"}
	if b, ok := s.read(DaemonJSON); ok {
		hosts, tls, err := DaemonTCP([]byte(b))
		if err != nil {
			c.Status, c.Severity, c.Evidence = Warn, Medium, DaemonJSON+" is not valid JSON"
		} else if len(hosts) > 0 && !tls {
			c.Status, c.FixID, c.Evidence = Fail, "docker.tcp_off", DaemonJSON+" listens on "+strings.Join(hosts, ", ")+" without tlsverify"
		}
	}
	if c.Status == Pass {
		if out, ok := s.output(ctx, "systemctl", "show", "docker.service", "--property=ExecStart", "--value"); ok && strings.Contains(out, "tcp://") && !strings.Contains(out, "--tlsverify") {
			c.Status, c.Evidence = Fail, "docker.service starts dockerd with a tcp:// host and no --tlsverify (change the unit by hand)"
		}
	}
	cs = append(cs, c)

	cs = append(cs, s.containerChecks(ctx, p)...)

	c = Check{ID: "docker.group", Title: "Nobody but root drives Docker", Area: "docker", Status: Pass, Severity: High, Evidence: "the docker group has no members"}
	if b, ok := s.read("/etc/group"); ok {
		if members := GroupMembers(b, "docker"); len(members) > 0 {
			c.Status, c.Evidence = Fail, "the docker group (root-equivalent) has "+list(members, 5)
		}
	}
	return append(cs, c)
}

// GroupMembers returns the members of a group in /etc/group, root left out.
func GroupMembers(etcGroup, group string) []string {
	for _, line := range strings.Split(etcGroup, "\n") {
		f := strings.Split(line, ":")
		if len(f) < 4 || f[0] != group {
			continue
		}
		var ms []string
		for _, m := range strings.Split(f[3], ",") {
			if m = strings.TrimSpace(m); m != "" && m != "root" {
				ms = append(ms, m)
			}
		}
		return ms
	}
	return nil
}

func (s *Security) containerChecks(ctx context.Context, p AuditPayload) []Check {
	ids, ok := s.output(ctx, "docker", "ps", "-q", "--no-trunc")
	if !ok {
		return []Check{{ID: "docker.containers", Title: "Containers", Area: "docker", Status: Info, Severity: SevInfo, Evidence: "the Docker daemon did not answer"}}
	}
	fields := strings.Fields(ids)
	if len(fields) > maxContainers {
		fields = fields[:maxContainers]
	}
	var cs []Container
	if len(fields) > 0 {
		out, ok := s.output(ctx, "docker", append([]string{"inspect"}, fields...)...)
		if !ok {
			return []Check{{ID: "docker.containers", Title: "Containers", Area: "docker", Status: Info, Severity: SevInfo, Evidence: "docker inspect failed"}}
		}
		var err error
		if cs, err = ParseDockerInspect([]byte(out)); err != nil {
			return []Check{{ID: "docker.containers", Title: "Containers", Area: "docker", Status: Info, Severity: SevInfo, Evidence: "docker inspect returned unexpected output"}}
		}
	}
	var privileged, sockets, bypass []string
	exp := ParseExpected(p.ExpectedPorts)
	for _, c := range cs {
		if c.Privileged {
			privileged = append(privileged, c.Name)
		}
		if c.SocketPath != "" {
			sockets = append(sockets, c.Name)
		}
		for _, pp := range c.PublicPorts {
			if p.ExpectedPorts == nil || !expected(exp, pp.Proto, pp.Port) {
				bypass = append(bypass, fmt.Sprintf("%s (%s/%d)", c.Name, pp.Proto, pp.Port))
			}
		}
	}
	n := plural(len(cs), "running container", "running containers")
	out := []Check{
		{ID: "docker.privileged", Title: "No container runs privileged", Area: "docker", Status: Pass, Severity: High, Evidence: n + ", none privileged"},
		{ID: "docker.socket_mounts", Title: "No container can drive Docker", Area: "docker", Status: Pass, Severity: High, Evidence: n + ", none mount the Docker socket"},
		{ID: "firewall.docker_published", Title: "Containers don't publish ports around the firewall", Area: "firewall", Status: Pass, Severity: High,
			Evidence: n + ", none publish unexpected ports on public addresses"},
	}
	if len(privileged) > 0 {
		out[0].Status, out[0].Evidence = Fail, "privileged: "+list(privileged, 5)
	}
	if len(sockets) > 0 {
		out[1].Status, out[1].Evidence = Fail, "mount the Docker socket: "+list(sockets, 5)
	}
	if len(bypass) > 0 {
		// Docker's own NAT rules forward published ports before the input chain sees them.
		out[2].Status, out[2].Evidence = Fail, "published on all interfaces (not filtered by the firewall): "+list(bypass, 5)+"; publish them on 127.0.0.1"
	}
	return out
}
