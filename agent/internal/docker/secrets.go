package docker

import (
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// DefaultSecretsDir holds the containers' secret files: /run/falak/secrets/<container>/<NAME>, bind-mounted read-only
// at SecretsTarget. /run is a tmpfs, so the files never reach a persistent disk; after a reboot they are gone until
// the control plane sends them again (MissingSecrets, site.env.write → RestoreSiteSecrets).
//
// Ownership: the parent directory is 0700 root, so no host user other than root reaches any file (Docker resolves
// the bind as root). Inside the container the files must be readable by the image's user, which the agent usually
// doesn't know: they are 0444 in a 0555 directory, unless the payload's user is numeric ("1000" or "1000:1000"),
// then they belong to that uid/gid, 0400 in a 0500 directory.
const DefaultSecretsDir = "/run/falak/secrets"

// SecretsTarget is where the files appear inside the container.
const SecretsTarget = "/run/secrets"

// Labels of containers with secret files.
const (
	LabelSecrets      = "falak.secrets"       // "files"
	LabelSecretsOwner = "falak.secrets.owner" // "uid:gid" when the files belong to the container's user
)

// SecretFile is one file of a container's /run/secrets (its name is the variable's).
type SecretFile struct {
	Name    string `json:"name"`
	Content string `json:"content"`
}

var secretNameRe = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

// secretValues are the payload's secrets: masked env values and every secret file.
func secretValues(env map[string]string, mask []string, files []SecretFile) []string {
	var out []string
	for _, k := range mask {
		if v, ok := env[k]; ok {
			out = append(out, v)
		}
	}
	for _, f := range files {
		out = append(out, f.Content)
	}
	return out
}

// numericOwner parses a container user "uid" or "uid:gid" ("" when it is a name or empty).
func numericOwner(user string) string {
	u, g, ok := strings.Cut(user, ":")
	if _, err := strconv.Atoi(u); err != nil {
		return ""
	}
	if !ok {
		g = u
	}
	if _, err := strconv.Atoi(g); err != nil {
		return ""
	}
	return u + ":" + g
}

func (s *Service) secretsDir(container string) string {
	return s.opts.FS.P(filepath.Join(s.opts.SecretsDir, container))
}

// withSecrets mounts the container's secret directory and drops env variables that are passed as files instead.
func (s *Service) withSecrets(b *CreateBody, container, user string, files []SecretFile) {
	if len(files) == 0 {
		return
	}
	names := map[string]bool{}
	for _, f := range files {
		names[f.Name] = true
	}
	env := b.Env[:0]
	for _, kv := range b.Env {
		if k, _, _ := strings.Cut(kv, "="); !names[k] {
			env = append(env, kv)
		}
	}
	b.Env = env
	b.HostConfig.Mounts = append(b.HostConfig.Mounts, Mount{Type: "bind", Source: s.secretsDir(container), Target: SecretsTarget, ReadOnly: true})
	b.Labels[LabelSecrets] = "files"
	if o := numericOwner(user); o != "" {
		b.Labels[LabelSecretsOwner] = o
	}
}

// writeSecrets replaces a container's secret directory with files (built aside, then renamed into place).
func (s *Service) writeSecrets(container, owner string, files []SecretFile) error {
	parent := s.opts.FS.P(s.opts.SecretsDir)
	if err := os.MkdirAll(parent, 0o700); err != nil {
		return err
	}
	if err := os.Chmod(parent, 0o700); err != nil {
		return err
	}
	tmp, err := os.MkdirTemp(parent, "."+container+".tmp-")
	if err != nil {
		return err
	}
	defer removeDir(tmp)
	uid, gid := -1, -1
	fileMode, dirMode := os.FileMode(0o444), os.FileMode(0o555)
	if owner != "" {
		u, g, _ := strings.Cut(owner, ":")
		uid, _ = strconv.Atoi(u)
		gid, _ = strconv.Atoi(g)
		fileMode, dirMode = 0o400, 0o500
	}
	for _, f := range files {
		if !secretNameRe.MatchString(f.Name) {
			return fmt.Errorf("invalid secret file name %q", f.Name)
		}
		p := filepath.Join(tmp, f.Name)
		if err := os.WriteFile(p, []byte(f.Content), 0o400); err != nil {
			return err
		}
		if err := os.Chmod(p, fileMode); err != nil {
			return err
		}
		if uid >= 0 && s.opts.FS.IsReal() {
			if err := os.Chown(p, uid, gid); err != nil {
				return err
			}
		}
	}
	if uid >= 0 && s.opts.FS.IsReal() {
		if err := os.Chown(tmp, uid, gid); err != nil {
			return err
		}
	}
	if err := os.Chmod(tmp, dirMode); err != nil {
		return err
	}
	dir := s.secretsDir(container)
	if err := removeDir(dir); err != nil {
		return err
	}
	return os.Rename(tmp, dir)
}

// removeSecrets deletes a container's secret directory (the container is gone).
func (s *Service) removeSecrets(container string) {
	if container == "" {
		return
	}
	_ = removeDir(s.secretsDir(container))
}

// removeDir removes a secret directory, which is read-only (only root could remove it as it is).
func removeDir(dir string) error {
	if err := os.Chmod(dir, 0o700); errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	return os.RemoveAll(dir)
}

func containerName(c ContainerSummary) string {
	if len(c.Names) == 0 {
		return ""
	}
	return strings.TrimPrefix(c.Names[0], "/")
}

// MissingSecrets lists the sites with a container whose secret directory is gone (a reboot emptied /run): Docker
// could not start it (the bind source must exist), and it waits for site.env.write.
func (s *Service) MissingSecrets(ctx context.Context) ([]string, error) {
	list, err := s.c.ContainerList(ctx, true, []string{LabelSecrets + "=files"})
	if err != nil {
		return nil, err
	}
	seen := map[string]bool{}
	var out []string
	for _, c := range list {
		site := c.Labels[LabelSite]
		if site == "" || seen[site] {
			continue
		}
		if _, err := os.Stat(s.secretsDir(containerName(c))); errors.Is(err, fs.ErrNotExist) {
			seen[site] = true
			out = append(out, site)
		}
	}
	sort.Strings(out)
	return out, nil
}

// RestoreSiteSecrets writes files into the secret directory of every container of the site that has them, and starts
// those that are not running (they could not start while the directory was missing).
func (s *Service) RestoreSiteSecrets(ctx context.Context, site string, files map[string]string, w io.Writer) ([]string, error) {
	list, err := s.c.ContainerList(ctx, true, []string{LabelSite + "=" + site, LabelSecrets + "=files"})
	if err != nil {
		return nil, err
	}
	sf := make([]SecretFile, 0, len(files))
	for name, content := range files {
		sf = append(sf, SecretFile{Name: name, Content: content})
	}
	sort.Slice(sf, func(i, j int) bool { return sf[i].Name < sf[j].Name })
	var restored []string
	for _, c := range list {
		name := containerName(c)
		_, err := os.Stat(s.secretsDir(name))
		missing := errors.Is(err, fs.ErrNotExist)
		if err := s.writeSecrets(name, c.Labels[LabelSecretsOwner], sf); err != nil {
			return restored, fmt.Errorf("secrets of %s: %w", name, err)
		}
		// Only a container that could not start without its files is started (a stopped one stays stopped).
		if missing && c.State != "running" {
			if err := s.c.ContainerStart(ctx, c.ID); err != nil {
				return restored, fmt.Errorf("starting %s: %w", name, err)
			}
			fmt.Fprintf(w, "started %s\n", name)
		}
		fmt.Fprintf(w, "secret files of %s written\n", name)
		restored = append(restored, name)
	}
	return restored, nil
}
