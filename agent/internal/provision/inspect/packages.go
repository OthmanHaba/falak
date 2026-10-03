package inspect

import (
	"bufio"
	"context"
	"errors"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// DefaultPackages are the dpkg-query patterns every report covers: the components Kiln provisions and the software
// that conflicts with them.
var DefaultPackages = []string{
	// Docker: Ubuntu's, Docker's and others.
	"docker.io", "docker-ce", "docker-ce-cli", "docker-ce-rootless-extras", "containerd", "containerd.io", "moby-engine", "podman-docker",
	"docker-compose", "docker-compose-v2", "docker-compose-plugin", "docker-buildx", "docker-buildx-plugin",
	// Databases: Ubuntu, PGDG (postgresql-NN), Oracle (mysql-community-server), MariaDB, Percona.
	"postgresql", "postgresql-[0-9]*", "mysql-server", "mysql-server-[0-9]*", "mysql-server-core-[0-9]*", "mysql-community-server",
	"percona-server-server*", "mariadb-server", "mariadb-server-[0-9]*", "mariadb-server-core*",
	// Caches.
	"redis-server", "redis", "valkey-server", "valkey",
	// Web servers.
	"nginx", "nginx-core", "nginx-full", "nginx-light", "nginx-extras", "apache2", "caddy", "frankenphp",
	// Runtimes.
	"php[0-9]*-cli", "php[0-9]*-fpm", "nodejs",
	// Host.
	"openssh-server", "ufw", "firewalld", "nftables", "fail2ban", "unattended-upgrades",
}

var pkgPattern = regexp.MustCompile(`^[a-z0-9][a-z0-9+.*\[\]-]*$`)

// packages lists the installed packages matching DefaultPackages and extra, with the origin of the installed version.
func (in *Inspector) packages(ctx context.Context, r *Report, extra []string) error {
	patterns := append([]string(nil), DefaultPackages...)
	for _, p := range extra {
		if pkgPattern.MatchString(p) {
			patterns = append(patterns, p)
		}
	}
	args := append([]string{"-W", "-f=${Package}\t${db:Status-Status}\t${Version}\n"}, patterns...)
	// dpkg-query exits 1 when a pattern matches nothing; the matches are still printed.
	res, err := in.run(ctx, "dpkg-query", args...)
	if err != nil {
		return err
	}
	installed := ParseDpkgQuery(string(res.Stdout))
	if len(installed) == 0 {
		if res.ExitCode > 1 {
			return errors.New("dpkg-query: " + truncate(string(res.Stderr), 300))
		}
		return nil
	}
	names := make([]string, 0, len(installed))
	for _, p := range installed {
		names = append(names, p.Name)
	}
	unknown := func(err error) error {
		// Without the policy the origins stay unknown; still report what is installed.
		for i := range installed {
			installed[i].Origin = OriginUnknown
		}
		r.Packages = installed
		return err
	}
	global, err := in.output(ctx, "apt-cache", "policy")
	if err != nil {
		return unknown(err)
	}
	policy, err := in.output(ctx, "apt-cache", append([]string{"policy"}, names...)...)
	if err != nil {
		return unknown(err)
	}
	ApplyOrigins(installed, ParseReleases(global), ParsePolicy(policy), in.hasPackageLists())
	r.Packages = installed
	return nil
}

// hasPackageLists reports whether apt has downloaded any package index (a fresh cloud image may have none until
// the first apt-get update; then no installed version is offered by any repository).
func (in *Inspector) hasPackageLists() bool {
	ents, err := os.ReadDir(in.d.FS.P("/var/lib/apt/lists"))
	if err != nil {
		return false
	}
	for _, e := range ents {
		if strings.Contains(e.Name(), "_Packages") {
			return true
		}
	}
	return false
}

// ParseDpkgQuery parses `dpkg-query -W -f='${Package}\t${db:Status-Status}\t${Version}\n'`: installed packages only,
// sorted by name.
func ParseDpkgQuery(out string) []Package {
	seen := map[string]bool{}
	pkgs := []Package{}
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		f := strings.Split(sc.Text(), "\t")
		if len(f) != 3 || f[1] != "installed" {
			continue
		}
		name := strings.SplitN(f[0], ":", 2)[0]
		if seen[name] {
			continue
		}
		seen[name] = true
		pkgs = append(pkgs, Package{Name: name, Version: f[2]})
	}
	sort.Slice(pkgs, func(i, j int) bool { return pkgs[i].Name < pkgs[j].Name })
	return pkgs
}

// Release is what `apt-cache policy` (no arguments) says about one package file.
type Release struct {
	Origin string // o=
	Label  string // l=
}

func isInt(s string) bool {
	_, err := strconv.Atoi(s)
	return err == nil
}

// ParseReleases parses the "Package files" section of `apt-cache policy`: package file ("<url> <suite>/<component>
// <arch> Packages") → its release fields.
func ParseReleases(out string) map[string]Release {
	rel := map[string]Release{}
	cur := ""
	for _, line := range strings.Split(out, "\n") {
		t := strings.TrimSpace(line)
		switch {
		case strings.HasPrefix(t, "release "):
			if cur == "" {
				continue
			}
			var r Release
			for _, kv := range splitRelease(strings.TrimPrefix(t, "release ")) {
				k, v, _ := strings.Cut(kv, "=")
				switch k {
				case "o":
					r.Origin = v
				case "l":
					r.Label = v
				}
			}
			rel[cur] = r
		case strings.HasPrefix(t, "origin "), t == "Package files:":
		case strings.HasPrefix(t, "Pinned packages:"):
			return rel
		default:
			if f := strings.Fields(t); len(f) >= 2 && isInt(f[0]) {
				cur = strings.Join(f[1:], " ")
			}
		}
	}
	return rel
}

