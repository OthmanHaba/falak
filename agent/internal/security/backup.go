package security

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/runner"
)

// BackupTTL is how long a fix can be undone; older backups are pruned.
const BackupTTL = 7 * 24 * time.Hour

// BackupIDRe matches backup ids ("20261009T120000Z-1a2b3c4d"); undo never builds a path from anything else.
var BackupIDRe = regexp.MustCompile(`^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}$`)

// Manifest describes one fix's backup (manifest.json in its directory).
type Manifest struct {
	ID        string      `json:"id"`
	FixID     string      `json:"fix_id"`
	CreatedAt time.Time   `json:"created_at"`
	Files     []FileEntry `json:"files"`
	// Sysctl holds the live values of the settings the fix changed, as they were.
	Sysctl map[string]string `json:"sysctl,omitempty"`
	// State is fix-specific (the NTP setting before, the reboot timer it started).
	State map[string]string `json:"state,omitempty"`
}

// FileEntry is one file a fix touched, as it was before.
type FileEntry struct {
	Path    string `json:"path"`
	Existed bool   `json:"existed"`
	Mode    uint32 `json:"mode,omitempty"`
	UID     int    `json:"uid"`
	GID     int    `json:"gid"`
	SHA256  string `json:"sha256,omitempty"`
	// Stored is the copy of the content in the backup ("files/0"); "" for permission-only entries (secret files: their
	// content is never copied out of the tmpfs or the site).
	Stored string `json:"stored,omitempty"`
	// Inode identifies the file of a permission-only entry, so undo never changes the mode of a file swapped in since.
	Inode uint64 `json:"inode,omitempty"`
	// The file as the fix left it: undo refuses (unless forced) when it changed since, so it never silently throws
	// away somebody's later edit. AfterSHA256 is "" for permission-only entries and when the fix left no file.
	AfterExists bool   `json:"after_exists"`
	AfterSHA256 string `json:"after_sha256,omitempty"`
	AfterMode   uint32 `json:"after_mode,omitempty"`
}

type backup struct {
	s   *Security
	m   Manifest
	dir string // host path
}

func (s *Security) backupsDir() string { return filepath.Join(s.d.StateDir, "security-backups") }

func (s *Security) newBackup(fixID string) (*backup, error) {
	var rnd [4]byte
	if _, err := rand.Read(rnd[:]); err != nil {
		return nil, err
	}
	now := s.d.Now().UTC()
	id := now.Format("20060102T150405Z") + "-" + hex.EncodeToString(rnd[:])
	return &backup{s: s, m: Manifest{ID: id, FixID: fixID, CreatedAt: now, Files: []FileEntry{}}, dir: filepath.Join(s.backupsDir(), id)}, nil
}

func (b *backup) empty() bool {
	return len(b.m.Files) == 0 && len(b.m.Sysctl) == 0 && len(b.m.State) == 0
}

func (b *backup) has(path string) bool {
	for _, f := range b.m.Files {
		if f.Path == path {
			return true
		}
	}
	return false
}

// write persists the manifest; it runs after every change, so a crash mid-fix still leaves an undoable backup.
func (b *backup) write() error {
	if err := b.s.d.FS.MkdirAll(b.dir, 0o700); err != nil {
		return err
	}
	j, err := json.MarshalIndent(b.m, "", "  ")
	if err != nil {
		return err
	}
	_, err = b.s.d.FS.WriteFile(filepath.Join(b.dir, "manifest.json"), append(j, '\n'), 0o600)
	return err
}

// saveFile records a file (content, mode, owner) before a fix changes or creates it. Only regular files are touched
// by fixes; a symlink in their place is refused.
func (b *backup) saveFile(path string) error {
	if b.has(path) {
		return nil
	}
	fi, err := os.Lstat(b.s.d.FS.P(path))
	if errors.Is(err, fs.ErrNotExist) {
		b.m.Files = append(b.m.Files, FileEntry{Path: path, Existed: false})
		return b.write()
	}
	if err != nil {
		return err
	}
	if !fi.Mode().IsRegular() {
		return fmt.Errorf("%s is not a regular file; not touching it", path)
	}
	content, err := b.s.d.FS.ReadFile(path)
	if err != nil {
		return err
	}
	if err := b.s.d.FS.MkdirAll(filepath.Join(b.dir, "files"), 0o700); err != nil {
		return err
	}
	stored := "files/" + strconv.Itoa(len(b.m.Files))
	if _, err := b.s.d.FS.WriteFile(filepath.Join(b.dir, stored), content, 0o600); err != nil {
		return err
	}
	sum := sha256.Sum256(content)
	e := FileEntry{Path: path, Existed: true, Mode: uint32(fi.Mode().Perm()), SHA256: hex.EncodeToString(sum[:]), Stored: stored}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		e.UID, e.GID = int(st.Uid), int(st.Gid)
	}
	b.m.Files = append(b.m.Files, e)
	return b.write()
}

// savePerms records the mode and owner of an open file (permission fixes; the content is never copied).
func (b *backup) savePerms(path string, fi os.FileInfo) error {
	if b.has(path) {
		return nil
	}
	e := FileEntry{Path: path, Existed: true, Mode: uint32(fi.Mode().Perm())}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		e.UID, e.GID, e.Inode = int(st.Uid), int(st.Gid), uint64(st.Ino)
	}
	b.m.Files = append(b.m.Files, e)
	return b.write()
}

