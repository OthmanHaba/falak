// Package envlinks remembers the symlinks that point at secret files on the tmpfs (a site's shared/.env, a compose
// release's .env), so that after a reboot the agent can tell which sites lost their secrets wherever they live
// (custom sites roots, compose release directories). The registry holds paths only, never secrets.
package envlinks

import (
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
)

// DefaultEnvDir holds the env files of sites and compose projects (a tmpfs).
const DefaultEnvDir = "/run/falak/env"

// Entry is one link: Link (real path) points at Target (real path) and belongs to Site.
type Entry struct {
	Site   string `json:"site"`
	Target string `json:"target"`
}

// Registry is persisted as JSON at Path ("" keeps it in memory).
type Registry struct {
	Path string

	mu      sync.Mutex
	loaded  bool
	entries map[string]Entry // link → entry
}

// New returns a registry stored at path.
func New(path string) *Registry { return &Registry{Path: path} }

func (r *Registry) load() {
	if r.loaded {
		return
	}
	r.loaded, r.entries = true, map[string]Entry{}
	if r.Path == "" {
		return
	}
	if b, err := os.ReadFile(r.Path); err == nil {
		_ = json.Unmarshal(b, &r.entries)
	}
	if r.entries == nil {
		r.entries = map[string]Entry{}
	}
}

func (r *Registry) save() error {
	if r.Path == "" {
		return nil
	}
	b, err := json.Marshal(r.entries)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(r.Path), 0o700); err != nil {
		return err
	}
	tmp := r.Path + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, r.Path)
}

// Record remembers that link points at target for site.
func (r *Registry) Record(site, link, target string) error {
	if r == nil {
		return nil
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	r.load()
	if cur, ok := r.entries[link]; ok && cur == (Entry{site, target}) {
		return nil
	}
	r.entries[link] = Entry{Site: site, Target: target}
	return r.save()
}

// Missing returns the sites with a recorded link that still points at its target while the target is gone. Links
// that were removed or repointed are forgotten.
func (r *Registry) Missing() []string {
	if r == nil {
		return nil
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	r.load()
	seen := map[string]bool{}
	changed := false
	for link, e := range r.entries {
		cur, err := os.Readlink(link)
		if err != nil || cur != e.Target {
			delete(r.entries, link)
			changed = true
			continue
		}
		if _, err := os.Stat(e.Target); errors.Is(err, fs.ErrNotExist) {
			seen[e.Site] = true
		}
	}
	if changed {
		_ = r.save()
	}
	out := make([]string, 0, len(seen))
	for s := range seen {
		out = append(out, s)
	}
	sort.Strings(out)
	return out
}

// ComposeEnvFile is where a compose project's env file (name: ".env", ".env.prod"…) lives in envDir:
// compose-<project>.env, compose-<project>.prod.env…
func ComposeEnvFile(envDir, project, name string) string {
	suffix := strings.TrimPrefix(strings.TrimPrefix(name, ".env"), ".")
	if suffix != "" {
		suffix = "." + suffix
	}
	return filepath.Join(envDir, "compose-"+project+suffix+".env")
}

// EnsureDir creates an env directory (real path): 0711 root, so site users reach their own file but list none.
func EnsureDir(dir string) error {
	if err := os.MkdirAll(dir, 0o711); err != nil {
		return err
	}
	return os.Chmod(dir, 0o711)
}