// splitRelease splits "v=24.04,o=Ubuntu,a=noble,l=Docker CE,c=stable" on the commas that start a new key.
func splitRelease(s string) []string {
	var out []string
	for _, part := range strings.Split(s, ",") {
		if k, _, ok := strings.Cut(part, "="); ok && len(k) == 1 || len(out) == 0 {
			out = append(out, part)
			continue
		}
		out[len(out)-1] += "," + part
	}
	return out
}

// PolicyFiles are the package files `apt-cache policy` lists for one package.
type PolicyFiles struct {
	Installed []string // files offering the installed version
	Other     []string // files offering other versions
}

// ParsePolicy parses `apt-cache policy <pkg>...`: package → the package files offering its installed version and
// those offering other versions (without /var/lib/dpkg/status). A version line ("     1.2-3 500", " *** 1.2-3 500",
// or with a phasing note "1.2-4 1 (phased 10%)") starts a version's files; a file line is "<prio> <url or path> ...".
func ParsePolicy(out string) map[string]PolicyFiles {
	files := map[string]PolicyFiles{}
	cur, inInstalled := "", false
	for _, line := range strings.Split(out, "\n") {
		if line == "" {
			continue
		}
		t := strings.TrimSpace(line)
		switch {
		case !strings.HasPrefix(line, " ") && strings.HasSuffix(t, ":"):
			cur, inInstalled = strings.SplitN(strings.TrimSuffix(t, ":"), ":", 2)[0], false
			files[cur] = PolicyFiles{}
		case cur == "":
		case strings.HasPrefix(t, "***"):
			inInstalled = true
		case strings.HasPrefix(t, "Installed:"), strings.HasPrefix(t, "Candidate:"), strings.HasPrefix(t, "Version table:"):
		default:
			f := strings.Fields(t)
			if len(f) < 2 || !isInt(f[0]) || !(strings.HasPrefix(f[1], "/") || strings.Contains(f[1], "://")) {
				inInstalled = false // another version
				continue
			}
			if f[1] == "/var/lib/dpkg/status" {
				continue
			}
			pf := files[cur]
			if inInstalled {
				pf.Installed = append(pf.Installed, strings.Join(f[1:], " "))
			} else {
				pf.Other = append(pf.Other, strings.Join(f[1:], " "))
			}
			files[cur] = pf
		}
	}
	return files
}

// archiveOrigins are release Origin values of distribution archives.
var archiveOrigins = map[string]bool{"Ubuntu": true, "Debian": true, "Ubuntu ESM": true, "UbuntuESM": true, "UbuntuESMApps": true}

// ApplyOrigins sets Origin/Repo/Label of every package from the package files offering its installed version. When no
// repository offers the installed version but one offers other versions of the package (the lists are older or newer
// than the installed version, as on a cloud image before its first upgrade), that repository is the origin. Without
// any package lists (no apt-get update yet) the origin is unknown. Repository URLs never keep credentials.
func ApplyOrigins(pkgs []Package, releases map[string]Release, policy map[string]PolicyFiles, haveLists bool) {
	for i := range pkgs {
		p := &pkgs[i]
		files := policy[p.Name].Installed
		if len(files) == 0 {
			files = policy[p.Name].Other
		}
		p.Origin, p.Repo, p.Label = OriginManual, "", ""
		if len(files) == 0 && !haveLists {
			p.Origin = OriginUnknown
		}
		var vendor *Package
		for _, file := range files {
			repo := redact(strings.Fields(file)[0])
			rel, known := releases[file]
			if (known && archiveOrigins[rel.Origin]) || (!known && archiveHost(repo)) {
				p.Origin, p.Repo, p.Label = OriginArchive, repo, rel.Origin
				vendor = nil
				break
			}
			if vendor == nil {
				vendor = &Package{Origin: OriginVendor, Repo: repo, Label: rel.Origin}
			}
		}
		if vendor != nil {
			p.Origin, p.Repo, p.Label = vendor.Origin, vendor.Repo, vendor.Label
		}
	}
}

func archiveHost(repo string) bool {
	u, err := url.Parse(repo)
	if err != nil {
		return false
	}
	h := u.Hostname()
	return h == "archive.ubuntu.com" || h == "security.ubuntu.com" || h == "ports.ubuntu.com" || strings.HasSuffix(h, ".archive.ubuntu.com") ||
		h == "deb.debian.org" || h == "security.debian.org"
}

// snaps lists installed snaps (none when snapd is absent).
func (in *Inspector) snaps(ctx context.Context, r *Report) error {
	if !in.exists("/usr/bin/snap") {
		return nil
	}
	out, err := in.output(ctx, "snap", "list")
	if err != nil {
		// "No snaps are installed yet." exits 0 on recent snapd; older ones print it to stderr.
		return nil
	}
	r.Snaps = ParseSnapList(out)
	return nil
}

// ParseSnapList parses `snap list` (Name Version Rev Tracking Publisher Notes).
func ParseSnapList(out string) []Snap {
	snaps := []Snap{}
	for i, line := range strings.Split(out, "\n") {
		f := strings.Fields(line)
		if i == 0 || len(f) < 2 || f[0] == "Name" {
			continue
		}
		s := Snap{Name: f[0], Version: f[1]}
		if len(f) >= 4 {
			s.Channel = f[3]
		}
		snaps = append(snaps, s)
	}
	return snaps
}

func (r *Report) snap(name string) *Snap {
	for i := range r.Snaps {
		if r.Snaps[i].Name == name {
			return &r.Snaps[i]
		}
	}
	return nil
}
