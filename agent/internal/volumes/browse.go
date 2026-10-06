package volumes

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"sort"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// SearchVisitLimit caps how many entries a volume.browse search looks at.
var SearchVisitLimit = 50000

// BrowsePayload is volume.browse.
type BrowsePayload struct {
	Volume Ref    `json:"volume"`
	Path   string `json:"path,omitempty"`
	Search string `json:"search,omitempty"`
	Offset int    `json:"offset,omitempty"`
	Limit  int    `json:"limit,omitempty"`
}

// Entry is one file or directory of a listing.
type Entry struct {
	Name  string `json:"name"`
	Path  string `json:"path"`
	Type  string `json:"type"`
	Size  int64  `json:"size"`
	MTime string `json:"mtime"`
}

// BrowseResult is its result.
type BrowseResult struct {
	Path      string  `json:"path"`
	Entries   []Entry `json:"entries"`
	Total     int     `json:"total"`
	Truncated bool    `json:"truncated,omitempty"`
}

// relPath validates a path inside a volume ("" or "." is the root): relative, without "." or ".." segments.
func relPath(p string) (string, error) {
	p = strings.TrimSuffix(p, "/")
	if p == "" || p == "." {
		return ".", nil
	}
	if strings.HasPrefix(p, "/") || strings.ContainsRune(p, 0) || len(p) > 4096 {
		return "", payloadErr("path must be relative to the volume")
	}
	for _, seg := range strings.Split(p, "/") {
		if seg == "" || seg == "." || seg == ".." {
			return "", payloadErr("path %q has an empty, . or .. segment", p)
		}
	}
	return p, nil
}

// noSymlinks refuses a path any of whose components is a symbolic link: browsing and downloads never follow one,
// not even within the volume.
func noSymlinks(root *os.Root, rel string) (fs.FileInfo, error) {
	if rel == "." {
		return root.Lstat(".")
	}
	parts := strings.Split(rel, "/")
	var fi fs.FileInfo
	for i := range parts {
		var err error
		if fi, err = root.Lstat(strings.Join(parts[:i+1], "/")); err != nil {
			if errors.Is(err, fs.ErrNotExist) {
				return nil, fmt.Errorf("%s does not exist", rel)
			}
			return nil, err
		}
		if fi.Mode()&fs.ModeSymlink != 0 {
			return nil, fmt.Errorf("%s is a symbolic link", strings.Join(parts[:i+1], "/"))
		}
	}
	return fi, nil
}

func entryOf(dir string, fi fs.FileInfo) Entry {
	e := Entry{Name: fi.Name(), Path: path.Join(dir, fi.Name()), MTime: fi.ModTime().UTC().Format(time.RFC3339)}
	if dir == "." {
		e.Path = fi.Name()
	}
	switch {
	case fi.Mode()&fs.ModeSymlink != 0:
		e.Type = "symlink"
	case fi.IsDir():
		e.Type = "dir"
	case fi.Mode().IsRegular():
		e.Type, e.Size = "file", fi.Size()
	default:
		e.Type = "other"
	}
	return e
}

// Browse lists one directory of a volume (directories first, then by name), or searches below it by name.
func (s *Service) Browse(ctx context.Context, p BrowsePayload, _ commands.Stream) (any, error) {
	rel, err := relPath(p.Path)
	if err != nil {
		return nil, err
	}
	if p.Offset < 0 || p.Limit < 0 || p.Limit > 1000 || len(p.Search) > 128 {
		return nil, payloadErr("offset / limit / search out of range")
	}
	limit := p.Limit
	if limit == 0 {
		limit = 200
	}
	root, _, err := s.openRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	fi, err := noSymlinks(root, rel)
	if err != nil {
		return nil, err
	}
	if !fi.IsDir() {
		return nil, fmt.Errorf("%s is not a directory", rel)
	}
	out := BrowseResult{Path: rel, Entries: []Entry{}}
	if rel == "." {
		out.Path = ""
	}
	if p.Search != "" {
		return s.search(ctx, root, rel, strings.ToLower(p.Search), p.Offset, limit, out)
	}
	f, err := root.OpenFile(rel, os.O_RDONLY|noFollow, 0)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	des, err := f.ReadDir(-1)
	if err != nil {
		return nil, err
	}
	entries := make([]Entry, 0, len(des))
	for _, de := range des {
		// Lstat through the root (DirEntry.Info resolves the name against the working directory on Linux):
		// symlinks are reported, never followed.
		info, err := root.Lstat(path.Join(rel, de.Name()))
		if err != nil {
			continue
		}
		entries = append(entries, entryOf(rel, info))
	}
	sort.Slice(entries, func(i, j int) bool {
		if (entries[i].Type == "dir") != (entries[j].Type == "dir") {
			return entries[i].Type == "dir"
		}
		return entries[i].Name < entries[j].Name
	})
	out.Total = len(entries)
	if p.Offset < len(entries) {
		end := min(p.Offset+limit, len(entries))
		out.Entries = entries[p.Offset:end]
		out.Truncated = end < len(entries)
	}
	return out, nil
}

