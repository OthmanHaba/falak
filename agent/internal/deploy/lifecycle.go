package deploy

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
)

// SharedPath is a path linked from the release into shared/.
type SharedPath struct {
	Path string `json:"path"`
	Type string `json:"type,omitempty"` // dir (default) | file
}

// EnvFile is written into shared/.
type EnvFile struct {
	Content string `json:"content"`
	Path    string `json:"path,omitempty"`
}

// PreparePayload is deploy.prepare.
type PreparePayload struct {
	Site         string        `json:"site"`
	ReleaseID    string        `json:"release_id"`
	SitesRoot    string        `json:"sites_root,omitempty"`
	SharedPaths  *[]SharedPath `json:"shared_paths,omitempty"`
	EnvFile      *EnvFile      `json:"env_file,omitempty"`
	Owner        *Owner        `json:"owner,omitempty"`
	WritableDirs []string      `json:"writable_dirs,omitempty"`
	Context      *Context      `json:"context,omitempty"`
}

// PrepareResult is deploy.prepare's result.
type PrepareResult struct {
	Changed bool     `json:"changed"`
	Links   []string `json:"links"`
}

// DefaultShared matches the schema default.
var DefaultShared = []SharedPath{{Path: "storage", Type: "dir"}, {Path: ".env", Type: "file"}}

// Prepare creates shared paths, seeds them from the first release that ships them, and replaces the
// release copies with relative symlinks into shared/.
func (d *Deployer) Prepare(ctx context.Context, p PreparePayload, s commands.Stream) (_ any, err error) {
	defer d.failed(lifecycle{site: p.Site, phase: PhasePrepare, releaseID: p.ReleaseID, ctx: p.Context}, &err)
	st, err := d.site(p.Site, p.SitesRoot)
	if err != nil {
		return nil, err
	}
	if err := checkRelease(p.ReleaseID); err != nil {
		return nil, err
	}
	rel := st.release(p.ReleaseID)
	if _, err := os.Stat(rel); err != nil {
		return nil, fmt.Errorf("release %s not fetched", p.ReleaseID)
	}
	shared := DefaultShared
	if p.SharedPaths != nil {
		shared = *p.SharedPaths
	}
	if err := os.MkdirAll(st.shared(), 0o755); err != nil {
		return nil, err
	}
	res := PrepareResult{Links: []string{}}
	chownHost := func(real string) error {
		if p.Owner == nil || p.Owner.User == "" || !d.o.FS.IsReal() {
			return nil
		}
		return d.o.FS.Chown(real, p.Owner.User, p.Owner.Group)
	}
	// shared/ holds .env and storage (logs, sessions, uploads): owned by the site user and closed to other local
	// users like the releases (the site group and the edge user keep access).
	if err := chownHost(filepath.Join(st.host, "shared")); err != nil {
		return nil, err
	}
	if _, err := closeDir(st.shared()); err != nil {
		return nil, err
	}
	if p.EnvFile != nil {
		envPath := p.EnvFile.Path
		if envPath == "" {
			envPath = ".env"
		}
		c, err := cleanRel(envPath)
		if err != nil {
			return nil, err
		}
		// Env files hold secrets: 0640, never world-readable.
		ch, err := d.o.FS.WriteFile(filepath.Join(st.host, "shared", c), []byte(p.EnvFile.Content), 0o640)
		if err != nil {
			return nil, err
		}
		if err := chownHost(filepath.Join(st.shared(), c)); err != nil {
			return nil, err
		}
		res.Changed = res.Changed || ch
	}
	for _, sp := range shared {
		c, err := cleanRel(sp.Path)
		if err != nil {
			return nil, err
		}
		sharedTarget := filepath.Join(st.shared(), c)
		inRelease := filepath.Join(rel, c)
		if err := noSymlinkParents(rel, filepath.Dir(inRelease)); err != nil {
			return nil, fmt.Errorf("shared path %s: %w", c, err)
		}
		// Seed shared/ from the release on first deploy (e.g. storage/ skeleton).
		if _, err := os.Lstat(sharedTarget); errors.Is(err, fs.ErrNotExist) {
			if err := os.MkdirAll(filepath.Dir(sharedTarget), 0o755); err != nil {
				return nil, err
			}
			if li, err := os.Lstat(inRelease); err == nil && li.Mode()&os.ModeSymlink == 0 {
				if err := os.Rename(inRelease, sharedTarget); err != nil {
					return nil, err
				}
			} else if sp.Type == "file" {
				if err := os.WriteFile(sharedTarget, nil, 0o640); err != nil {
					return nil, err
				}
			} else if err := os.MkdirAll(sharedTarget, 0o775); err != nil {
				return nil, err
			}
			if err := chownHost(sharedTarget); err != nil {
				return nil, err
			}
			res.Changed = true
		}
		want, err := relLink(inRelease, sharedTarget)
		if err != nil {
			return nil, err
		}
		if cur, err := os.Readlink(inRelease); err == nil && cur == want {
			res.Links = append(res.Links, c)
			continue
		}
		if err := os.RemoveAll(inRelease); err != nil {
			return nil, err
		}
		if err := os.MkdirAll(filepath.Dir(inRelease), 0o755); err != nil {
			return nil, err
		}
		if err := os.Symlink(want, inRelease); err != nil {
			return nil, err
		}
		if p.Owner != nil && p.Owner.User != "" && d.o.FS.IsReal() {
			_ = d.o.FS.Chown(inRelease, p.Owner.User, p.Owner.Group)
		}
		res.Links = append(res.Links, c)
		res.Changed = true
		fmt.Fprintf(s.Stdout(), "linked %s -> %s\n", c, want)
	}
	for _, w := range p.WritableDirs {
		c, err := cleanRel(w)
		if err != nil {
			return nil, err
		}
		dir := filepath.Join(rel, c)
		if err := os.MkdirAll(dir, 0o775); err != nil {
			return nil, err
		}
		ch, err := groupWritable(st.real, dir)
		if err != nil {
			return nil, fmt.Errorf("writable dir %s: %w", c, err)
		}
		res.Changed = res.Changed || ch
		if err := chownHost(dir); err != nil {
			return nil, err
		}
	}
	return res, nil
}

