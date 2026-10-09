package security

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
)

// Walk bounds: the audit has to finish in its budget on servers with large site trees.
const (
	siteWalkDepth   = 8
	siteWalkEntries = 50000
	tmpWalkDepth    = 3
	tmpWalkEntries  = 5000
)

var errWalkLimit = errors.New("walk limit")

// walk visits the tree under the host path root (not following symlinks) up to maxDepth levels and maxEntries
// entries; fn gets host paths. It reports whether the walk was cut short.
func (s *Security) walk(ctx context.Context, root string, maxDepth, maxEntries int, skip map[string]bool, fn func(path string, d fs.DirEntry)) bool {
	real := s.d.FS.P(root)
	n := 0
	err := filepath.WalkDir(real, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			if p == real {
				return err
			}
			return nil // unreadable entry: skip it
		}
		if ctx.Err() != nil || n >= maxEntries {
			return errWalkLimit
		}
		n++
		rel, _ := filepath.Rel(real, p)
		host := filepath.Join(root, rel)
		if d.IsDir() && p != real {
			if skip[d.Name()] || strings.Count(rel, string(filepath.Separator))+1 > maxDepth {
				return filepath.SkipDir
			}
		}
		fn(host, d)
		return nil
	})
	return errors.Is(err, errWalkLimit)
}

// isSecretName reports whether a file name is a dotenv file (examples and templates hold no secrets).
func isSecretName(name string) bool {
	if name != ".env" && !strings.HasPrefix(name, ".env.") {
		return false
	}
	for _, s := range []string{".example", ".sample", ".dist", ".template", ".testing.example"} {
		if strings.HasSuffix(name, s) {
			return false
		}
	}
	return true
}

// secretRoots are the places Falak keeps secrets on the tmpfs: sites' env files and containers' secret files.
func (s *Security) secretRoots() []string {
	return []string{filepath.Join(s.d.RunDir, "env"), filepath.Join(s.d.RunDir, "secrets")}
}

// exposedSecrets lists secret files under Falak's paths that other users can read or write (o+rwx bits): dotenv files
// under the sites root and every file under the tmpfs secret directories. Only regular files count, never symlinks.
func (s *Security) exposedSecrets(ctx context.Context) (files []string, truncated bool) {
	visit := func(all bool) func(string, fs.DirEntry) {
		return func(p string, d fs.DirEntry) {
			if !d.Type().IsRegular() || (!all && !isSecretName(d.Name())) {
				return
			}
			if fi, err := d.Info(); err == nil && fi.Mode().Perm()&0o007 != 0 {
				files = append(files, p)
			}
		}
	}
	skip := map[string]bool{"node_modules": true, ".git": true}
	if s.d.FS.Exists(s.d.SitesRoot) {
		truncated = s.walk(ctx, s.d.SitesRoot, siteWalkDepth, siteWalkEntries, skip, visit(false))
	}
	for _, r := range s.secretRoots() {
		if s.d.FS.Exists(r) {
			truncated = s.walk(ctx, r, 4, siteWalkEntries, nil, visit(true)) || truncated
		}
	}
	return files, truncated
}

func (s *Security) fileChecks(ctx context.Context, _ AuditPayload) []Check {
	var cs []Check
	exposed, cut := s.exposedSecrets(ctx)
	c := Check{ID: "files.secret_permissions", Title: "Secret files are private", Area: "files", Status: Pass, Severity: High,
		Evidence: "no .env or secret file is readable by other users"}
	if len(exposed) > 0 {
		c.Status, c.FixID = Fail, "files.secret_permissions"
		c.Evidence = plural(len(exposed), "secret file is", "secret files are") + " readable by other users: " + list(exposed, 3)
	}
	if cut {
		c.Evidence += " (walk stopped at its limit)"
	}
	cs = append(cs, c)

	var writable []string
	cut = false
	if s.d.FS.Exists(s.d.SitesRoot) {
		cut = s.walk(ctx, s.d.SitesRoot, siteWalkDepth, siteWalkEntries, map[string]bool{"node_modules": true, ".git": true}, func(p string, d fs.DirEntry) {
			if d.Type()&fs.ModeSymlink != 0 {
				return
			}
			fi, err := d.Info()
			if err != nil {
				return
			}
			m := fi.Mode()
			if m.Perm()&0o002 != 0 && !(m.IsDir() && m&os.ModeSticky != 0) {
				writable = append(writable, p)
			}
		})
	}
	c = Check{ID: "files.world_writable", Title: "Nothing in the site roots is world-writable", Area: "files", Status: Pass, Severity: Medium,
		Evidence: "no world-writable files or directories under " + s.d.SitesRoot}
	if len(writable) > 0 {
		c.Status = Warn
		c.Evidence = plural(len(writable), "world-writable path", "world-writable paths") + ": " + list(writable, 3)
	}
	if cut {
		c.Evidence += " (walk stopped at its limit)"
	}
	cs = append(cs, c)

	var execs []string
	for _, dir := range []string{"/tmp", "/var/tmp", "/dev/shm"} {
		if !s.d.FS.Exists(dir) {
			continue
		}
		s.walk(ctx, dir, tmpWalkDepth, tmpWalkEntries, nil, func(p string, d fs.DirEntry) {
			if d.IsDir() && strings.HasPrefix(d.Name(), "systemd-private-") {
				return
			}
			if !d.Type().IsRegular() {
				return
			}
			if fi, err := d.Info(); err == nil && fi.Mode().Perm()&0o111 != 0 {
				execs = append(execs, p)
			}
		})
	}
	c = Check{ID: "files.tmp_executables", Title: "No executables are left in temporary directories", Area: "files", Status: Pass, Severity: Medium,
		Evidence: "no executable files in /tmp, /var/tmp or /dev/shm"}
	if len(execs) > 0 {
		c.Status = Warn
		c.Evidence = fmt.Sprintf("%s (look at who left them): %s", plural(len(execs), "executable file", "executable files"), list(execs, 3))
	}
	return append(cs, c)
}
