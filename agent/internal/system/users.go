package system

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"sync"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

func errFmt(s string) error { return errors.New(s) }

// UserSpec is system.user.create (and provision.apply users[]).
type UserSpec struct {
	Name     string   `json:"name"`
	UID      *int     `json:"uid"`
	Shell    string   `json:"shell"`
	Home     string   `json:"home"`
	Groups   []string `json:"groups"`
	System   bool     `json:"system"`
	Sudo     string   `json:"sudo"`
	Isolated bool     `json:"isolated"`
}

// UserResult is the result of system.user.create.
type UserResult struct {
	Changed bool   `json:"changed"`
	UID     int    `json:"uid"`
	Home    string `json:"home"`
}

// Passwd is a parsed passwd entry.
type Passwd struct {
	Name, Home, Shell string
	UID, GID          int
}

// LookupPasswd uses `getent passwd` (exit 2 = not found → ok=false).
func LookupPasswd(ctx context.Context, r runner.Runner, name string) (Passwd, bool, error) {
	res, err := r.Run(ctx, runner.Cmd{Name: "getent", Args: []string{"passwd", name}})
	if err != nil {
		return Passwd{}, false, err
	}
	if res.ExitCode == 2 {
		return Passwd{}, false, nil
	}
	if res.ExitCode != 0 {
		return Passwd{}, false, fmt.Errorf("getent passwd %s: exit %d", name, res.ExitCode)
	}
	f := strings.Split(strings.TrimSpace(string(res.Stdout)), ":")
	if len(f) < 7 {
		return Passwd{}, false, fmt.Errorf("getent passwd %s: malformed %q", name, res.Stdout)
	}
	uid, _ := strconv.Atoi(f[2])
	gid, _ := strconv.Atoi(f[3])
	return Passwd{Name: f[0], UID: uid, GID: gid, Home: f[5], Shell: f[6]}, true, nil
}

func groupExists(ctx context.Context, r runner.Runner, g string) (bool, error) {
	res, err := r.Run(ctx, runner.Cmd{Name: "getent", Args: []string{"group", g}})
	if err != nil {
		return false, err
	}
	return res.ExitCode == 0, nil
}

// EnsureUser converges a unix user. Users are never deleted.
// AccountsMu serializes every change to local accounts the agent makes (site users here, Redis / Valkey instance
// users in package db): useradd, usermod and userdel lock /etc/passwd and fail when two run at once.
var AccountsMu sync.Mutex

func EnsureUser(ctx context.Context, r runner.Runner, fs hostfs.FS, p UserSpec, st commands.Stream) (UserResult, error) {
	if p.Name == "" {
		return UserResult{}, &commands.PayloadError{Err: errFmt("name is required")}
	}
	AccountsMu.Lock()
	defer AccountsMu.Unlock()
	shell := p.Shell
	if shell == "" {
		shell = "/bin/bash"
	}
	var out, errw = st.Stdout(), st.Stderr()
	run := func(name string, args ...string) error {
		_, err := runner.Check(ctx, r, runner.Cmd{Name: name, Args: args, Stdout: out, Stderr: errw})
		return err
	}
	changed := false
	for _, g := range p.Groups {
		ok, err := groupExists(ctx, r, g)
		if err != nil {
			return UserResult{}, err
		}
		if !ok {
			if err := run("groupadd", g); err != nil {
				return UserResult{}, err
			}
			changed = true
		}
	}
	pw, exists, err := LookupPasswd(ctx, r, p.Name)
	if err != nil {
		return UserResult{}, err
	}
	if !exists {
		args := []string{"--create-home", "--user-group", "--shell", shell}
		if p.Home != "" {
			args = append(args, "--home-dir", p.Home)
		}
		if p.UID != nil {
			args = append(args, "--uid", strconv.Itoa(*p.UID))
		}
		if p.System {
			args = append(args, "--system")
		}
		if len(p.Groups) > 0 {
			args = append(args, "--groups", strings.Join(p.Groups, ","))
		}
		if err := run("useradd", append(args, p.Name)...); err != nil {
			return UserResult{}, err
		}
		changed = true
		if pw, exists, err = LookupPasswd(ctx, r, p.Name); err != nil {
			return UserResult{}, err
		} else if !exists {
			return UserResult{}, fmt.Errorf("user %s missing after useradd", p.Name)
		}
	} else {
		if pw.Shell != shell {
			if err := run("usermod", "--shell", shell, p.Name); err != nil {
				return UserResult{}, err
			}
			changed = true
		}
		if len(p.Groups) > 0 {
			res, err := runner.Check(ctx, r, runner.Cmd{Name: "id", Args: []string{"-nG", p.Name}})
			if err != nil {
				return UserResult{}, err
			}
			have := map[string]bool{}
			for _, g := range strings.Fields(string(res.Stdout)) {
				have[g] = true
			}
			var add []string
			for _, g := range p.Groups {
				if !have[g] {
					add = append(add, g)
				}
			}
			if len(add) > 0 {
				if err := run("usermod", "--append", "--groups", strings.Join(add, ","), p.Name); err != nil {
					return UserResult{}, err
				}
				changed = true
			}
		}
	}
	home := pw.Home
	if home == "" {
		home = p.Home
	}
	if p.Isolated && home != "" {
		if st, err := os.Stat(fs.P(home)); err == nil && st.Mode().Perm() != 0o750 {
			if err := os.Chmod(fs.P(home), 0o750); err != nil {
				return UserResult{}, err
			}
			changed = true
		}
	}
	c, err := syncSudoers(ctx, r, fs, p.Name, p.Sudo)
	if err != nil {
		return UserResult{}, err
	}
	changed = changed || c
	return UserResult{Changed: changed, UID: pw.UID, Home: home}, nil
}

func syncSudoers(ctx context.Context, r runner.Runner, fs hostfs.FS, name, mode string) (bool, error) {
	path := "/etc/sudoers.d/falak-" + name
	if mode == "" || mode == "none" {
		return fs.Remove(path)
	}
	if mode != "nopasswd" {
		return false, &commands.PayloadError{Err: fmt.Errorf("unknown sudo mode %q", mode)}
	}
	want := []byte(fmt.Sprintf("# Managed by Falak\n%s ALL=(ALL:ALL) NOPASSWD:ALL\n", name))
	if cur, err := fs.ReadFile(path); err == nil && string(cur) == string(want) {
		return false, nil
	}
	// Validate a candidate before it can break sudo.
	tmp := path + ".falak-check"
	if _, err := fs.WriteFile(tmp, want, 0o440); err != nil {
		return false, err
	}
	defer fs.Remove(tmp)
	if _, err := runner.Check(ctx, r, runner.Cmd{Name: "visudo", Args: []string{"-cf", fs.P(tmp)}}); err != nil {
		return false, err
	}
	return fs.WriteFile(path, want, 0o440)
}