// saveSysctl records a setting's live value before the fix changes it.
func (b *backup) saveSysctl(key, value string) error {
	if b.m.Sysctl == nil {
		b.m.Sysctl = map[string]string{}
	}
	if _, ok := b.m.Sysctl[key]; ok {
		return nil
	}
	b.m.Sysctl[key] = value
	return b.write()
}

func (b *backup) setState(k, v string) error {
	if b.m.State == nil {
		b.m.State = map[string]string{}
	}
	b.m.State[k] = v
	return b.write()
}

// restore puts every recorded file and setting back the way it was (newest first). It keeps going on errors and
// returns them all.
func (b *backup) restore(ctx context.Context) error {
	var errs []error
	for i := len(b.m.Files) - 1; i >= 0; i-- {
		if err := b.restoreFile(b.m.Files[i]); err != nil {
			errs = append(errs, fmt.Errorf("restore %s: %w", b.m.Files[i].Path, err))
		}
	}
	for k, v := range b.m.Sysctl {
		if _, err := runner.Check(ctx, b.s.d.Runner, runner.Cmd{Name: "sysctl", Args: []string{"-q", "-w", k + "=" + v}}); err != nil {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}

func (b *backup) restoreFile(e FileEntry) error {
	hfs := b.s.d.FS
	if !e.Existed {
		_, err := hfs.Remove(e.Path)
		return err
	}
	if e.Stored == "" {
		return b.restorePerms(e)
	}
	content, err := hfs.ReadFile(filepath.Join(b.dir, e.Stored))
	if err != nil {
		return err
	}
	if sum := sha256.Sum256(content); hex.EncodeToString(sum[:]) != e.SHA256 {
		return errors.New("the backup copy does not match its checksum")
	}
	if _, err := hfs.WriteFile(e.Path, content, os.FileMode(e.Mode)); err != nil {
		return err
	}
	if err := os.Lchown(hfs.P(e.Path), e.UID, e.GID); err != nil && hfs.IsReal() {
		return err
	}
	return nil
}

// restorePerms sets a file's mode and owner back through a descriptor opened without following symlinks, and only on
// the same file (inode) the fix changed.
func (b *backup) restorePerms(e FileEntry) error {
	f, err := os.OpenFile(b.s.d.FS.P(e.Path), os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return err
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); !ok || uint64(st.Ino) != e.Inode || !fi.Mode().IsRegular() {
		return errors.New("the file was replaced since the fix; leaving it alone")
	}
	if err := f.Chmod(os.FileMode(e.Mode)); err != nil {
		return err
	}
	if err := f.Chown(e.UID, e.GID); err != nil && b.s.d.FS.IsReal() {
		return err
	}
	return nil
}

// seal records every file as the fix left it.
func (b *backup) seal() error {
	for i := range b.m.Files {
		e := &b.m.Files[i]
		e.AfterExists, e.AfterSHA256, e.AfterMode = b.current(*e)
	}
	return b.write()
}

// current describes a file now: whether it exists, its sha256 (content entries) and mode.
func (b *backup) current(e FileEntry) (bool, string, uint32) {
	fi, err := os.Lstat(b.s.d.FS.P(e.Path))
	if err != nil {
		return false, "", 0
	}
	sum := ""
	if e.Stored != "" || !e.Existed {
		if content, err := b.s.d.FS.ReadFile(e.Path); err == nil && fi.Mode().IsRegular() {
			h := sha256.Sum256(content)
			sum = hex.EncodeToString(h[:])
		} else {
			sum = "unreadable"
		}
	}
	return true, sum, uint32(fi.Mode().Perm())
}

// changedSinceFix lists the files that are no longer as the fix left them.
func (b *backup) changedSinceFix() []string {
	var changed []string
	for _, e := range b.m.Files {
		exists, sum, mode := b.current(e)
		if exists != e.AfterExists || (exists && (sum != e.AfterSHA256 || mode != e.AfterMode)) {
			changed = append(changed, e.Path)
		}
	}
	return changed
}

// discard deletes the backup directory.
func (b *backup) discard() { _ = os.RemoveAll(b.s.d.FS.P(b.dir)) }

// loadBackup reads a backup by id.
func (s *Security) loadBackup(id string) (*backup, error) {
	if !BackupIDRe.MatchString(id) {
		return nil, fmt.Errorf("invalid backup id %q", id)
	}
	dir := filepath.Join(s.backupsDir(), id)
	raw, err := s.d.FS.ReadFile(filepath.Join(dir, "manifest.json"))
	if err != nil {
		return nil, err
	}
	var m Manifest
	if err := json.Unmarshal(raw, &m); err != nil {
		return nil, err
	}
	if m.ID != id {
		return nil, errors.New("the backup manifest names another backup")
	}
	return &backup{s: s, m: m, dir: dir}, nil
}

// prune deletes backups older than BackupTTL (the directory's mtime decides when its manifest is unreadable).
func (s *Security) prune() {
	ents, err := os.ReadDir(s.d.FS.P(s.backupsDir()))
	if err != nil {
		return
	}
	cutoff := s.d.Now().Add(-BackupTTL)
	for _, e := range ents {
		if !e.IsDir() || !BackupIDRe.MatchString(e.Name()) {
			continue
		}
		created := time.Time{}
		if b, err := s.loadBackup(e.Name()); err == nil {
			created = b.m.CreatedAt
		} else if fi, err := e.Info(); err == nil {
			created = fi.ModTime()
		}
		if created.Before(cutoff) {
			_ = os.RemoveAll(s.d.FS.P(filepath.Join(s.backupsDir(), e.Name())))
		}
	}
}
