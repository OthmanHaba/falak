package db

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// The spool is written by the engine's user inside the container (postgres' archive_command), and a database superuser
// can get a shell as that user (COPY ... TO PROGRAM): anything in <volume>/spool/<kind> may be a symlink or a hard link
// planted to make the root agent read, upload or delete a host file. So the agent never resolves spool paths by name:
// it opens <volume>/spool and then <kind> component by component without following symlinks (os.Root, which also never
// lets a path leave it), lists and Lstats entries through that handle, accepts only regular files with one link, opens
// them O_NOFOLLOW and checks the opened file is the one it listed, and deletes with unlinkat on the handle. falak-db
// keeps <volume>/spool itself root's (0755): only spool/<kind> belongs to the engine.

var errSpoolSymlink = errors.New("is a symlink: refused")

// openSpool opens <volume>/spool/<kind> beneath the volume. fs.ErrNotExist when there is none yet.
func (db *DB) openSpool(volumeID, kind string) (*os.Root, error) {
	vol, err := os.OpenRoot(db.d.FS.P(db.volumeDir(volumeID)))
	if err != nil {
		return nil, err
	}
	cur := vol
	for _, comp := range []string{"spool", kind} {
		fi, err := cur.Lstat(comp)
		if err != nil {
			cur.Close()
			return nil, err
		}
		if fi.Mode()&fs.ModeSymlink != 0 {
			cur.Close()
			return nil, fmt.Errorf("spool: %s %w", comp, errSpoolSymlink)
		}
		if !fi.IsDir() {
			cur.Close()
			return nil, fmt.Errorf("spool: %s is not a directory", comp)
		}
		next, err := cur.OpenRoot(comp)
		if err != nil {
			cur.Close()
			return nil, err
		}
		got, err := next.Stat(".")
		cur.Close()
		if err != nil || !os.SameFile(fi, got) {
			next.Close()
			return nil, fmt.Errorf("spool: %s changed while it was opened", comp)
		}
		cur = next
	}
	return cur, nil
}

// regularSpoolFile accepts a plain file with a single link (a hard link to a file elsewhere has two).
func regularSpoolFile(fi os.FileInfo) bool {
	if !fi.Mode().IsRegular() {
		return false
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok && uint64(st.Nlink) != 1 {
		return false
	}
	return true
}

// spoolEntries lists the shippable files of an opened spool directory: no dot-files (falak-db's), only regular files
// with one link, by Lstat through the handle.
func spoolEntries(dir *os.Root) ([]spoolFile, error) {
	d, err := dir.Open(".")
	if err != nil {
		return nil, err
	}
	names, err := d.Readdirnames(-1)
	d.Close()
	if err != nil {
		return nil, err
	}
	var out []spoolFile
	for _, name := range names {
		if strings.HasPrefix(name, ".") || !segmentRe.MatchString(name) {
			continue
		}
		fi, err := dir.Lstat(name)
		if err != nil || !regularSpoolFile(fi) {
			continue
		}
		out = append(out, spoolFile{Name: name, Bytes: fi.Size(), Modified: fi.ModTime().UTC(), info: fi})
	}
	return out, nil
}

// openSpoolFile opens a listed spool file without following a symlink, and checks it is still the file listed.
func openSpoolFile(dir *os.Root, f spoolFile) (*os.File, error) {
	in, err := dir.OpenFile(f.Name, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, err
	}
	fi, err := in.Stat()
	if err == nil && (!regularSpoolFile(fi) || (f.info != nil && !os.SameFile(fi, f.info))) {
		err = fmt.Errorf("spool: %s changed since it was listed", f.Name)
	}
	if err != nil {
		in.Close()
		return nil, err
	}
	return in, nil
}

// removeSpoolFiles deletes names from the instance's spool (unlinkat on the directory handle: a symlink is removed, never
// followed).
func (db *DB) removeSpoolFiles(cfg pitrConfig, names []string) error {
	if len(names) == 0 {
		return nil
	}
	dir, err := db.openSpool(cfg.VolumeID, spoolKind(cfg.Engine))
	if err != nil {
		return err
	}
	defer dir.Close()
	var errs []error
	for _, n := range names {
		if err := dir.Remove(n); err != nil && !errors.Is(err, fs.ErrNotExist) {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}

// dataFileInfo is a file of the instance's data directory by Lstat beneath the volume (a binlog's size and mtime).
func (db *DB) dataFileInfo(volumeID, name string) (os.FileInfo, bool) {
	if !segmentRe.MatchString(name) {
		return nil, false
	}
	vol, err := os.OpenRoot(db.d.FS.P(db.volumeDir(volumeID)))
	if err != nil {
		return nil, false
	}
	defer vol.Close()
	fi, err := vol.Lstat(filepath.Join("data", name))
	if err != nil || !fi.Mode().IsRegular() {
		return nil, false
	}
	return fi, true
}

// ensureRootSpool makes <volume>/spool a real directory, root's and 0755 (falak-db hands only spool/<kind> to the
// engine's user). A symlink in its place is refused.
func ensureRootSpool(path string) error {
	if fi, err := os.Lstat(path); err == nil && !fi.IsDir() {
		return fmt.Errorf("%s is not a directory (a symlink?): refused", path)
	}
	if err := os.MkdirAll(path, 0o755); err != nil {
		return err
	}
	if err := os.Chmod(path, 0o755); err != nil {
		return err
	}
	if os.Geteuid() == 0 {
		return os.Lchown(path, 0, 0)
	}
	return nil
}

// modTimeBefore is a or b, whichever is earlier.
func modTimeBefore(a, b time.Time) time.Time {
	if b.Before(a) {
		return b
	}
	return a
}
