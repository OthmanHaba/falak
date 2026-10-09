package security

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"io"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
)

// SSH paths.
const (
	SSHDConfig = "/etc/ssh/sshd_config"
	SSHDDir    = "/etc/ssh/sshd_config.d"
	SSHDDropIn = "/etc/ssh/sshd_config.d/50-falak.conf" // provisioning's drop-in; the SSH fix edits it too
)

// sshDefaults are OpenSSH's built-in values of the keywords the checks read.
var sshDefaults = map[string]string{"permitrootlogin": "prohibit-password", "passwordauthentication": "yes", "permitemptypasswords": "no",
	"kbdinteractiveauthentication": "yes", "port": "22", "authorizedkeysfile": ".ssh/authorized_keys .ssh/authorized_keys2"}

// sshEffective returns sshd's effective settings: `sshd -T` when it runs (it needs /run/sshd, which an audit never
// creates), else the files read the way sshd reads them.
func (s *Security) sshEffective(ctx context.Context) (map[string]string, string) {
	if out, ok := s.output(ctx, "sshd", "-T"); ok {
		return ParseSSHDT(out), "sshd -T"
	}
	main, _ := s.read(SSHDConfig)
	var drops []string
	for _, f := range s.sshDropIns() {
		if c, ok := s.read(f); ok {
			drops = append(drops, c)
		}
	}
	return EffectiveFromFiles(main, drops), "config files"
}

func (s *Security) sshDropIns() []string {
	ents, err := os.ReadDir(s.d.FS.P(SSHDDir))
	if err != nil {
		return nil
	}
	var files []string
	for _, e := range ents {
		if !e.IsDir() && strings.HasSuffix(e.Name(), ".conf") {
			files = append(files, SSHDDir+"/"+e.Name())
		}
	}
	sort.Strings(files) // sshd expands the Include glob in lexical order
	return files
}

// ParseSSHDT parses `sshd -T` ("keyword value" lines, lowercase keywords); repeated keywords keep the first value.
func ParseSSHDT(out string) map[string]string {
	eff := map[string]string{}
	for _, line := range strings.Split(out, "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), " ")
		if !ok {
			continue
		}
		if _, seen := eff[k]; !seen {
			eff[k] = strings.TrimSpace(v)
		}
	}
	return eff
}

// sshDirectives returns the first value of every keyword (lowercase) before the first Match block.
func sshDirectives(content string) map[string]string {
	out := map[string]string{}
	for _, line := range strings.Split(content, "\n") {
		k, v, ok := sshLine(line)
		if !ok {
			continue
		}
		if k == "match" {
			break
		}
		if _, seen := out[k]; !seen {
			out[k] = v
		}
	}
	return out
}

// sshLine splits a config line into its lowercase keyword and value; ok is false for blanks and comments.
func sshLine(line string) (string, string, bool) {
	line = strings.TrimSpace(line)
	if line == "" || strings.HasPrefix(line, "#") {
		return "", "", false
	}
	i := strings.IndexAny(line, " \t=")
	if i < 0 {
		return strings.ToLower(line), "", true
	}
	return strings.ToLower(line[:i]), strings.Trim(strings.TrimSpace(line[i+1:]), "=\" \t"), true
}

// EffectiveFromFiles approximates `sshd -T`: sshd keeps the first value it reads, and Debian / Ubuntu include
// sshd_config.d/*.conf at the top of sshd_config, so the drop-ins (in order) come before the main file.
func EffectiveFromFiles(main string, dropIns []string) map[string]string {
	eff := map[string]string{}
	set := func(m map[string]string) {
		for k, v := range m {
			if _, ok := eff[k]; !ok {
				if k != "authorizedkeysfile" {
					v = strings.ToLower(v)
				}
				eff[k] = v
			}
		}
	}
	for _, d := range dropIns {
		set(sshDirectives(d))
	}
	set(sshDirectives(main))
	set(sshDefaults)
	return eff
}

func (s *Security) sshChecks(ctx context.Context, p AuditPayload) []Check {
	eff, source := s.sshEffective(ctx)
	get := func(k string) string {
		if v, ok := eff[k]; ok {
			return v
		}
		return sshDefaults[k]
	}
	from := " (" + source + ")"
	var cs []Check
	c := Check{ID: "ssh.permit_root_login", Title: "Root can't log in with a password", Area: "ssh", Status: Pass, Severity: High, FixID: "ssh.harden",
		Evidence: "PermitRootLogin " + get("permitrootlogin") + from}
	if get("permitrootlogin") == "yes" {
		c.Status = Fail
	}
	cs = append(cs, c)
	c = Check{ID: "ssh.password_authentication", Title: "Password logins are off", Area: "ssh", Status: Pass, Severity: High, FixID: "ssh.harden",
		Evidence: "PasswordAuthentication " + get("passwordauthentication") + from}
	if get("passwordauthentication") != "no" {
		c.Status = Fail
	}
	cs = append(cs, c)
	c = Check{ID: "ssh.permit_empty_passwords", Title: "Empty passwords are refused", Area: "ssh", Status: Pass, Severity: Critical, FixID: "ssh.harden",
		Evidence: "PermitEmptyPasswords " + get("permitemptypasswords") + from}
	if get("permitemptypasswords") != "no" {
		c.Status = Fail
	}
	cs = append(cs, c)
	// With UsePAM (the Debian / Ubuntu default) keyboard-interactive logins are password logins.
	c = Check{ID: "ssh.kbd_interactive", Title: "Keyboard-interactive logins are off", Area: "ssh", Status: Pass, Severity: High, FixID: "ssh.harden",
		Evidence: "KbdInteractiveAuthentication " + get("kbdinteractiveauthentication") + from}
	if get("kbdinteractiveauthentication") != "no" {
		c.Status = Fail
	}
	cs = append(cs, c)
	for i := range cs {
		if cs[i].Status == Pass {
			cs[i].FixID = ""
		}
	}
	return append(cs, s.keysCheck(get("authorizedkeysfile"), p))
}

