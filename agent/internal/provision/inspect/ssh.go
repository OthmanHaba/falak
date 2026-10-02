package inspect

import (
	"context"
	"os"
	"sort"
	"strconv"
	"strings"
)

// SSH is the sshd configuration that matters for hardening without a lockout.
type SSH struct {
	// DropIns are the sshd_config.d/*.conf files in the order sshd reads them, with the settings each one sets.
	DropIns []SSHDropIn `json:"drop_ins"`
	// Effective values (lowercase keys of `sshd -T`): passwordauthentication, permitrootlogin, port,
	// pubkeyauthentication, kbdinteractiveauthentication.
	Effective map[string]string `json:"effective"`
	// EffectiveSource is "sshd -T", or "files" when sshd -T could not run and the files were read instead.
	EffectiveSource string      `json:"effective_source"`
	Users           []LoginUser `json:"users"`
}

// SSHDropIn is one sshd_config.d file.
type SSHDropIn struct {
	File     string            `json:"file"`
	Settings map[string]string `json:"settings"`
}

// LoginUser is a user who can log in over SSH (root, and UID ≥ 1000 with a login shell).
type LoginUser struct {
	Name           string `json:"name"`
	UID            int    `json:"uid"`
	AuthorizedKeys int    `json:"authorized_keys"` // public keys in the user's authorized_keys files
}

// sshKeys are the sshd settings the report tracks.
var sshKeys = map[string]bool{"passwordauthentication": true, "permitrootlogin": true, "port": true, "pubkeyauthentication": true, "kbdinteractiveauthentication": true, "authorizedkeysfile": true}

const sshdConfig = "/etc/ssh/sshd_config"

func (in *Inspector) ssh(ctx context.Context, r *Report) error {
	s := SSH{DropIns: []SSHDropIn{}, Users: []LoginUser{}}
	files := in.dropIns()
	for _, f := range files {
		b, err := in.d.FS.ReadFile(f)
		if err != nil {
			continue
		}
		s.DropIns = append(s.DropIns, SSHDropIn{File: f, Settings: ParseSSHDConfig(string(b))})
	}
	// sshd -T needs the privilege separation directory, which only exists once sshd has run; inspecting never
	// creates it, so fall back to reading the files the way sshd does.
	if out, err := in.output(ctx, "sshd", "-T"); err == nil {
		s.Effective = ParseSSHDT(out)
		s.EffectiveSource = "sshd -T"
	} else {
		main, _ := in.d.FS.ReadFile(sshdConfig)
		s.Effective = EffectiveFromFiles(string(main), s.DropIns)
		s.EffectiveSource = "files"
	}
	users, err := in.loginUsers(s.Effective["authorizedkeysfile"])
	s.Users = users
	delete(s.Effective, "authorizedkeysfile")
	r.SSH = s
	return err
}

func (in *Inspector) dropIns() []string {
	ents, err := os.ReadDir(in.d.FS.P("/etc/ssh/sshd_config.d"))
	if err != nil {
		return nil
	}
	var files []string
	for _, e := range ents {
		if !e.IsDir() && strings.HasSuffix(e.Name(), ".conf") {
			files = append(files, "/etc/ssh/sshd_config.d/"+e.Name())
		}
	}
	sort.Strings(files) // sshd expands the Include glob in lexical order
	return files
}

// ParseSSHDConfig returns the first value of each tracked keyword (lowercase) before any Match block.
func ParseSSHDConfig(content string) map[string]string {
	out := map[string]string{}
	for _, line := range strings.Split(content, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v := splitKeyword(line)
		if k == "match" {
			break
		}
		if sshKeys[k] {
			if _, ok := out[k]; !ok {
				out[k] = v
			}
		}
	}
	return out
}

func splitKeyword(line string) (string, string) {
	i := strings.IndexAny(line, " \t=")
	if i < 0 {
		return strings.ToLower(line), ""
	}
	return strings.ToLower(line[:i]), strings.Trim(strings.TrimSpace(line[i+1:]), "=\" \t")
}

// ParseSSHDT parses `sshd -T` (lowercase "keyword value" lines) for the tracked keywords; several port lines are joined.
func ParseSSHDT(out string) map[string]string {
	eff := map[string]string{}
	for _, line := range strings.Split(out, "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), " ")
		if !ok || !sshKeys[k] {
			continue
		}
		if cur, seen := eff[k]; seen && k == "port" {
			eff[k] = cur + " " + v
			continue
		}
		eff[k] = v
	}
	return eff
}

// sshDefaults are OpenSSH's built-in values for the tracked keywords.
var sshDefaults = map[string]string{"passwordauthentication": "yes", "permitrootlogin": "prohibit-password", "port": "22", "pubkeyauthentication": "yes",
	"kbdinteractiveauthentication": "yes", "authorizedkeysfile": ".ssh/authorized_keys .ssh/authorized_keys2"}

// EffectiveFromFiles approximates `sshd -T`: sshd keeps the first value it reads; Ubuntu's sshd_config includes
// sshd_config.d/*.conf at its top, so the drop-ins (in order) come before the main file.
func EffectiveFromFiles(main string, dropIns []SSHDropIn) map[string]string {
	eff := map[string]string{}
	set := func(m map[string]string) {
		for k, v := range m {
			if _, ok := eff[k]; !ok {
				if k != "authorizedkeysfile" { // paths keep their case, as in sshd -T
					v = strings.ToLower(v)
				}
				eff[k] = v
			}
		}
	}
	for _, d := range dropIns {
		set(d.Settings)
	}
	set(ParseSSHDConfig(main))
	set(sshDefaults)
	return eff
}

// loginUsers lists root and the regular users with a login shell, counting their authorized keys.
func (in *Inspector) loginUsers(keysFile string) ([]LoginUser, error) {
	b, err := in.d.FS.ReadFile("/etc/passwd")
	if err != nil {
		return []LoginUser{}, err
	}
	if keysFile == "" || keysFile == "none" {
		keysFile = sshDefaults["authorizedkeysfile"]
	}
	users := []LoginUser{}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Split(line, ":")
		if len(f) < 7 {
			continue
		}
		uid, err := strconv.Atoi(f[2])
		if err != nil || !(uid == 0 || (uid >= 1000 && uid < 65534)) || !loginShell(f[6]) {
			continue
		}
		u := LoginUser{Name: f[0], UID: uid}
		for _, p := range strings.Fields(keysFile) {
			u.AuthorizedKeys += in.countKeys(expandKeysPath(p, f[0], f[5]))
		}
		users = append(users, u)
	}
	return users, nil
}

func loginShell(sh string) bool {
	return sh != "" && !strings.HasSuffix(sh, "/nologin") && !strings.HasSuffix(sh, "/false") && !strings.HasSuffix(sh, "/sync")
}

// expandKeysPath expands an AuthorizedKeysFile entry (%h, %u, %%; relative to the home directory).
func expandKeysPath(p, user, home string) string {
	p = strings.NewReplacer("%%", "%", "%h", home, "%u", user).Replace(p)
	if !strings.HasPrefix(p, "/") {
		p = strings.TrimRight(home, "/") + "/" + p
	}
	return p
}

// countKeys counts the key lines of an authorized_keys file.
func (in *Inspector) countKeys(path string) int {
	b, err := in.d.FS.ReadFile(path)
	if err != nil {
		return 0
	}
	n := 0
	for _, line := range strings.Split(string(b), "\n") {
		if line = strings.TrimSpace(line); line != "" && !strings.HasPrefix(line, "#") {
			n++
		}
	}
	return n
}
