package dbhelper

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
)

// The spool (FALAK_DB_SPOOL, on the instance's volume) holds WAL segments (wal/) and closed binlogs (binlog/) until
// the host agent ships them. falak-db only ever adds files: it never deletes or overwrites one, so nothing unshipped
// is lost. Files whose names start with "." are falak-db's own (temporary files, the binlog position) and are not
// for shipping.
const (
	spoolWAL    = "wal"
	spoolBinlog = "binlog"
)

// Spooled describes one file in the spool.
type Spooled struct {
	Name    string `json:"name"`
	Path    string `json:"path"`
	Bytes   int64  `json:"bytes"`
	SHA256  string `json:"sha256"`
	Existed bool   `json:"existed"` // already spooled with identical content (a retried archive_command)
}

// validName accepts a plain file name: no separators, no "." or "..", not hidden.
func validName(name string) bool {
	return name != "" && !strings.HasPrefix(name, ".") && !strings.ContainsAny(name, "/\\\x00") && len(name) <= 255
}

// spoolFile copies src into dir/kind/name durably: the data is written to a temporary file, fsynced, linked into
// place (link never replaces an existing file) and the directory is fsynced. Spooling the same content again is a
// no-op; different content under an existing name is a conflict.
func spoolFile(src, dir, kind, name string) (Spooled, error) {
	if !validName(name) {
		return Spooled{}, usageErr("invalid file name %q", name)
	}
	target := filepath.Join(dir, kind)
	if err := os.MkdirAll(target, 0o700); err != nil {
		return Spooled{}, fmt.Errorf("spool: %w", err)
	}
	dst := filepath.Join(target, name)
	in, err := os.Open(src)
	if err != nil {
		return Spooled{}, fmt.Errorf("spool: %w", err)
	}
	defer in.Close()
	if st, err := in.Stat(); err != nil {
		return Spooled{}, fmt.Errorf("spool: %w", err)
	} else if !st.Mode().IsRegular() {
		return Spooled{}, usageErr("spool: %s is not a regular file", src)
	}

	if r, ok, err := sameAs(in, dst); err != nil || ok {
		return r, err
	}

	tmp, err := tempFile(target, name)
	if err != nil {
		return Spooled{}, fmt.Errorf("spool: %w", err)
	}
	defer os.Remove(tmp.Name())
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(tmp, h), in)
	if err == nil {
		err = tmp.Sync()
	}
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return Spooled{}, fmt.Errorf("spool: %w", err)
	}
	if err := os.Link(tmp.Name(), dst); err != nil {
		if errors.Is(err, fs.ErrExist) {
			// Someone spooled the same name meanwhile: fine if it is the same file.
			if _, err := in.Seek(0, io.SeekStart); err != nil {
				return Spooled{}, err
			}
			if r, ok, err := sameAs(in, dst); err != nil || ok {
				return r, err
			}
			return Spooled{}, conflict("spool: %s exists with different content", dst)
		}
		return Spooled{}, fmt.Errorf("spool: %w", err)
	}
	if err := syncDir(target); err != nil {
		return Spooled{}, fmt.Errorf("spool: %w", err)
	}
	return Spooled{Name: name, Path: dst, Bytes: n, SHA256: hex.EncodeToString(h.Sum(nil))}, nil
}

// sameAs reports whether dst exists with exactly src's content. A dst with different content is a conflict.
func sameAs(src *os.File, dst string) (Spooled, bool, error) {
	st, err := os.Lstat(dst)
	if errors.Is(err, fs.ErrNotExist) {
		return Spooled{}, false, nil
	}
	if err != nil {
		return Spooled{}, false, err
	}
	if !st.Mode().IsRegular() {
		return Spooled{}, false, conflict("spool: %s exists and is not a regular file", dst)
	}
	srcSum, n, err := hashReader(src)
	if err != nil {
		return Spooled{}, false, err
	}
	f, err := os.Open(dst)
	if err != nil {
		return Spooled{}, false, err
	}
	defer f.Close()
	dstSum, _, err := hashReader(f)
	if err != nil {
		return Spooled{}, false, err
	}
	if srcSum != dstSum {
		return Spooled{}, false, conflict("spool: %s exists with different content", dst)
	}
	return Spooled{Name: filepath.Base(dst), Path: dst, Bytes: n, SHA256: srcSum, Existed: true}, true, nil
}

func hashReader(r io.Reader) (string, int64, error) {
	h := sha256.New()
	n, err := io.Copy(h, r)
	return hex.EncodeToString(h.Sum(nil)), n, err
}

func tempFile(dir, name string) (*os.File, error) {
	var b [6]byte
	if _, err := rand.Read(b[:]); err != nil {
		return nil, err
	}
	return os.OpenFile(filepath.Join(dir, ".tmp-"+name+"-"+hex.EncodeToString(b[:])), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
}

func syncDir(dir string) error {
	d, err := os.Open(dir)
	if err != nil {
		return err
	}
	defer d.Close()
	return d.Sync()
}

// writeFileAtomic writes content to path through a synced temporary file and a rename.
func writeFileAtomic(path string, content []byte, mode os.FileMode) error {
	dir := filepath.Dir(path)
	tmp, err := tempFile(dir, filepath.Base(path))
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err = tmp.Write(content); err == nil {
		err = tmp.Chmod(mode)
	}
	if err == nil {
		err = tmp.Sync()
	}
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return err
	}
	if err := os.Rename(tmp.Name(), path); err != nil {
		return err
	}
	return syncDir(dir)
}

// fetchFile copies dir/name to dst (postgres restore_command: `falak-db wal-fetch %f %p --from DIR`). A missing
// segment is ExitNotFound, which postgres reads as "end of the archive".
func fetchFile(dir, name, dst string) (Spooled, error) {
	if !validName(name) {
		return Spooled{}, usageErr("invalid file name %q", name)
	}
	src := filepath.Join(dir, name)
	in, err := os.Open(src)
	if errors.Is(err, fs.ErrNotExist) {
		return Spooled{}, notFound("%s not found in %s", name, dir)
	}
	if err != nil {
		return Spooled{}, err
	}
	defer in.Close()
	if st, err := in.Stat(); err != nil {
		return Spooled{}, err
	} else if !st.Mode().IsRegular() {
		return Spooled{}, usageErr("%s is not a regular file", src)
	}
	tmp, err := tempFile(filepath.Dir(dst), filepath.Base(dst))
	if err != nil {
		return Spooled{}, err
	}
	defer os.Remove(tmp.Name())
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(tmp, h), in)
	if err == nil {
		err = tmp.Sync()
	}
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return Spooled{}, err
	}
	if err := os.Rename(tmp.Name(), dst); err != nil {
		return Spooled{}, err
	}
	return Spooled{Name: name, Path: dst, Bytes: n, SHA256: hex.EncodeToString(h.Sum(nil))}, nil
}
