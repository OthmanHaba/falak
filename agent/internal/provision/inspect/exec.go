package inspect

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"

	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/system"
)

// The inspector runs as root and looks at software other users may have installed. It never executes a file a
// non-root user could have written: every command is resolved to an absolute path in the system directories (or a
// known install location) and run only when that file and every directory on its path are owned by root and not
// writable by group or others. Versions of user-owned installs (nvm, a Node or PHP in a user's tree) come from
// directory names and package metadata instead.

// systemDirs are searched for the tools the inspector runs (never /usr/local or a user's PATH).
var systemDirs = []string{"/usr/sbin", "/usr/bin", "/sbin", "/bin"}

// errUnsafe marks a binary the inspector refused to execute.
var errUnsafe = errors.New("not executed")

// resolve finds a tool in systemDirs; an absolute name is used as is.
func (in *Inspector) resolve(name string) (string, error) {
	if strings.HasPrefix(name, "/") {
		return name, nil
	}
	for _, dir := range systemDirs {
		if p := dir + "/" + name; in.exists(p) {
			return p, nil
		}
	}
	return "", fmt.Errorf("%s: not found in %s", name, strings.Join(systemDirs, ", "))
}

// run executes a resolved, root-owned binary; a non-zero exit is not an error (see runner.Runner).
func (in *Inspector) run(ctx context.Context, name string, args ...string) (runner.Result, error) {
	p, err := in.resolve(name)
	if err != nil {
		return runner.Result{ExitCode: -1}, err
	}
	if err := in.safe(p); err != nil {
		return runner.Result{ExitCode: -1}, err
	}
	return in.d.Runner.Run(ctx, runner.Cmd{Name: p, Args: args, Env: system.AptEnv})
}

// output runs a read-only command and returns its stdout; a non-zero exit is an error.
func (in *Inspector) output(ctx context.Context, name string, args ...string) (string, error) {
	res, err := in.run(ctx, name, args...)
	if err != nil {
		return "", err
	}
	if res.ExitCode != 0 {
		return string(res.Stdout), &runner.ExitError{Cmd: strings.TrimSpace(name + " " + strings.Join(args, " ")), Code: res.ExitCode, Stderr: string(res.Stderr)}
	}
	return string(res.Stdout), nil
}

// safe checks that a host path may be executed as root: the file (after symlinks) and every directory leading to
// it, before and after resolving symlinks, are owned by root and writable by nobody else.
func (in *Inspector) safe(hostPath string) error {
	root, err := filepath.EvalSymlinks(in.d.FS.P("/"))
	if err != nil {
		return err
	}
	real, err := filepath.EvalSymlinks(in.d.FS.P(hostPath))
	if err != nil {
		return err
	}
	rel, err := filepath.Rel(root, real)
	if err != nil || rel == ".." || strings.HasPrefix(rel, "../") {
		return fmt.Errorf("%w: %s resolves outside the filesystem", errUnsafe, hostPath)
	}
	resolved := "/" + filepath.ToSlash(rel)
	for _, p := range append(ancestors(path.Dir(hostPath)), ancestors(resolved)...) {
		fi, err := os.Stat(in.d.FS.P(p))
		if err != nil {
			return err
		}
		if err := in.trusted(fi, p); err != nil {
			return err
		}
	}
	fi, err := os.Stat(real)
	if err != nil {
		return err
	}
	if !fi.Mode().IsRegular() {
		return fmt.Errorf("%w: %s is not a regular file", errUnsafe, hostPath)
	}
	return nil
}

// ancestors returns p and every directory above it ("/usr/bin/x" → "/", "/usr", "/usr/bin", "/usr/bin/x").
func ancestors(p string) []string {
	p = path.Clean("/" + p)
	out := []string{"/"}
	cur := ""
	for _, seg := range strings.Split(strings.TrimPrefix(p, "/"), "/") {
		if seg == "" {
			continue
		}
		cur += "/" + seg
		out = append(out, cur)
	}
	return out
}

func (in *Inspector) trusted(fi os.FileInfo, p string) error {
	st, ok := fi.Sys().(*syscall.Stat_t)
	if !ok {
		return fmt.Errorf("%w: cannot read the owner of %s", errUnsafe, p)
	}
	if int(st.Uid) != in.d.OwnerUID {
		return fmt.Errorf("%w: %s is not owned by root", errUnsafe, p)
	}
	if fi.Mode().Perm()&0o022 != 0 {
		return fmt.Errorf("%w: %s is writable by group or others", errUnsafe, p)
	}
	return nil
}

// userinfo matches the credentials of a URL ("https://user:token@host" → "https://host").
var userinfo = regexp.MustCompile(`([A-Za-z][A-Za-z0-9+.-]*://)[^/@\s'"]+@`)

// redact removes URL credentials (apt sources with auth tokens) from text that goes into the report.
func redact(s string) string { return userinfo.ReplaceAllString(s, "$1") }
