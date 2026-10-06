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
	"github.com/OthmanHaba/falak/agent/internal/redact"
)

// DefaultEnvDir holds the sites' env files. /run is a tmpfs: secrets never reach a persistent disk, and after a
// reboot the directory is empty until the control plane sends the files again (MissingSecrets, site.env.write).
const DefaultEnvDir = "/run/falak/env"

// envFileMode: the site user (PHP-FPM, workers, cron, hooks) and the site group (the edge user, which runs PHP under
// FrankenPHP, is a member) read it; nobody writes it but the agent.
const envFileMode = 0o440

// ContainerSecrets rewrites the secret files of a site's containers after a reboot and starts the containers that
// could not start without them (implemented by docker.Service). It returns the containers it restored.
type ContainerSecrets interface {
	RestoreSiteSecrets(ctx context.Context, site string, files map[string]string, w io.Writer) ([]string, error)
	MissingSecrets(ctx context.Context) ([]string, error)
}

// envFile is the host path of a site's env file.
func (d *Deployer) envFile(site string) string { return filepath.Join(d.o.EnvDir, site+".env") }

// writeEnv writes a site's env file to the tmpfs and makes shared/<rel> a symlink to it (replacing the regular file
// earlier versions kept on disk). Releases link their .env to shared/.env, so they all follow.
func (d *Deployer) writeEnv(st site, siteName, rel, content string, owner *Owner) (bool, error) {
	dir := d.o.FS.P(d.o.EnvDir)
	if err := os.MkdirAll(dir, 0o711); err != nil {
		return false, err
	}
	// Site users must reach their own file but not list the others.
	if err := os.Chmod(dir, 0o711); err != nil {
		return false, err
	}
	hostFile := d.envFile(siteName)
	changed, err := d.o.FS.WriteFile(hostFile, []byte(content), envFileMode)
	if err != nil {
		return false, err
	}
	if owner != nil && owner.User != "" && d.o.FS.IsReal() {
		if err := d.o.FS.Chown(hostFile, owner.User, owner.Group); err != nil {
			return false, err
		}
	}
	if _, err := os.Stat(st.shared()); errors.Is(err, fs.ErrNotExist) {
		return changed, nil
	}
	link, target := filepath.Join(st.shared(), rel), d.o.FS.P(hostFile)
	if cur, err := os.Readlink(link); err == nil && cur == target {
		return changed, nil
	}
	if err := os.MkdirAll(filepath.Dir(link), 0o755); err != nil {
		return false, err
	}
	tmp := filepath.Join(filepath.Dir(link), ".env.falak-"+randHex(6))
	if err := os.Symlink(target, tmp); err != nil {
		return false, err
	}
	// rename(2) replaces the old file (or link) atomically: a reader sees the old or the new env, never none.
	if err := os.Rename(tmp, link); err != nil {
		os.Remove(tmp)
		return false, err
	}
	if owner != nil && owner.User != "" && d.o.FS.IsReal() {
		_ = d.o.FS.Chown(filepath.Join(st.host, "shared", rel), owner.User, owner.Group)
	}
	return true, nil
}

// envSecrets returns the values of keys in the site's env file (nothing when it is missing).
func (d *Deployer) envSecrets(site string, keys []string) []string {
	if len(keys) == 0 {
		return nil
	}
	b, err := d.o.FS.ReadFile(d.envFile(site))
	if err != nil {
		return nil
	}
	return redact.FromDotenv(string(b), keys)
}

// MissingEnv lists the sites whose shared/.env links to an env file that is gone (the tmpfs was emptied by a reboot).
func (d *Deployer) MissingEnv() []string {
	root, envDir := d.o.FS.P(d.o.SitesRoot), d.o.FS.P(d.o.EnvDir)
	ents, err := os.ReadDir(root)
	if err != nil {
		return nil
	}
	var out []string
	for _, e := range ents {
		if !e.IsDir() || !slugRE.MatchString(e.Name()) {
			continue
		}
		target, err := os.Readlink(filepath.Join(root, e.Name(), "shared", ".env"))
		if err != nil || !strings.HasPrefix(target, envDir+"/") {
			continue
		}
		if _, err := os.Stat(target); errors.Is(err, fs.ErrNotExist) {
			out = append(out, e.Name())
		}
	}
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

// EnvWritePayload is site.env.write: the control plane re-sends a site's secrets after the agent reported them
// missing (a reboot emptied /run).
type EnvWritePayload struct {
	Site        string       `json:"site"`
	SitesRoot   string       `json:"sites_root,omitempty"`
	EnvFile     *EnvFile     `json:"env_file,omitempty"`
	Owner       *Owner       `json:"owner,omitempty"`
	SecretFiles []SecretFile `json:"secret_files,omitempty"`
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
	res := EnvWriteResult{Containers: []string{}}
	if p.EnvFile != nil {
		rel := ".env"
		if p.EnvFile.Path != "" {
			if rel, err = cleanRel(p.EnvFile.Path); err != nil {
				return nil, err
			}
		}
		ch, err := d.writeEnv(st, p.Site, rel, p.EnvFile.Content, p.Owner)
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
		names, err := d.o.Containers.RestoreSiteSecrets(ctx, p.Site, files, s.Stdout())
		if err != nil {
			return nil, err
		}
		res.Containers = append(res.Containers, names...)
		res.Changed = res.Changed || len(names) > 0
	}
	return res, nil
}