// Context of a deployment exposed as KILN_* variables.
type Context struct {
	SiteID       string `json:"site_id,omitempty"`
	DeploymentID string `json:"deployment_id,omitempty"`
	Commit       string `json:"commit,omitempty"`
	Author       string `json:"author,omitempty"`
	Branch       string `json:"branch,omitempty"`
	Trigger      string `json:"trigger,omitempty"`
	PHPBinary    string `json:"php_binary,omitempty"`
}

// HookPayload is deploy.hook.
type HookPayload struct {
	Site      string            `json:"site"`
	ReleaseID string            `json:"release_id"`
	SitesRoot string            `json:"sites_root,omitempty"`
	Name      string            `json:"name"`
	Script    string            `json:"script"`
	Shell     string            `json:"shell,omitempty"`
	User      string            `json:"user,omitempty"`
	Cwd       string            `json:"cwd,omitempty"`
	Env       map[string]string `json:"env,omitempty"`
	Context   *Context          `json:"context,omitempty"`
}

// HookResult is deploy.hook's result.
type HookResult struct {
	ExitCode   int   `json:"exit_code"`
	DurationMS int64 `json:"duration_ms"`
}

// HookEnv builds the KILN_* environment for a hook.
func HookEnv(site, siteRoot, releaseDir, releaseID string, c *Context, extra map[string]string) []string {
	if c == nil {
		c = &Context{}
	}
	php := c.PHPBinary
	if php == "" {
		php = "php"
	}
	env := []string{
		"KILN_SITE=" + site,
		"KILN_SITE_ROOT=" + siteRoot,
		"KILN_SHARED_DIR=" + filepath.Join(siteRoot, "shared"),
		"KILN_RELEASE_DIR=" + releaseDir,
		"KILN_RELEASE_ID=" + releaseID,
		"KILN_CURRENT_DIR=" + filepath.Join(siteRoot, "current"),
		"KILN_SITE_ID=" + c.SiteID,
		"KILN_DEPLOYMENT_ID=" + c.DeploymentID,
		"KILN_COMMIT=" + c.Commit,
		"KILN_AUTHOR=" + c.Author,
		"KILN_BRANCH=" + c.Branch,
		"KILN_TRIGGER=" + c.Trigger,
		"KILN_PHP_BINARY=" + php,
		// Macros are split out by the control plane into separate commands; inside a hook they are no-ops.
		"KILN_FETCH=:", "KILN_ACTIVATE=:", "KILN_RESTART_PROCS=:",
	}
	keys := make([]string, 0, len(extra))
	for k := range extra {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		env = append(env, k+"="+extra[k])
	}
	return env
}

