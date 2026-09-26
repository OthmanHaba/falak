package system

import (
	"bufio"
	"bytes"
	"context"
	"io"
	"strings"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
)

// AptEnv is the environment for every apt/dpkg invocation.
var AptEnv = []string{"DEBIAN_FRONTEND=noninteractive", "NEEDRESTART_MODE=a", "LC_ALL=C"}

var aptOpts = []string{"-y", "-q", "-o", "DPkg::Lock::Timeout=300", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}

// Apt wraps apt-get/dpkg-query.
type Apt struct {
	R      runner.Runner
	Stdout io.Writer
	Stderr io.Writer
}

// AptFor returns an Apt streaming to st (st may be nil).
func AptFor(r runner.Runner, st commands.Stream) Apt {
	a := Apt{R: r}
	if st != nil {
		a.Stdout, a.Stderr = st.Stdout(), st.Stderr()
	}
	return a
}

// SplitPin splits "name=version".
func SplitPin(p string) (name, version string) {
	name, version, _ = strings.Cut(p, "=")
	return
}

// Installed returns installed versions for the given package names (pins stripped).
func (a Apt) Installed(ctx context.Context, pkgs []string) (map[string]string, error) {
	out := map[string]string{}
	if len(pkgs) == 0 {
		return out, nil
	}
	args := []string{"-W", "-f=${Package}\t${db:Status-Status}\t${Version}\n"}
	for _, p := range pkgs {
		n, _ := SplitPin(p)
		args = append(args, n)
	}
	// dpkg-query exits 1 when some packages are unknown; the known ones are still printed.
	res, err := a.R.Run(ctx, runner.Cmd{Name: "dpkg-query", Args: args, Env: AptEnv})
	if err != nil {
		return nil, err
	}
	sc := bufio.NewScanner(bytes.NewReader(res.Stdout))
	for sc.Scan() {
		f := strings.Split(sc.Text(), "\t")
		if len(f) == 3 && f[1] == "installed" {
			out[strings.SplitN(f[0], ":", 2)[0]] = f[2]
		}
	}
	return out, nil
}

// Missing returns the packages (with pins) not installed at the requested version.
func (a Apt) Missing(ctx context.Context, pkgs []string) ([]string, error) {
	inst, err := a.Installed(ctx, pkgs)
	if err != nil {
		return nil, err
	}
	var miss []string
	for _, p := range pkgs {
		n, v := SplitPin(p)
		cur, ok := inst[n]
		if !ok || (v != "" && cur != v) {
			miss = append(miss, p)
		}
	}
	return miss, nil
}

// Update runs apt-get update.
func (a Apt) Update(ctx context.Context) error {
	_, err := runner.Check(ctx, a.R, runner.Cmd{Name: "apt-get", Args: []string{"update", "-q", "-o", "DPkg::Lock::Timeout=300"}, Env: AptEnv, Stdout: a.Stdout, Stderr: a.Stderr})
	return err
}

// InstallRaw runs apt-get install for pkgs (no diffing).
func (a Apt) InstallRaw(ctx context.Context, pkgs []string, extra ...string) error {
	if len(pkgs) == 0 {
		return nil
	}
	args := append(append(append([]string{"install"}, aptOpts...), extra...), pkgs...)
	_, err := runner.Check(ctx, a.R, runner.Cmd{Name: "apt-get", Args: args, Env: AptEnv, Stdout: a.Stdout, Stderr: a.Stderr})
	return err
}

// Ensure installs missing packages (apt-get update first when anything is missing and update is set).
// Returns the packages it installed.
func (a Apt) Ensure(ctx context.Context, pkgs []string, update bool) ([]string, error) {
	miss, err := a.Missing(ctx, pkgs)
	if err != nil || len(miss) == 0 {
		return nil, err
	}
	if update {
		if err := a.Update(ctx); err != nil {
			return nil, err
		}
	}
	return miss, a.InstallRaw(ctx, miss, "--no-install-recommends")
}

