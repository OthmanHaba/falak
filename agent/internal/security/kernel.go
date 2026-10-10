package security

import (
	"context"
	"os"
	"sort"
	"strconv"
	"strings"
)

// Sysctl is one hardening setting: Want is what the fix writes, OK accepts the values that pass.
type Sysctl struct {
	Key      string
	Want     string
	Severity string
	Title    string
	OK       func(v string) bool
}

func eq(want string) func(string) bool { return func(v string) bool { return v == want } }

func atLeast(n int) func(string) bool {
	return func(v string) bool { i, err := strconv.Atoi(v); return err == nil && i >= n }
}

// Sysctls are the kernel settings the audit checks and kernel.sysctl writes (rp_filter 2, loose mode, also passes 1:
// strict mode breaks asymmetric routes over private networks).
var Sysctls = []Sysctl{
	{"net.ipv4.conf.all.rp_filter", "2", Low, "Reverse-path filtering is on", func(v string) bool { return v == "1" || v == "2" }},
	{"net.ipv4.conf.all.accept_redirects", "0", Medium, "ICMP redirects are ignored (IPv4)", eq("0")},
	{"net.ipv4.conf.default.accept_redirects", "0", Medium, "ICMP redirects are ignored on new interfaces (IPv4)", eq("0")},
	{"net.ipv6.conf.all.accept_redirects", "0", Medium, "ICMP redirects are ignored (IPv6)", eq("0")},
	{"net.ipv4.conf.all.send_redirects", "0", Low, "The server sends no ICMP redirects", eq("0")},
	{"net.ipv4.tcp_syncookies", "1", Medium, "SYN cookies are on", eq("1")},
	{"kernel.kptr_restrict", "1", Low, "Kernel pointers are hidden", atLeast(1)},
	{"kernel.dmesg_restrict", "1", Low, "Only root reads the kernel log", eq("1")},
	{"fs.protected_hardlinks", "1", Medium, "Hard link protection is on", eq("1")},
	{"fs.protected_symlinks", "1", Medium, "Symlink protection is on", eq("1")},
}

// Stricter is what the fix writes for a setting now at cur: cur when it already passes (never loosened), else Want.
func (k Sysctl) Stricter(cur string) string {
	if k.OK(cur) {
		return cur
	}
	return k.Want
}

func procPath(key string) string { return "/proc/sys/" + strings.ReplaceAll(key, ".", "/") }

// sysctl reads a setting's live value; ok is false when the kernel has no such setting (IPv6 disabled, ...).
func (s *Security) sysctl(key string) (string, bool) {
	b, err := s.d.FS.ReadFile(procPath(key))
	if err != nil {
		return "", false
	}
	return strings.TrimSpace(string(b)), true
}

func (s *Security) kernelChecks(_ context.Context, _ AuditPayload) []Check {
	var cs []Check
	for _, k := range Sysctls {
		v, ok := s.sysctl(k.Key)
		if !ok {
			continue
		}
		c := Check{ID: "kernel." + k.Key, Title: k.Title, Area: "kernel", Status: Pass, Severity: k.Severity, Evidence: k.Key + " = " + v}
		if !k.OK(v) {
			c.Status, c.FixID = Fail, "kernel.sysctl"
			c.Evidence += " (want " + k.Want + ")"
		}
		cs = append(cs, c)
	}
	return cs
}

func (s *Security) accountChecks(_ context.Context, p AuditPayload) []Check {
	var cs []Check
	passwd, _ := s.read("/etc/passwd")
	var uid0, shells []string
	known := map[string]bool{"root": true}
	for _, u := range p.KnownUsers {
		known[u] = true
	}
	for _, line := range strings.Split(passwd, "\n") {
		f := strings.Split(line, ":")
		if len(f) < 7 {
			continue
		}
		if f[2] == "0" && f[0] != "root" {
			uid0 = append(uid0, f[0])
		}
		if uid, err := strconv.Atoi(f[2]); err == nil && uid >= 1000 && uid < 65534 && loginShell(f[6]) && !known[f[0]] {
			shells = append(shells, f[0])
		}
	}
	c := Check{ID: "accounts.uid0", Title: "Only root has UID 0", Area: "accounts", Status: Pass, Severity: Critical, Evidence: "root is the only UID 0 account"}
	if len(uid0) > 0 {
		c.Status, c.Evidence = Fail, "UID 0 accounts besides root: "+list(uid0, 5)
	}
	cs = append(cs, c)

	nopasswd := s.nopasswdSudoers()
	c = Check{ID: "accounts.sudo_nopasswd", Title: "No passwordless sudo outside Falak", Area: "accounts", Status: Pass, Severity: Medium,
		Evidence: "no NOPASSWD rules besides Falak's own"}
	if len(nopasswd) > 0 {
		c.Status, c.Evidence = Warn, "NOPASSWD rules in "+list(nopasswd, 4)
	}
	cs = append(cs, c)

	if p.KnownUsers != nil {
		c = Check{ID: "accounts.unknown_users", Title: "Users with a login shell", Area: "accounts", Status: Info, Severity: SevInfo,
			Evidence: "every user with a login shell is managed by Falak"}
		if len(shells) > 0 {
			c.Evidence = "not managed by Falak: " + list(shells, 6)
		}
		cs = append(cs, c)
	}
	return cs
}

// nopasswdSudoers lists sudoers files with an active NOPASSWD rule, except the ones Falak writes
// (/etc/sudoers.d/falak-<user>, "# Managed by Falak").
func (s *Security) nopasswdSudoers() []string {
	files := []string{"/etc/sudoers"}
	if ents, err := os.ReadDir(s.d.FS.P("/etc/sudoers.d")); err == nil {
		for _, e := range ents {
			// sudo skips names with a dot or ending in ~ in #includedir.
			if !e.IsDir() && !strings.Contains(e.Name(), ".") && !strings.HasSuffix(e.Name(), "~") {
				files = append(files, "/etc/sudoers.d/"+e.Name())
			}
		}
	}
	var out []string
	for _, f := range files {
		b, ok := s.read(f)
		if !ok {
			continue
		}
		if strings.HasPrefix(f, "/etc/sudoers.d/falak-") && strings.HasPrefix(b, "# Managed by Falak") {
			continue
		}
		for _, line := range strings.Split(b, "\n") {
			line = strings.TrimSpace(line)
			if !strings.HasPrefix(line, "#") && strings.Contains(line, "NOPASSWD") {
				out = append(out, f)
				break
			}
		}
	}
	sort.Strings(out)
	return out
}