// Hook runs one deploy script step. A non-zero exit fails the command with that exit code.
func (d *Deployer) Hook(ctx context.Context, p HookPayload, s commands.Stream) (_ any, err error) {
	defer d.failed(lifecycle{site: p.Site, phase: PhaseHook, releaseID: p.ReleaseID, hook: p.Name, ctx: p.Context}, &err)
	st, err := d.site(p.Site, p.SitesRoot)
	if err != nil {
		return nil, err
	}
	if err := checkRelease(p.ReleaseID); err != nil {
		return nil, err
	}
	shell := p.Shell
	if shell == "" {
		shell = "/bin/bash"
	}
	releaseHost := st.hostRelease(p.ReleaseID)
	var cwd string
	switch p.Cwd {
	case "", "release":
		cwd = st.release(p.ReleaseID)
	case "current":
		cwd = st.current()
	case "site_root":
		cwd = st.real
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid cwd %q", p.Cwd)}
	}
	if _, err := os.Stat(cwd); err != nil {
		return nil, fmt.Errorf("hook cwd: %w", err)
	}
	args := []string{"-c", p.Script}
	if strings.HasSuffix(shell, "bash") {
		args = []string{"-eo", "pipefail", "-c", p.Script}
	}
	env := HookEnv(p.Site, st.host, releaseHost, p.ReleaseID, p.Context, p.Env)
	env = append(env, "KILN_HOOK="+p.Name)
	start := time.Now()
	fmt.Fprintf(s.Stdout(), "$ [%s] running as %s in %s\n", p.Name, orDefault(p.User, "agent user"), p.Cwd)
	r, err := d.o.Runner.Run(ctx, runner.Cmd{Name: shell, Args: args, Dir: cwd, Env: env, User: p.User, Stdout: s.Stdout(), Stderr: s.Stderr()})
	res := HookResult{ExitCode: r.ExitCode, DurationMS: time.Since(start).Milliseconds()}
	if err != nil {
		return res, err
	}
	if r.ExitCode != 0 {
		return res, &commands.ExitError{Code: r.ExitCode, Err: fmt.Errorf("hook %s exited with status %d", p.Name, r.ExitCode)}
	}
	return res, nil
}

func orDefault(s, d string) string {
	if s == "" {
		return d
	}
	return s
}

// Reload is an action after a swap.
type Reload struct {
	Kind string `json:"kind"`
	Name string `json:"name,omitempty"`
}

// ActivatePayload is deploy.activate.
type ActivatePayload struct {
	Site      string   `json:"site"`
	ReleaseID string   `json:"release_id"`
	SitesRoot string   `json:"sites_root,omitempty"`
	Reload    []Reload `json:"reload,omitempty"`
	Context   *Context `json:"context,omitempty"`
}

// ActivateResult is deploy.activate's result.
type ActivateResult struct {
	Changed           bool   `json:"changed"`
	PreviousReleaseID string `json:"previous_release_id,omitempty"`
	ReleaseID         string `json:"release_id"`
}

