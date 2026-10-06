package deploy

import (
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/envlinks"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/redact"
	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// DefaultEnvDir holds the sites' env files. /run is a tmpfs: secrets never reach a persistent disk, and after a
// reboot the directory is empty until the control plane sends the files again (MissingSecrets, site.env.write).
const DefaultEnvDir = envlinks.DefaultEnvDir

// envFileMode: the site user (PHP-FPM, workers, cron, hooks) and the site group (the edge user, which runs PHP under
// FrankenPHP, is a member) read it; nobody writes it but the agent.
const envFileMode = 0o440

// ContainerSecrets rewrites the secret files of a site's containers after a reboot and starts the containers that
// could not start without them (implemented by docker.Service). It returns the containers it restored.
type ContainerSecrets interface {
	RestoreSiteSecrets(ctx context.Context, site, releaseID string, files map[string]string, w io.Writer) ([]string, error)
	MissingSecrets(ctx context.Context) ([]string, error)
}

// EnvLinks remembers links to tmpfs secret files (implemented by envlinks.Registry).
type EnvLinks interface {
	Record(site, link, target string) error
	Missing() []string
}

// envFile is the host path of a site's env file.
func (d *Deployer) envFile(site string) string { return filepath.Join(d.o.EnvDir, site+".env") }

// CacheDir is the host path of a site's writable tmpfs directory for caches that hold secrets: Laravel's config cache
// (the control plane sets APP_CONFIG_CACHE=<dir>/config.php in the site's .env, so it never lands in bootstrap/cache).
func CacheDir(envDir, site string) string { return filepath.Join(envDir, site+".d") }

// writeEnvFile writes a site's env file (and creates its cache directory) on the tmpfs.
func (d *Deployer) writeEnvFile(siteName, content string, owner *Owner) (bool, error) {
	if err := envlinks.EnsureDir(d.o.FS.P(d.o.EnvDir)); err != nil {
		return false, err
	}
	hostFile := d.envFile(siteName)
	changed, err := d.o.FS.WriteFile(hostFile, []byte(content), envFileMode)
	if err != nil {
		return false, err
	}
	cache := d.o.FS.P(CacheDir(d.o.EnvDir, siteName))
	if err := os.MkdirAll(cache, 0o750); err != nil {
		return false, err
	}
	if owner != nil && owner.User != "" && d.o.FS.IsReal() {
		if err := d.o.FS.Chown(hostFile, owner.User, owner.Group); err != nil {
			return false, err
		}
		// Laravel writes config.php here as the site user; the site group (edge user) reads it.
		if err := d.o.FS.Chown(CacheDir(d.o.EnvDir, siteName), owner.User, owner.Group); err != nil {
			return false, err
		}
	}
	return changed, nil
}

// writeEnv writes a site's env file to the tmpfs and makes shared/<rel> a symlink to it (replacing the regular file
// earlier versions kept on disk). Releases link their .env to shared/.env, so they all follow.
func (d *Deployer) writeEnv(st site, siteName, rel, content string, owner *Owner) (bool, error) {
	changed, err := d.writeEnvFile(siteName, content, owner)
	if err != nil {
		return false, err
	}
	if _, err := os.Stat(st.shared()); errors.Is(err, fs.ErrNotExist) {
		return changed, nil
	}
	target := d.o.FS.P(d.envFile(siteName))
	linked, err := d.linkInSite(st, filepath.Join("shared", rel), target, owner)
	if err != nil {
		return false, err
	}
	if d.o.Links != nil {
		if err := d.o.Links.Record(siteName, filepath.Join(st.real, "shared", rel), target); err != nil {
			d.o.Logger.Warn("record env link", "err", err)
		}
	}
	return changed || linked, nil
}

// linkInSite makes rel (inside the site directory) a symlink to target. The site user owns the site directory and
// could plant symlinks there: every step goes through an os.Root on the site directory, and any symlink among rel's
// parent directories is refused, so the agent never writes outside the site or through a link.
func (d *Deployer) linkInSite(st site, rel, target string, owner *Owner) (bool, error) {
	root, err := os.OpenRoot(st.real)
	if err != nil {
		return false, err
	}
	defer root.Close()
	parent := filepath.Dir(rel)
	if parent != "." {
		walked := ""
		for _, part := range strings.Split(parent, string(filepath.Separator)) {
			walked = filepath.Join(walked, part)
			fi, err := root.Lstat(walked)
			if errors.Is(err, fs.ErrNotExist) {
				if err := root.Mkdir(walked, 0o755); err != nil {
					return false, err
				}
				continue
			}
			if err != nil {
				return false, err
			}
			if fi.Mode()&fs.ModeSymlink != 0 || !fi.IsDir() {
				return false, fmt.Errorf("%s is a symlink or not a directory: refusing to write the env link through it", walked)
			}
		}
	}
	if cur, err := root.Readlink(rel); err == nil && cur == target {
		return false, nil
	}
	tmp := filepath.Join(parent, ".env.falak-"+randHex(6))
	if err := root.Symlink(target, tmp); err != nil {
		return false, err
	}
	// rename(2) replaces the old file (or link) atomically: a reader sees the old or the new env, never none.
	if err := root.Rename(tmp, rel); err != nil {
		_ = root.Remove(tmp)
		return false, err
	}
	if owner != nil && owner.User != "" && d.o.FS.IsReal() {
		if uid, gid, err := hostfs.LookupIDs(owner.User, owner.Group); err == nil {
			_ = root.Lchown(rel, uid, gid)
		}
	}
	return true, nil
}

// envSecrets returns the values of keys in the site's env file (nothing when it is missing).
func (d *Deployer) envSecrets(site string, keys []string) []string {
	if len(keys) == 0 || !slugRE.MatchString(site) {
		return nil
	}
	b, err := d.o.FS.ReadFile(d.envFile(site))
	if err != nil {
		return nil
	}
	return redact.FromDotenv(string(b), keys)
}

// SiteSecrets returns the values of keys in a site's env file (system.exec masks them: site commands read .env).
func (d *Deployer) SiteSecrets(site string, keys []string) []string { return d.envSecrets(site, keys) }

// MissingEnv lists the sites whose env link dangles (the tmpfs was emptied by a reboot): the links recorded when
// they were written (any sites root, compose releases), plus a scan of the default sites root.
func (d *Deployer) MissingEnv() []string {
	seen := map[string]bool{}
	if d.o.Links != nil {
		for _, s := range d.o.Links.Missing() {
			seen[s] = true
		}
	}
	root, envDir := d.o.FS.P(d.o.SitesRoot), d.o.FS.P(d.o.EnvDir)
	if ents, err := os.ReadDir(root); err == nil {
		for _, e := range ents {
			if !e.IsDir() || !slugRE.MatchString(e.Name()) {
				continue
			}
			target, err := os.Readlink(filepath.Join(root, e.Name(), "shared", ".env"))
			if err != nil || !strings.HasPrefix(target, envDir+"/") {
				continue
			}
			if _, err := os.Stat(target); errors.Is(err, fs.ErrNotExist) {
				seen[e.Name()] = true
			}
		}
	}
	out := make([]string, 0, len(seen))
	for s := range seen {
		out = append(out, s)
	}
	sort.Strings(out)
	return out
}

// MissingSecrets lists the sites whose env file or container secret files must be sent again (heartbeat
// `missing_secrets`).
func (d *Deployer) MissingSecrets(ctx context.Context) []string {
	seen := map[string]bool{}
	for _, s := range d.MissingEnv() {
		seen[s] = true
	}
	if d.o.Containers != nil {
		// Runs with every heartbeat: bounded, and quiet on servers without Docker.
		cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		sites, err := d.o.Containers.MissingSecrets(cctx)
		cancel()
		if err != nil {
			d.o.Logger.Debug("container secrets check failed", "err", err)
		}
		for _, s := range sites {
			seen[s] = true
		}
	}
	out := make([]string, 0, len(seen))
	for s := range seen {
		out = append(out, s)
	}
	sort.Strings(out)
	return out
}

// SecretFile is one file of a container's /run/secrets.
type SecretFile struct {
	Name    string `json:"name"`
	Content string `json:"content"`
}

// AfterWrite runs once the files are back (e.g. `php artisan config:cache`, whose cache lives on the tmpfs too).
type AfterWrite struct {
	Script string `json:"script"`
	User   string `json:"user,omitempty"`
}

// EnvWritePayload is site.env.write: the control plane re-sends a site's secrets after the agent reported them
// missing (a reboot emptied /run).
type EnvWritePayload struct {
	Site      string `json:"site"`
	SitesRoot string `json:"sites_root,omitempty"`
	// ReleaseID is the release the secrets belong to: the write is refused when another release is live by now
	// (a deployment won the race and wrote newer files).
	ReleaseID   string       `json:"release_id"`
	EnvFile     *EnvFile     `json:"env_file,omitempty"`
	Compose     bool         `json:"compose,omitempty"` // env_file is the compose project's (compose-<site>.env)
	Owner       *Owner       `json:"owner,omitempty"`
	SecretFiles []SecretFile `json:"secret_files,omitempty"`
	After       *AfterWrite  `json:"after,omitempty"`
	Reload      []Reload     `json:"reload,omitempty"`
	Mask        []string     `json:"mask,omitempty"`
}

// Secrets are the masked variables of the env file and every secret file.
func (p EnvWritePayload) Secrets() []string {
	var out []string
	if p.EnvFile != nil {
		out = redact.FromDotenv(p.EnvFile.Content, p.Mask)
	}
	for _, f := range p.SecretFiles {
		out = append(out, f.Content)
	}
	return out
}

// EnvWriteResult is site.env.write's result.
type EnvWriteResult struct {
	Changed    bool     `json:"changed"`
	Containers []string `json:"containers"`
}

// WriteEnv is site.env.write.
func (d *Deployer) WriteEnv(ctx context.Context, p EnvWritePayload, s commands.Stream) (any, error) {
	st, err := d.site(p.Site, p.SitesRoot)
	if err != nil {
		return nil, err
	}
	if err := checkRelease(p.ReleaseID); err != nil {
		return nil, err
	}
	unlock := d.lock(p.Site)
	defer unlock()
	res := EnvWriteResult{Containers: []string{}}
	envPath := d.envFile(p.Site)
	if p.Compose {
		envPath = envlinks.ComposeEnvFile(d.o.EnvDir, p.Site, ".env")
	}
	// This restores lost files only: one that exists was written by a deployment since, and is newer.
	if p.EnvFile != nil && d.o.FS.Exists(envPath) {
		fmt.Fprintf(s.Stdout(), "env file of %s is present: left as it is\n", p.Site)
	} else if p.EnvFile != nil {
		var ch bool
		if p.Compose {
			if _, err := os.Stat(st.release(p.ReleaseID)); err != nil {
				return nil, fmt.Errorf("release %s of %s is not on this server: not restoring its env", p.ReleaseID, p.Site)
			}
			if err = envlinks.EnsureDir(d.o.FS.P(d.o.EnvDir)); err == nil {
				ch, err = d.o.FS.WriteFile(envPath, []byte(p.EnvFile.Content), 0o400)
			}
		} else {
			if cur, cerr := currentRelease(st); cerr != nil || cur != p.ReleaseID {
				return nil, fmt.Errorf("release %s of %s is not current (current: %s): not restoring its env", p.ReleaseID, p.Site, orDefault(cur, "none"))
			}
			rel := ".env"
			if p.EnvFile.Path != "" {
				if rel, err = cleanRel(p.EnvFile.Path); err != nil {
					return nil, err
				}
			}
			ch, err = d.writeEnv(st, p.Site, rel, p.EnvFile.Content, p.Owner)
		}
		if err != nil {
			return nil, err
		}
		res.Changed = ch
		fmt.Fprintf(s.Stdout(), "env file of %s written\n", p.Site)
	}
	if len(p.SecretFiles) > 0 {
		if d.o.Containers == nil {
			return nil, errors.New("docker unavailable")
		}
		files := make(map[string]string, len(p.SecretFiles))
		for _, f := range p.SecretFiles {
			files[f.Name] = f.Content
		}
		names, err := d.o.Containers.RestoreSiteSecrets(ctx, p.Site, p.ReleaseID, files, s.Stdout())
		if err != nil {
			return nil, err
		}
		res.Containers = append(res.Containers, names...)
		res.Changed = res.Changed || len(names) > 0
	}
	if p.After != nil && p.After.Script != "" {
		fmt.Fprintf(s.Stdout(), "$ running as %s\n", orDefault(p.After.User, "agent user"))
		r, err := d.o.Runner.Run(ctx, runner.Cmd{Name: "/bin/bash", Args: []string{"-eo", "pipefail", "-c", p.After.Script}, Dir: st.current(),
			User: p.After.User, Stdout: s.Stdout(), Stderr: s.Stderr()})
		if err == nil && r.ExitCode != 0 {
			err = fmt.Errorf("exited with status %d", r.ExitCode)
		}
		if err != nil {
			return res, fmt.Errorf("after restoring the env: %w", err)
		}
	}
	if err := d.reload(ctx, p.Reload, s); err != nil {
		return res, err
	}
	return res, nil
}