// LoginUser is a user who can log in over SSH: root, and UID ≥ 1000 with a login shell.
type LoginUser struct {
	Name string
	UID  int
	Home string
}

// loginUsers parses /etc/passwd.
func (s *Security) loginUsers() []LoginUser {
	b, _ := s.read("/etc/passwd")
	var us []LoginUser
	for _, line := range strings.Split(b, "\n") {
		f := strings.Split(line, ":")
		if len(f) < 7 {
			continue
		}
		uid, err := strconv.Atoi(f[2])
		if err != nil || !(uid == 0 || (uid >= 1000 && uid < 65534)) || !loginShell(f[6]) {
			continue
		}
		us = append(us, LoginUser{Name: f[0], UID: uid, Home: f[5]})
	}
	return us
}

func loginShell(sh string) bool {
	return sh != "" && !strings.HasSuffix(sh, "/nologin") && !strings.HasSuffix(sh, "/false") && !strings.HasSuffix(sh, "/sync")
}

func (s *Security) keysCheck(keysFile string, p AuditPayload) Check {
	c := Check{ID: "ssh.authorized_keys", Title: "Only keys Falak manages can log in", Area: "ssh", Status: Pass, Severity: Medium}
	if p.ManagedKeys == nil {
		c.Status, c.Severity, c.Evidence = Info, SevInfo, "keys were not compared"
		return c
	}
	var found []string
	total := 0
	for _, u := range s.loginUsers() {
		managed := map[string]bool{}
		for _, k := range p.ManagedKeys[u.Name] {
			if blob := KeyBlob(k); blob != "" {
				managed[blob] = true
			}
		}
		var unknown []string
		for _, f := range strings.Fields(keysFile) {
			for _, blob := range s.keyBlobs(expandKeysPath(f, u.Name, u.Home)) {
				total++
				if !managed[blob] {
					unknown = append(unknown, Fingerprint(blob))
				}
			}
		}
		if len(unknown) > 0 {
			found = append(found, fmt.Sprintf("%s: %s (%s)", u.Name, plural(len(unknown), "unknown key", "unknown keys"), list(unknown, 2)))
		}
	}
	if len(found) == 0 {
		c.Evidence = plural(total, "authorized key", "authorized keys") + ", all managed by Falak"
		return c
	}
	c.Status, c.Evidence = Warn, strings.Join(found, "; ")
	return c
}

// expandKeysPath expands an AuthorizedKeysFile entry (%h, %u, %%; relative to the home directory).
func expandKeysPath(p, user, home string) string {
	p = strings.NewReplacer("%%", "%", "%h", home, "%u", user).Replace(p)
	if !strings.HasPrefix(p, "/") {
		p = strings.TrimSuffix(home, "/") + "/" + p
	}
	return p
}

// maxKeysFile bounds how much of an authorized_keys file is read.
const maxKeysFile = 1 << 20

// keyBlobs returns the key blobs of an authorized_keys file without trusting it: it lives in a user's home, so it is
// opened with O_NOFOLLOW|O_NONBLOCK, must be a regular file and is read up to maxKeysFile bytes.
func (s *Security) keyBlobs(path string) []string {
	f, err := os.OpenFile(s.d.FS.P(path), os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil
	}
	defer f.Close()
	if fi, err := f.Stat(); err != nil || !fi.Mode().IsRegular() {
		return nil
	}
	b, err := io.ReadAll(io.LimitReader(f, maxKeysFile))
	if err != nil {
		return nil
	}
	var blobs []string
	for _, line := range strings.Split(string(b), "\n") {
		if blob := KeyBlob(line); blob != "" {
			blobs = append(blobs, blob)
		}
	}
	return blobs
}

var keyType = regexp.MustCompile(`^(ssh-(rsa|dss|ed25519)|ecdsa-sha2-nistp(256|384|521)|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh[.]com)$`)

// KeyBlob returns the base64 blob of an authorized_keys line (after optional options), or "".
func KeyBlob(line string) string {
	f := strings.Fields(strings.TrimSpace(line))
	if len(f) < 2 || strings.HasPrefix(f[0], "#") {
		return ""
	}
	for i := 0; i < len(f)-1; i++ {
		if keyType.MatchString(f[i]) && strings.HasPrefix(f[i+1], "AAAA") {
			return f[i+1]
		}
	}
	return ""
}

// Fingerprint renders a key blob the way ssh-keygen -l does ("SHA256:…").
func Fingerprint(blob string) string {
	raw, err := base64.StdEncoding.DecodeString(blob)
	if err != nil {
		raw = []byte(blob)
	}
	sum := sha256.Sum256(raw)
	return "SHA256:" + base64.RawStdEncoding.EncodeToString(sum[:])
}