// Activate atomically swaps `current` and runs reloads when it changed.
func (d *Deployer) Activate(ctx context.Context, p ActivatePayload, s commands.Stream) (_ any, err error) {
	lc := lifecycle{site: p.Site, phase: PhaseActivate, releaseID: p.ReleaseID, ctx: p.Context}
	defer d.failed(lc, &err)
	st, err := d.site(p.Site, p.SitesRoot)
	if err != nil {
		return nil, err
	}
	if err := checkRelease(p.ReleaseID); err != nil {
		return nil, err
	}
	unlock := d.lock(p.Site)
	defer unlock()
	prev, err := currentRelease(st)
	if err != nil {
		return nil, err
	}
	res := ActivateResult{PreviousReleaseID: prev, ReleaseID: p.ReleaseID}
	if prev == p.ReleaseID {
		fmt.Fprintf(s.Stdout(), "release %s already active\n", p.ReleaseID)
		return res, nil
	}
	if err := swapCurrent(st, p.ReleaseID); err != nil {
		return nil, err
	}
	res.Changed = true
	fmt.Fprintf(s.Stdout(), "current -> releases/%s (was %s)\n", p.ReleaseID, orDefault(prev, "none"))
	if err := d.reload(ctx, p.Reload, s); err != nil {
		return res, err
	}
	d.emit(lc, StatusSucceeded, nil)
	return res, nil
}

// RollbackPayload is deploy.rollback.
type RollbackPayload struct {
	Site      string   `json:"site"`
	SitesRoot string   `json:"sites_root,omitempty"`
	ReleaseID string   `json:"release_id,omitempty"`
	Reload    []Reload `json:"reload,omitempty"`
	Context   *Context `json:"context,omitempty"`
}

// RollbackResult is deploy.rollback's result.
type RollbackResult struct {
	Changed       bool   `json:"changed"`
	FromReleaseID string `json:"from_release_id,omitempty"`
	ToReleaseID   string `json:"to_release_id"`
}

// Rollback points current at the given release, or the newest release older than current.
func (d *Deployer) Rollback(ctx context.Context, p RollbackPayload, s commands.Stream) (_ any, err error) {
	lc := lifecycle{site: p.Site, phase: PhaseRollback, releaseID: p.ReleaseID, ctx: p.Context}
	defer d.failed(lc, &err)
	st, err := d.site(p.Site, p.SitesRoot)
	if err != nil {
		return nil, err
	}
	unlock := d.lock(p.Site)
	defer unlock()
	cur, err := currentRelease(st)
	if err != nil {
		return nil, err
	}
	target := p.ReleaseID
	if target == "" {
		ids, err := listReleases(st)
		if err != nil {
			return nil, err
		}
		for i := len(ids) - 1; i >= 0; i-- {
			if cur == "" || ids[i] < cur {
				target = ids[i]
				break
			}
		}
		if target == "" {
			return nil, errors.New("no earlier release to roll back to")
		}
	} else if err := checkRelease(target); err != nil {
		return nil, err
	}
	res := RollbackResult{FromReleaseID: cur, ToReleaseID: target}
	if cur == target {
		return res, nil
	}
	if err := swapCurrent(st, target); err != nil {
		return nil, err
	}
	res.Changed = true
	fmt.Fprintf(s.Stdout(), "rolled back current: %s -> %s\n", orDefault(cur, "none"), target)
	lc.releaseID = target
	if err := d.reload(ctx, p.Reload, s); err != nil {
		return res, err
	}
	d.emit(lc, StatusRolledBack, nil)
	return res, nil
}

// PrunePayload is deploy.prune.
type PrunePayload struct {
	Site      string   `json:"site"`
	SitesRoot string   `json:"sites_root,omitempty"`
	Keep      int      `json:"keep,omitempty"`
	Protect   []string `json:"protect,omitempty"`
}

// PruneResult is deploy.prune's result.
type PruneResult struct {
	Changed bool     `json:"changed"`
	Removed []string `json:"removed"`
	Kept    []string `json:"kept"`
}