func (s *Service) search(ctx context.Context, root *os.Root, rel, needle string, offset, limit int, out BrowseResult) (BrowseResult, error) {
	visited, matched := 0, 0
	stop := errors.New("stop")
	err := fs.WalkDir(root.FS(), rel, func(name string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if visited++; visited > SearchVisitLimit {
			out.Truncated = true
			return stop
		}
		if name == rel || !strings.Contains(strings.ToLower(d.Name()), needle) {
			return nil
		}
		matched++
		if matched <= offset {
			return nil
		}
		if len(out.Entries) == limit {
			out.Truncated = true
			return stop
		}
		if info, err := root.Lstat(name); err == nil {
			out.Entries = append(out.Entries, entryOf(path.Dir(name), info))
		}
		return nil
	})
	if err != nil && !errors.Is(err, stop) {
		return out, err
	}
	out.Total = matched
	return out, nil
}

// DownloadPayload is volume.download.
type DownloadPayload struct {
	Volume      Ref      `json:"volume"`
	Path        string   `json:"path,omitempty"`
	Destination Location `json:"destination"`
	MaxBytes    int64    `json:"max_bytes"`
}

// DownloadResult is its result.
type DownloadResult struct {
	SizeBytes  int64  `json:"size_bytes"`
	SHA256     string `json:"sha256"`
	Location   string `json:"location"`
	Format     string `json:"format"`
	Name       string `json:"name"`
	Files      int64  `json:"files,omitempty"`
	DurationMS int64  `json:"duration_ms,omitempty"`
}

// Download uploads one file of a volume as is, or a folder as tar.zst, to a presigned URL. Anything larger than
// max_bytes (before compression) is refused before a byte is sent.
func (s *Service) Download(ctx context.Context, p DownloadPayload, st commands.Stream) (any, error) {
	start := time.Now()
	if err := checkDestination(p.Destination); err != nil {
		return nil, err
	}
	if p.MaxBytes <= 0 {
		return nil, payloadErr("max_bytes is required")
	}
	rel, err := relPath(p.Path)
	if err != nil {
		return nil, err
	}
	root, _, err := s.openRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	fi, err := noSymlinks(root, rel)
	if err != nil {
		return nil, err
	}
	name := path.Base(rel)
	if rel == "." {
		name = p.Volume.ID
	}
	if fi.Mode().IsRegular() {
		if fi.Size() > p.MaxBytes {
			return nil, fmt.Errorf("%s is %d bytes, over the %d-byte download limit", rel, fi.Size(), p.MaxBytes)
		}
		dir, err := s.staging(fi.Size())
		if err != nil {
			return nil, err
		}
		file, sum, size, err := s.copyFile(dir, root, rel, p.MaxBytes)
		if err != nil {
			return nil, err
		}
		defer os.Remove(file)
		loc, err := s.put(ctx, file, size, p.Destination)
		if err != nil {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "uploaded %s (%d bytes)\n", rel, size)
		return DownloadResult{SizeBytes: size, SHA256: sum, Location: loc, Format: "raw", Name: name, Files: 1, DurationMS: time.Since(start).Milliseconds()}, nil
	}
	if !fi.IsDir() {
		return nil, fmt.Errorf("%s is not a file or directory", rel)
	}
	var total int64
	err = fs.WalkDir(root.FS(), rel, func(name string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.Type().IsRegular() {
			// A size that can't be read fails the download: the limit must never be skipped.
			info, err := root.Lstat(name)
			if err != nil {
				return err
			}
			if total += info.Size(); total > p.MaxBytes {
				return fmt.Errorf("%s holds more than the %d-byte download limit", rel, p.MaxBytes)
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	dir, err := s.staging(total)
	if err != nil {
		return nil, err
	}
	file, sum, size, stats, err := s.plainStage(dir, func(w io.Writer) (tarStats, error) { return writeTar(ctx, root, rel, w) })
	if err != nil {
		return nil, err
	}
	defer os.Remove(file)
	loc, err := s.put(ctx, file, size, p.Destination)
	if err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "uploaded %s as tar.zst (%d files, %d bytes)\n", rel, stats.files, size)
	return DownloadResult{SizeBytes: size, SHA256: sum, Location: loc, Format: "tar.zst", Name: name + ".tar.zst", Files: stats.files,
		DurationMS: time.Since(start).Milliseconds()}, nil
}

// plainStage is stage without the Sealer: downloads are for the user to open.
func (s *Service) plainStage(dir string, write func(io.Writer) (tarStats, error)) (string, string, int64, tarStats, error) {
	plain := *s
	plain.d.Seal = nil
	return plain.stage(dir, write)
}

// copyFile copies one file of a volume (at most limit bytes) into the staging directory dir, hashing it.
func (s *Service) copyFile(dir string, root *os.Root, rel string, limit int64) (string, string, int64, error) {
	src, err := root.OpenFile(rel, os.O_RDONLY|noFollow, 0)
	if err != nil {
		return "", "", 0, err
	}
	defer src.Close()
	f, err := os.CreateTemp(dir, "download-*")
	if err != nil {
		return "", "", 0, err
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(src, limit+1))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err == nil && n > limit {
		err = fmt.Errorf("%s grew over the %d-byte download limit", rel, limit)
	}
	if err != nil {
		os.Remove(f.Name())
		return "", "", 0, err
	}
	return f.Name(), hex.EncodeToString(h.Sum(nil)), n, nil
}
