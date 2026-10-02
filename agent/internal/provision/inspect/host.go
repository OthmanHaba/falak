package inspect

import (
	"context"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Firewall is the state of host firewalls besides Kiln's (table inet kiln).
type Firewall struct {
	UFW       string   `json:"ufw"`       // active, inactive or absent
	Firewalld string   `json:"firewalld"` // active, inactive or absent
	Tables    []string `json:"nft_tables"`
}

func (in *Inspector) firewall(ctx context.Context, r *Report) error {
	fw := Firewall{UFW: "absent", Firewalld: "absent", Tables: []string{}}
	if in.exists("/usr/sbin/ufw") {
		fw.UFW = "inactive"
		if out, err := in.output(ctx, "ufw", "status"); err == nil && strings.Contains(strings.ToLower(out), "status: active") {
			fw.UFW = "active"
		}
	}
	if s := r.service("firewalld.service"); s != nil {
		fw.Firewalld = "inactive"
		if s.Active == "active" {
			fw.Firewalld = "active"
		}
	}
	var err error
	if in.exists("/usr/sbin/nft") {
		var out string
		if out, err = in.output(ctx, "nft", "list", "tables"); err == nil {
			fw.Tables = ParseNftTables(out)
		}
	}
	r.Firewall = fw
	return err
}

// ParseNftTables parses `nft list tables` ("table inet kiln" → "inet kiln").
func ParseNftTables(out string) []string {
	ts := []string{}
	for _, line := range strings.Split(out, "\n") {
		if t, ok := strings.CutPrefix(strings.TrimSpace(line), "table "); ok {
			ts = append(ts, t)
		}
	}
	return ts
}

// Swap is one active swap device or file (/proc/swaps).
type Swap struct {
	Name      string `json:"name"`
	Type      string `json:"type"`
	SizeBytes int64  `json:"size_bytes"`
}

func (in *Inspector) swap(r *Report) error {
	b, err := in.d.FS.ReadFile("/proc/swaps")
	if err != nil {
		return err
	}
	r.Swap = ParseProcSwaps(string(b))
	return nil
}

// ParseProcSwaps parses /proc/swaps (sizes in KiB).
func ParseProcSwaps(s string) []Swap {
	out := []Swap{}
	for i, line := range strings.Split(s, "\n") {
		f := strings.Fields(line)
		if i == 0 || len(f) < 3 {
			continue
		}
		kb, _ := strconv.ParseInt(f[2], 10, 64)
		out = append(out, Swap{Name: strings.ReplaceAll(f[0], `\040`, " "), Type: f[1], SizeBytes: kb << 10})
	}
	return out
}

const (
	kilnNodeRoot     = "/opt/kiln/node/"
	kilnFrankenPHP   = "/usr/local/bin/frankenphp"
	kilnFrankenMark  = "/etc/kiln/frankenphp.version"
	nodesourceDomain = "nodesource.com"
)

var semverish = regexp.MustCompile(`v?(\d+\.\d+(?:\.\d+)?)`)

func (in *Inspector) runtimes(ctx context.Context, r *Report) error {
	r.Node = in.nodes(ctx, r)
	r.PHP = in.phps(ctx, r)
	if in.exists(kilnFrankenPHP) {
		b := Binary{Path: kilnFrankenPHP, Source: "manual"}
		if v, err := in.d.FS.ReadFile(kilnFrankenMark); err == nil {
			b.Source, b.Version = "kiln", strings.TrimPrefix(strings.TrimSpace(string(v)), "v")
		} else if out, err := in.output(ctx, kilnFrankenPHP, "version"); err == nil {
			if m := semverish.FindStringSubmatch(out); m != nil {
				b.Version = m[1]
			}
		}
		r.FrankenPHP = append(r.FrankenPHP, b)
	}
	if p := r.pkg("frankenphp"); p != nil {
		r.FrankenPHP = append(r.FrankenPHP, Binary{Path: "/usr/bin/frankenphp", Version: p.Version, Source: p.Origin, Package: p.Name, Repo: p.Repo})
	}
	return nil
}

// nodes finds node binaries: Kiln's (/opt/kiln/node), on PATH (/usr/local/bin, /usr/bin, snap) and nvm's.
func (in *Inspector) nodes(ctx context.Context, r *Report) []Binary {
	cands := []string{"/usr/local/bin/node", "/usr/bin/node", "/snap/bin/node"}
	homes := []string{"/root"}
	if ents, err := os.ReadDir(in.d.FS.P("/home")); err == nil {
		for _, e := range ents {
			homes = append(homes, "/home/"+e.Name())
		}
	}
	for _, h := range homes {
		vs, _ := os.ReadDir(in.d.FS.P(h + "/.nvm/versions/node"))
		for _, v := range vs {
			cands = append(cands, h+"/.nvm/versions/node/"+v.Name()+"/bin/node")
		}
	}
	if vs, err := os.ReadDir(in.d.FS.P(kilnNodeRoot)); err == nil {
		for _, v := range vs {
			if !strings.HasPrefix(v.Name(), ".") {
				cands = append(cands, kilnNodeRoot+v.Name()+"/bin/node")
			}
		}
	}
	out := []Binary{}
	for _, c := range cands {
		if !in.exists(c) || len(out) >= 20 {
			continue
		}
		b := Binary{Path: c, Source: in.nodeSource(r, c)}
		if v, err := in.output(ctx, c, "--version"); err == nil {
			b.Version = strings.TrimPrefix(strings.TrimSpace(v), "v")
		}
		if b.Source == "nodesource" || b.Source == "archive" || b.Source == "vendor" {
			if p := r.pkg("nodejs"); p != nil {
				b.Package, b.Repo = p.Name, p.Repo
			}
		}
		out = append(out, b)
	}
	return out
}

func (in *Inspector) nodeSource(r *Report, path string) string {
	// Kiln's default version is a symlink /usr/local/bin/node → /opt/kiln/node/<version>/bin/node.
	target := path
	if t, err := os.Readlink(in.d.FS.P(path)); err == nil {
		target = t
	}
	switch {
	case strings.HasPrefix(target, kilnNodeRoot) || strings.HasPrefix(path, kilnNodeRoot):
		return "kiln"
	case strings.Contains(path, "/.nvm/"):
		return "nvm"
	case strings.HasPrefix(path, "/snap/") || strings.HasPrefix(target, "/snap/"):
		return "snap"
	case path == "/usr/bin/node":
		if p := r.pkg("nodejs"); p != nil {
			if strings.Contains(p.Repo, nodesourceDomain) {
				return "nodesource"
			}
			return p.Origin
		}
	}
	return "manual"
}

var phpCLI = regexp.MustCompile(`^php(\d+\.\d+)-cli$`)

// phps lists PHP versions from their php<v>-cli packages, plus a php binary in /usr/local/bin that no package owns.
func (in *Inspector) phps(ctx context.Context, r *Report) []Binary {
	out := []Binary{}
	for _, p := range r.Packages {
		if m := phpCLI.FindStringSubmatch(p.Name); m != nil {
			out = append(out, Binary{Path: "/usr/bin/php" + m[1], Version: m[1], Source: p.Origin, Package: p.Name, Repo: p.Repo})
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Version < out[j].Version })
	if in.exists("/usr/local/bin/php") {
		b := Binary{Path: "/usr/local/bin/php", Source: "manual"}
		if v, err := in.output(ctx, "/usr/local/bin/php", "-r", "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;"); err == nil {
			b.Version = strings.TrimSpace(v)
		}
		out = append(out, b)
	}
	return out
}

// Unattended is the unattended-upgrades state.
type Unattended struct {
	Installed bool `json:"installed"`
	// Periodic holds APT::Periodic::* from 20auto-upgrades ("Update-Package-Lists", "Unattended-Upgrade"); nil when
	// the file does not exist.
	Periodic      map[string]string `json:"periodic"`
	ManagedByKiln bool              `json:"managed_by_kiln"`
}

const autoUpgrades = "/etc/apt/apt.conf.d/20auto-upgrades"

var aptPeriodic = regexp.MustCompile(`APT::Periodic::([A-Za-z-]+)\s+"([^"]*)"`)

func (in *Inspector) unattended(r *Report) error {
	u := Unattended{Installed: r.pkg("unattended-upgrades") != nil}
	if b, err := in.d.FS.ReadFile(autoUpgrades); err == nil {
		u.Periodic = map[string]string{}
		for _, m := range aptPeriodic.FindAllStringSubmatch(string(b), -1) {
			u.Periodic[m[1]] = m[2]
		}
		u.ManagedByKiln = strings.HasPrefix(string(b), "// Managed by Kiln")
	}
	r.UnattendedUpgrades = u
	return nil
}

// Fail2ban is the fail2ban state.
type Fail2ban struct {
	Installed bool     `json:"installed"`
	Active    bool     `json:"active"`
	Jails     []string `json:"jails"` // jail.local and the files in jail.d
}

func (in *Inspector) fail2ban(r *Report) error {
	f := Fail2ban{Installed: r.pkg("fail2ban") != nil, Jails: []string{}}
	if s := r.service("fail2ban.service"); s != nil {
		f.Active = s.Active == "active"
	}
	if in.exists("/etc/fail2ban/jail.local") {
		f.Jails = append(f.Jails, "/etc/fail2ban/jail.local")
	}
	if ents, err := os.ReadDir(in.d.FS.P("/etc/fail2ban/jail.d")); err == nil {
		for _, e := range ents {
			if n := e.Name(); strings.HasSuffix(n, ".conf") || strings.HasSuffix(n, ".local") {
				f.Jails = append(f.Jails, "/etc/fail2ban/jail.d/"+n)
			}
		}
	}
	r.Fail2ban = f
	return nil
}