// Prune keeps the newest `keep` releases plus current and protected ones.
func (d *Deployer) Prune(ctx context.Context, p PrunePayload, s commands.Stream) (any, error) {
	st, err := d.site(p.Site, p.SitesRoot)
	if err != nil {
		return nil, err
	}
	keep := p.Keep
	if keep <= 0 {
		keep = 5
	}
	unlock := d.lock(p.Site)
	defer unlock()
	cur, err := currentRelease(st)
	if err != nil {
		return nil, err
	}
	ids, err := listReleases(st)
	if err != nil {
		return nil, err
	}
	protected := map[string]bool{cur: true}
	for _, id := range p.Protect {
		protected[id] = true
	}
	res := PruneResult{Removed: []string{}, Kept: []string{}}
	for i := len(ids) - 1; i >= 0; i-- {
		id := ids[i]
		if protected[id] || len(ids)-i <= keep {
			res.Kept = append(res.Kept, id)
			continue
		}
		if err := os.RemoveAll(st.release(id)); err != nil {
			return res, err
		}
		res.Removed = append(res.Removed, id)
		fmt.Fprintf(s.Stdout(), "removed release %s\n", id)
	}
	// Leftovers of interrupted fetches.
	if ents, err := os.ReadDir(st.releases()); err == nil {
		for _, e := range ents {
			if strings.HasPrefix(e.Name(), ".") && strings.Contains(e.Name(), ".partial-") {
				if info, err := e.Info(); err == nil && time.Since(info.ModTime()) > time.Hour {
					_ = os.RemoveAll(filepath.Join(st.releases(), e.Name()))
				}
			}
		}
	}
	res.Changed = len(res.Removed) > 0
	return res, nil
}

var phpVersionRE = strings.NewReplacer(".", "")

func (d *Deployer) reload(ctx context.Context, rs []Reload, s commands.Stream) error {
	var errs []error
	for _, r := range rs {
		var err error
		switch r.Kind {
		case "php_fpm":
			if _, perr := strconv.ParseFloat(r.Name, 64); perr != nil || phpVersionRE.Replace(r.Name) == "" {
				err = fmt.Errorf("php_fpm reload needs a version name, got %q", r.Name)
				break
			}
			_, err = runner.Check(ctx, d.o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"reload", "php" + r.Name + "-fpm"}, Stderr: s.Stderr()})
		case "systemd":
			_, err = runner.Check(ctx, d.o.Runner, runner.Cmd{Name: "systemctl", Args: []string{"reload-or-restart", r.Name}, Stderr: s.Stderr()})
		case "proc":
			if d.o.Procs == nil {
				err = errors.New("process supervisor unavailable")
				break
			}
			var names []string
			if r.Name != "" {
				names = []string{r.Name}
			}
			err = d.o.Procs.Restart(ctx, names)
		case "frankenphp":
			if d.o.Workers == nil {
				err = errors.New("edge client unavailable")
				break
			}
			err = d.o.Workers.ReloadFrankenPHP(ctx)
		default:
			err = fmt.Errorf("unknown reload kind %q", r.Kind)
		}
		if err != nil {
			errs = append(errs, fmt.Errorf("reload %s %s: %w", r.Kind, r.Name, err))
		} else {
			fmt.Fprintf(s.Stdout(), "reloaded %s %s\n", r.Kind, r.Name)
		}
	}
	return errors.Join(errs...)
}

// groupWritable makes a writable dir (often a symlink into shared/, e.g. storage) group-writable all
// the way down: dirs 2775 (setgid keeps the site group on new files) with a default ACL that gives new
// files group write regardless of the creating process's umask, files g+w. The web server joins
// the site group, so under FrankenPHP (which runs PHP as the edge user) Laravel can write logs, cache
// and sessions. The target must stay inside the site root, and symlinks inside it are never followed.
func groupWritable(siteRoot, dir string) (bool, error) {
	real, err := filepath.EvalSymlinks(dir)
	if err != nil {
		return false, err
	}
	root, err := filepath.EvalSymlinks(siteRoot)
	if err != nil {
		return false, err
	}
	if real != root && !strings.HasPrefix(real, root+string(filepath.Separator)) {
		return false, fmt.Errorf("%s resolves outside the site (%s)", dir, real)
	}
	changed := false
	err = filepath.WalkDir(real, func(p string, e fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if e.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		info, err := e.Info()
		if err != nil {
			return err
		}
		want := info.Mode().Perm() | 0o020
		if e.IsDir() {
			want = 0o775 | fs.ModeSetgid
			// New files get group write whoever creates them (see groupSharedDefaultACL).
			ch, err := setGroupSharedACL(p)
			if err != nil {
				return fmt.Errorf("default ACL on %s: %w", p, err)
			}
			changed = changed || ch
		}
		if info.Mode()&(fs.ModePerm|fs.ModeSetgid) == want {
			return nil
		}
		changed = true
		return os.Chmod(p, want)
	})
	return changed, err
}
