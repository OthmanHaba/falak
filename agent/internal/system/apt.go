package system

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strings"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
)

// AptEnv is the environment for every apt/dpkg invocation.
var AptEnv = []string{"DEBIAN_FRONTEND=noninteractive", "NEEDRESTART_MODE=a", "LC_ALL=C"}

var aptOpts = []string{"-y", "-q", "-o", "DPkg::Lock::Timeout=300", "-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}

// Apt wraps apt-get/dpkg-query.
type Apt struct {
	R runner.Runner
	// FS is where Update finds the apt sources (/etc/apt/sources.list, sources.list.d) of a failing repository.
	FS     hostfs.FS
	Stdout io.Writer
	Stderr io.Writer
}

// AptFor returns an Apt streaming to st (st may be nil).
func AptFor(r runner.Runner, fs hostfs.FS, st commands.Stream) Apt {
	a := Apt{R: r, FS: fs}
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

// Update runs apt-get update. Every apt-get update of the agent goes through here:
//
//   - a ppa:ondrej/php source whose release this distribution does not have ("does not have a Release file", e.g.
//     a PPA added before an upgrade to a release the PPA does not build for) is disabled (renamed to
//     <file>.disabled-by-kiln) with a warning, and the update runs once more;
//   - any other repository that breaks the update fails with a RepoError naming the repository and its source file.
func (a Apt) Update(ctx context.Context) error {
	err := a.update(ctx)
	broken := brokenRepos(err)
	if len(broken) == 0 {
		return err
	}
	disabled := false
	for _, r := range broken {
		if !r.ondrejUnreleased() {
			continue
		}
		for _, f := range a.sourceFiles(r.URL) {
			if !strings.HasPrefix(f, AptSourcesDir+"/") {
				continue // never rename sources.list itself
			}
			if err := os.Rename(a.FS.P(f), a.FS.P(f+DisabledSuffix)); err != nil {
				return fmt.Errorf("disable %s: %w", f, err)
			}
			disabled = true
			a.warn("warning: %s has no release for this distribution; disabled %s (now %s%s)", r.URL, f, f, DisabledSuffix)
		}
	}
	if disabled {
		err = a.update(ctx)
		if broken = brokenRepos(err); len(broken) == 0 {
			return err
		}
	}
	return a.repoError(broken, err)
}

func (a Apt) update(ctx context.Context) error {
	_, err := runner.Check(ctx, a.R, runner.Cmd{Name: "apt-get", Args: []string{"update", "-q", "-o", "DPkg::Lock::Timeout=300"}, Env: AptEnv, Stdout: a.Stdout, Stderr: a.Stderr})
	return err
}

func (a Apt) warn(format string, args ...any) {
	if a.Stderr != nil {
		fmt.Fprintf(a.Stderr, format+"\n", args...)
	}
}

// AptSourcesDir holds the apt source files besides AptSourcesList.
const (
	AptSourcesList = "/etc/apt/sources.list"
	AptSourcesDir  = "/etc/apt/sources.list.d"
	// DisabledSuffix is appended to a source file Update disabled; apt ignores files without .list/.sources.
	DisabledSuffix = ".disabled-by-kiln"
)

// brokenRepo is one repository an apt-get update error line names.
type brokenRepo struct {
	URL  string // repository URL as apt printed it (a file URL for "Failed to fetch")
	Line string // the E: line
}

func (r brokenRepo) ondrejUnreleased() bool {
	return strings.Contains(r.URL, "/ondrej/php/") && strings.Contains(r.Line, "does not have a Release file")
}

var aptURL = regexp.MustCompile(`(?:https?|ftp|file|cdrom)://[^\s'"\]]+`)

// brokenRepos parses the E: lines of a failed apt-get update that name a repository.
func brokenRepos(err error) []brokenRepo {
	var ee *runner.ExitError
	if !errors.As(err, &ee) {
		return nil
	}
	var out []brokenRepo
	seen := map[string]bool{}
	for _, line := range strings.Split(ee.Stderr, "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "E:") {
			continue
		}
		u := aptURL.FindString(line)
		if u == "" || seen[u] {
			continue
		}
		seen[u] = true
		out = append(out, brokenRepo{URL: u, Line: line})
	}
	return out
}

// RepoError is an apt-get update that failed because of one or more repositories.
type RepoError struct {
	Repos []RepoFailure
	Err   error // the apt-get error
}

// RepoFailure is one failing repository and the source files that configure it.
type RepoFailure struct {
	URL     string
	Files   []string
	Message string
}

func (e *RepoError) Error() string {
	var parts []string
	for _, r := range e.Repos {
		where := "a source in " + AptSourcesList + " or " + AptSourcesDir
		if len(r.Files) > 0 {
			where = strings.Join(r.Files, ", ")
		}
		parts = append(parts, fmt.Sprintf("repository %s (%s) breaks apt-get update: %s", r.URL, where, r.Message))
	}
	return "apt-get update failed: " + strings.Join(parts, "; ") + "; fix or remove that source file and retry"
}

func (e *RepoError) Unwrap() error { return e.Err }

func (a Apt) repoError(broken []brokenRepo, err error) error {
	re := &RepoError{Err: err}
	for _, r := range broken {
		re.Repos = append(re.Repos, RepoFailure{URL: r.URL, Files: a.sourceFiles(r.URL), Message: strings.TrimSpace(strings.TrimPrefix(r.Line, "E:"))})
	}
	return re
}

// sourceFiles returns the apt source files (one-line .list or deb822 .sources) with a URI that u starts with.
func (a Apt) sourceFiles(u string) []string {
	want := strings.TrimRight(u, "/") + "/"
	var out []string
	for _, src := range AptSources(a.FS) {
		for _, uri := range src.URIs {
			if strings.HasPrefix(want, strings.TrimRight(uri, "/")+"/") {
				out = append(out, src.File)
				break
			}
		}
	}
	return out
}

// AptSource is one apt source file and the repository URIs it configures.
type AptSource struct {
	File string   `json:"file"`
	URIs []string `json:"uris"`
}

// AptSources lists the enabled apt source files (sources.list, sources.list.d/*.list and *.sources) with their URIs.
func AptSources(fs hostfs.FS) []AptSource {
	files := []string{AptSourcesList}
	ents, _ := os.ReadDir(fs.P(AptSourcesDir))
	for _, e := range ents {
		if n := e.Name(); strings.HasSuffix(n, ".list") || strings.HasSuffix(n, ".sources") {
			files = append(files, AptSourcesDir+"/"+n)
		}
	}
	out := []AptSource{}
	for _, f := range files {
		b, err := fs.ReadFile(f)
		if err != nil {
			continue
		}
		if uris := sourceURIs(string(b)); len(uris) > 0 {
			out = append(out, AptSource{File: f, URIs: uris})
		}
	}
	return out
}

// sourceURIs extracts repository URIs from a .list ("deb [opts] uri suite ...") or .sources ("URIs: a b") file.
func sourceURIs(content string) []string {
	var out []string
	for _, line := range strings.Split(content, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if k, v, ok := strings.Cut(line, ":"); ok && strings.EqualFold(strings.TrimSpace(k), "URIs") {
			out = append(out, strings.Fields(v)...)
			continue
		}
		if strings.HasPrefix(line, "deb ") || strings.HasPrefix(line, "deb-src ") {
			out = append(out, aptURL.FindAllString(line, -1)...)
		}
	}
	return out
}

// Candidate returns the version apt would install for pkg ("" when no repository has it). Run Update first.
func (a Apt) Candidate(ctx context.Context, pkg string) (string, error) {
	res, err := a.R.Run(ctx, runner.Cmd{Name: "apt-cache", Args: []string{"policy", pkg}, Env: AptEnv})
	if err != nil {
		return "", err
	}
	for _, line := range strings.Split(string(res.Stdout), "\n") {
		if v, ok := strings.CutPrefix(strings.TrimSpace(line), "Candidate:"); ok {
			if v = strings.TrimSpace(v); v != "(none)" {
				return v, nil
			}
		}
	}
	return "", nil
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
	a := AptFor(s.d.Runner, s.d.FS, st)
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