// Remove purges installed packages; returns those removed.
func (a Apt) Remove(ctx context.Context, pkgs []string) ([]string, error) {
	inst, err := a.Installed(ctx, pkgs)
	if err != nil {
		return nil, err
	}
	var rm []string
	for _, p := range pkgs {
		n, _ := SplitPin(p)
		if _, ok := inst[n]; ok {
			rm = append(rm, n)
		}
	}
	if len(rm) == 0 {
		return nil, nil
	}
	args := append(append([]string{"remove"}, aptOpts...), rm...)
	_, err = runner.Check(ctx, a.R, runner.Cmd{Name: "apt-get", Args: args, Env: AptEnv, Stdout: a.Stdout, Stderr: a.Stderr})
	return rm, err
}

// Upgradable returns installed packages whose candidate differs from the installed version.
func (a Apt) Upgradable(ctx context.Context, pkgs []string) ([]string, error) {
	var names []string
	for _, p := range pkgs {
		n, _ := SplitPin(p)
		names = append(names, n)
	}
	res, err := runner.Check(ctx, a.R, runner.Cmd{Name: "apt-cache", Args: append([]string{"policy"}, names...), Env: AptEnv})
	if err != nil {
		return nil, err
	}
	var out []string
	var cur, inst string
	flush := func() {
		if cur != "" && inst != "" && inst != "(none)" {
			// candidate stored in inst after '|'
			i, c, _ := strings.Cut(inst, "|")
			if c != "" && c != "(none)" && i != c {
				out = append(out, cur)
			}
		}
	}
	sc := bufio.NewScanner(bytes.NewReader(res.Stdout))
	for sc.Scan() {
		line := sc.Text()
		t := strings.TrimSpace(line)
		switch {
		case !strings.HasPrefix(line, " ") && strings.HasSuffix(t, ":"):
			flush()
			cur, inst = strings.TrimSuffix(t, ":"), ""
		case strings.HasPrefix(t, "Installed:"):
			inst = strings.TrimSpace(strings.TrimPrefix(t, "Installed:"))
		case strings.HasPrefix(t, "Candidate:"):
			inst += "|" + strings.TrimSpace(strings.TrimPrefix(t, "Candidate:"))
		}
	}
	flush()
	return out, nil
}

// PackagePayload is system.package.install.
type PackagePayload struct {
	Packages    []string `json:"packages"`
	State       string   `json:"state"`
	UpdateCache *bool    `json:"update_cache"`
}

// PackageResult is its result.
type PackageResult struct {
	Changed   bool     `json:"changed"`
	Installed []string `json:"installed"`
	Removed   []string `json:"removed"`
}

// PackageInstall converges package state.
func (s *System) PackageInstall(ctx context.Context, p PackagePayload, st commands.Stream) (any, error) {
	if len(p.Packages) == 0 {
		return nil, &commands.PayloadError{Err: errFmt("packages must not be empty")}
	}
	a := AptFor(s.d.Runner, st)
	update := p.UpdateCache == nil || *p.UpdateCache
	res := PackageResult{Installed: []string{}, Removed: []string{}}
	switch p.State {
	case "absent":
		rm, err := a.Remove(ctx, p.Packages)
		res.Removed = append(res.Removed, rm...)
		res.Changed = len(rm) > 0
		return res, err
	case "latest":
		if update {
			if err := a.Update(ctx); err != nil {
				return nil, err
			}
		}
		miss, err := a.Missing(ctx, p.Packages)
		if err != nil {
			return nil, err
		}
		up, err := a.Upgradable(ctx, p.Packages)
		if err != nil {
			return nil, err
		}
		todo := append(miss, up...)
		if err := a.InstallRaw(ctx, todo, "--no-install-recommends"); err != nil {
			return nil, err
		}
		res.Installed = append(res.Installed, todo...)
		res.Changed = len(todo) > 0
		return res, nil
	case "", "present":
		inst, err := a.Ensure(ctx, p.Packages, update)
		res.Installed = append(res.Installed, inst...)
		res.Changed = len(inst) > 0
		return res, err
	default:
		return nil, &commands.PayloadError{Err: errFmt("unknown state " + p.State)}
	}
}
