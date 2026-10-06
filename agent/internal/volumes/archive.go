package volumes

import (
	"archive/tar"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"net/url"
	"os"
	"path"
	"strings"
	"time"

	"github.com/klauspost/compress/zstd"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// Location is a presigned destination (volume.archive / volume.download) or a source URL (volume.restore).
type Location struct {
	Kind    string            `json:"kind"`
	URL     string            `json:"url"`
	Headers map[string]string `json:"headers,omitempty"`
}

// ArchivePayload is volume.archive.
type ArchivePayload struct {
	Volume      Ref      `json:"volume"`
	Consistency string   `json:"consistency,omitempty"`
	Destination Location `json:"destination"`
}

// ArchiveResult is its result.
type ArchiveResult struct {
	SizeBytes         int64    `json:"size_bytes"`
	SHA256            string   `json:"sha256"`
	Location          string   `json:"location"`
	UncompressedBytes int64    `json:"uncompressed_bytes,omitempty"`
	Files             int64    `json:"files,omitempty"`
	DurationMS        int64    `json:"duration_ms,omitempty"`
	Containers        []string `json:"containers,omitempty"`
}

func checkDestination(d Location) error {
	if d.Kind != "presigned_url" || !strings.HasPrefix(d.URL, "https://") {
		return payloadErr("destination must be an https presigned_url")
	}
	return nil
}

// Archive streams a snapshot of a volume (tar | zstd, then the Sealer when set) into a staging file while hashing
// it, then PUTs it to the presigned URL. Containers that mount the volume are paused or stopped for the read when
// the consistency mode asks.
func (s *Service) Archive(ctx context.Context, p ArchivePayload, st commands.Stream) (any, error) {
	start := time.Now()
	if err := checkDestination(p.Destination); err != nil {
		return nil, err
	}
	root, hp, err := s.openRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	undo, touched, err := s.quiesce(ctx, p.Volume, hp, p.Consistency, st)
	if err != nil {
		return nil, err
	}
	file, sum, size, stats, err := s.stage(func(w io.Writer) (tarStats, error) {
		defer undo()
		return writeTar(ctx, root, ".", w)
	})
	if err != nil {
		return nil, err
	}
	defer os.Remove(file)
	loc, err := s.put(ctx, file, size, p.Destination)
	if err != nil {
		return nil, err
	}
	fmt.Fprintf(st.Stdout(), "snapshot of volume %s: %d files, %d bytes, sha256 %s\n", p.Volume.ID, stats.files, size, sum)
	return ArchiveResult{SizeBytes: size, SHA256: sum, Location: loc, UncompressedBytes: stats.bytes, Files: stats.files,
		DurationMS: time.Since(start).Milliseconds(), Containers: touched}, nil
}

// stage writes tar output through zstd (and the Sealer) into a temp file, returning its path, sha256 and size.
func (s *Service) stage(write func(io.Writer) (tarStats, error)) (string, string, int64, tarStats, error) {
	f, err := os.CreateTemp(s.d.TempDir, "falak-volume-*")
	if err != nil {
		return "", "", 0, tarStats{}, err
	}
	name := f.Name()
	fail := func(err error) (string, string, int64, tarStats, error) {
		f.Close()
		os.Remove(name)
		return "", "", 0, tarStats{}, err
	}
	h := sha256.New()
	var sink io.Writer = io.MultiWriter(f, h)
	var sealed io.WriteCloser
	if s.d.Seal != nil { // step 4: backup encryption
		if sealed, err = s.d.Seal(sink); err != nil {
			return fail(err)
		}
		sink = sealed
	}
	zw, err := zstd.NewWriter(sink)
	if err != nil {
		return fail(err)
	}
	stats, err := write(zw)
	if cerr := zw.Close(); err == nil {
		err = cerr
	}
	if sealed != nil {
		if cerr := sealed.Close(); err == nil {
			err = cerr
		}
	}
	if err != nil {
		return fail(err)
	}
	fi, err := f.Stat()
	if err != nil {
		return fail(err)
	}
	if err := f.Close(); err != nil {
		return fail(err)
	}
	return name, hex.EncodeToString(h.Sum(nil)), fi.Size(), stats, nil
}

// put uploads a staged file to a presigned URL; the returned location has no query string (the signature).
func (s *Service) put(ctx context.Context, file string, size int64, d Location) (string, error) {
	f, err := os.Open(file)
	if err != nil {
		return "", err
	}
	defer f.Close()
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, d.URL, f)
	if err != nil {
		return "", errors.New("invalid destination url")
	}
	req.ContentLength = size
	req.Header.Set("Content-Type", "application/octet-stream")
	for k, v := range d.Headers {
		req.Header.Set(k, v)
	}
	resp, err := s.d.HTTP.Do(req)
	if err != nil {
		return "", fmt.Errorf("upload: %w", redact(err))
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return "", fmt.Errorf("upload: %s: %s", resp.Status, b)
	}
	return stripQuery(d.URL), nil
}

func stripQuery(raw string) string {
	u, err := url.Parse(raw)
	if err != nil {
		return ""
	}
	u.RawQuery = ""
	return u.String()
}

// redact strips the query string (signature) from url errors.
func redact(err error) error {
	var ue *url.Error
	if errors.As(err, &ue) {
		ue.URL = stripQuery(ue.URL)
	}
	return err
}

type tarStats struct{ files, bytes int64 }

type countingWriter struct {
	w io.Writer
	n int64
}

func (c *countingWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n += int64(n)
	return n, err
}

// writeTar writes the tree below dir (relative to root) as a tar stream. Symlinks are stored as symlinks and never
// followed; sockets, devices and fifos are skipped. A sized volume's lost+found is left out.
func writeTar(ctx context.Context, root *os.Root, dir string, w io.Writer) (tarStats, error) {
	cw := &countingWriter{w: w}
	tw := tar.NewWriter(cw)
	var stats tarStats
	err := fs.WalkDir(root.FS(), dir, func(name string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if name == "." {
			return nil
		}
		if name == "lost+found" && d.IsDir() {
			return fs.SkipDir
		}
		info, err := root.Lstat(name)
		if err != nil {
			return err
		}
		arcName := name
		if dir != "." {
			arcName = strings.TrimPrefix(strings.TrimPrefix(name, dir), "/")
			if arcName == "" {
				return nil
			}
		}
		link := ""
		switch {
		case info.Mode()&fs.ModeSymlink != 0:
			if link, err = root.Readlink(name); err != nil {
				return err
			}
		case info.IsDir(), info.Mode().IsRegular():
		default:
			return nil
		}
		hdr, err := tar.FileInfoHeader(info, link)
		if err != nil {
			return err
		}
		hdr.Name = arcName
		if info.IsDir() {
			hdr.Name += "/"
		}
		hdr.Format = tar.FormatPAX
		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}
		if !info.Mode().IsRegular() {
			return nil
		}
		f, err := root.OpenFile(name, os.O_RDONLY|noFollow, 0)
		if err != nil {
			return err
		}
		defer f.Close()
		// The file may change while it is read: write exactly the size the header announced.
		n, err := io.Copy(tw, io.LimitReader(f, hdr.Size))
		if err != nil {
			return err
		}
		if n < hdr.Size {
			if _, err := io.CopyN(tw, zeroReader{}, hdr.Size-n); err != nil {
				return err
			}
		}
		stats.files++
		return nil
	})
	if err != nil {
		return stats, err
	}
	if err := tw.Close(); err != nil {
		return stats, err
	}
	stats.bytes = cw.n
	return stats, nil
}

type zeroReader struct{}

func (zeroReader) Read(p []byte) (int, error) {
	clear(p)
	return len(p), nil
}

// extract unpacks a tar stream into root. Entries must stay inside it: absolute names and ".." segments are
// refused, files are created with O_EXCL (never written through an existing entry, e.g. a symlink placed by an
// earlier entry), and os.Root refuses any path that would resolve outside the volume.
func extract(ctx context.Context, root *os.Root, r io.Reader, limit int64) (tarStats, error) {
	tr := tar.NewReader(r)
	var stats tarStats
	type dirMeta struct {
		name string
		hdr  *tar.Header
	}
	var dirs []dirMeta
	asRoot := os.Geteuid() == 0
	for {
		if ctx.Err() != nil {
			return stats, ctx.Err()
		}
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return stats, fmt.Errorf("read snapshot: %w", err)
		}
		name, err := entryName(hdr.Name)
		if err != nil {
			return stats, err
		}
		if name == "." {
			continue
		}
		if parent := path.Dir(name); parent != "." {
			if err := root.MkdirAll(parent, 0o755); err != nil {
				return stats, err
			}
		}
		mode := fs.FileMode(hdr.Mode).Perm()
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := root.Mkdir(name, 0o700); err != nil && !errors.Is(err, fs.ErrExist) {
				return stats, err
			}
			dirs = append(dirs, dirMeta{name, hdr})
			continue
		case tar.TypeReg:
			if limit > 0 && stats.bytes+hdr.Size > limit {
				return stats, fmt.Errorf("snapshot is larger than the %d bytes it announced", limit)
			}
			f, err := root.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_EXCL|noFollow, 0o600)
			if err != nil {
				return stats, err
			}
			n, err := io.Copy(f, tr)
			if cerr := f.Close(); err == nil {
				err = cerr
			}
			if err != nil {
				return stats, err
			}
			stats.bytes += n
			stats.files++
			if err := root.Chmod(name, mode); err != nil {
				return stats, err
			}
		case tar.TypeSymlink:
			if err := root.Symlink(hdr.Linkname, name); err != nil {
				return stats, err
			}
		case tar.TypeLink:
			target, err := entryName(hdr.Linkname)
			if err != nil {
				return stats, err
			}
			if err := root.Link(target, name); err != nil {
				return stats, err
			}
			continue
		default:
			continue // devices, fifos: never created
		}
		if asRoot {
			_ = root.Lchown(name, hdr.Uid, hdr.Gid)
		}
		if hdr.Typeflag == tar.TypeReg {
			_ = root.Chtimes(name, hdr.ModTime, hdr.ModTime)
		}
	}
	// Directories last: their modes may forbid writing into them, and writing changes their mtimes.
	for i := len(dirs) - 1; i >= 0; i-- {
		d := dirs[i]
		if err := root.Chmod(d.name, fs.FileMode(d.hdr.Mode).Perm()); err != nil {
			return stats, err
		}
		if asRoot {
			_ = root.Lchown(d.name, d.hdr.Uid, d.hdr.Gid)
		}
		_ = root.Chtimes(d.name, d.hdr.ModTime, d.hdr.ModTime)
	}
	return stats, nil
}

// entryName validates a tar entry's name: relative, no "." or ".." segments once a leading "./" is dropped.
func entryName(name string) (string, error) {
	n := strings.TrimSuffix(strings.TrimPrefix(name, "./"), "/")
	if n == "" || n == "." {
		return ".", nil
	}
	if strings.HasPrefix(n, "/") || strings.ContainsRune(n, 0) || strings.Contains(n, `\`) {
		return "", fmt.Errorf("snapshot entry %q is not a relative path", name)
	}
	for _, seg := range strings.Split(n, "/") {
		if seg == "" || seg == "." || seg == ".." {
			return "", fmt.Errorf("snapshot entry %q leaves the volume", name)
		}
	}
	return n, nil
}

// empty reports whether a volume root holds nothing but a filesystem's lost+found.
func empty(root *os.Root) (bool, error) {
	f, err := root.Open(".")
	if err != nil {
		return false, err
	}
	defer f.Close()
	entries, err := f.ReadDir(-1)
	if err != nil {
		return false, err
	}
	for _, e := range entries {
		if e.Name() != "lost+found" {
			return false, nil
		}
	}
	return true, nil
}

// RestorePayload is volume.restore.
type RestorePayload struct {
	Volume            Ref               `json:"volume"`
	SizeBytes         int64             `json:"size_bytes,omitempty"`
	Labels            map[string]string `json:"labels,omitempty"`
	Source            Location          `json:"source"`
	SHA256            string            `json:"sha256"`
	UncompressedBytes int64             `json:"uncompressed_bytes,omitempty"`
}

// RestoreResult is its result (also volume.clone's, with Containers).
type RestoreResult struct {
	Bytes      int64    `json:"bytes"`
	Files      int64    `json:"files,omitempty"`
	DurationMS int64    `json:"duration_ms,omitempty"`
	Containers []string `json:"containers,omitempty"`
}

var shaRe = struct{ ok func(string) bool }{ok: func(s string) bool {
	if len(s) != 64 {
		return false
	}
	_, err := hex.DecodeString(s)
	return err == nil && strings.ToLower(s) == s
}}

// Restore downloads a snapshot, checks its sha256 before anything is written, and unpacks it into the volume
// (created when missing; one holding data is refused).
func (s *Service) Restore(ctx context.Context, p RestorePayload, st commands.Stream) (any, error) {
	start := time.Now()
	if p.Source.Kind != "url" || !strings.HasPrefix(p.Source.URL, "https://") {
		return nil, payloadErr("source must be an https url")
	}
	if !shaRe.ok(p.SHA256) {
		return nil, payloadErr("invalid sha256")
	}
	if err := s.check(p.Volume); err != nil {
		return nil, err
	}
	if _, err := s.create(ctx, p.Volume, p.SizeBytes, p.Labels, st); err != nil {
		return nil, err
	}
	root, hp, err := s.openRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	if ok, err := empty(root); err != nil {
		return nil, err
	} else if !ok {
		return nil, fmt.Errorf("volume %s is not empty: restores go into a new or empty volume", p.Volume.ID)
	}
	if p.UncompressedBytes > 0 {
		if us, err := s.d.StatFS(s.d.FS.P(hp)); err == nil && us.Available < uint64(p.UncompressedBytes) {
			return nil, fmt.Errorf("volume %s has %d bytes free, the snapshot needs %d", p.Volume.ID, us.Available, p.UncompressedBytes)
		}
	}
	file, err := s.fetch(ctx, p.Source, p.SHA256)
	if err != nil {
		return nil, err
	}
	defer os.Remove(file)
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	var in io.Reader = f
	if s.d.Open != nil { // step 4: backup encryption
		if in, err = s.d.Open(in); err != nil {
			return nil, err
		}
	}
	zr, err := zstd.NewReader(in)
	if err != nil {
		return nil, err
	}
	defer zr.Close()
	limit := p.UncompressedBytes
	stats, err := extract(ctx, root, zr, limit)
	if err != nil {
		return nil, fmt.Errorf("restore into volume %s: %w", p.Volume.ID, err)
	}
	fmt.Fprintf(st.Stdout(), "restored %d files (%d bytes) into volume %s\n", stats.files, stats.bytes, p.Volume.ID)
	return RestoreResult{Bytes: stats.bytes, Files: stats.files, DurationMS: time.Since(start).Milliseconds()}, nil
}

// fetch downloads a snapshot to a temp file and checks its sha256.
func (s *Service) fetch(ctx context.Context, src Location, want string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, src.URL, nil)
	if err != nil {
		return "", errors.New("invalid source url")
	}
	for k, v := range src.Headers {
		req.Header.Set(k, v)
	}
	resp, err := s.d.HTTP.Do(req)
	if err != nil {
		return "", fmt.Errorf("download snapshot: %w", redact(err))
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		return "", fmt.Errorf("download snapshot: %s", resp.Status)
	}
	f, err := os.CreateTemp(s.d.TempDir, "falak-volume-restore-*")
	if err != nil {
		return "", err
	}
	h := sha256.New()
	_, err = io.Copy(io.MultiWriter(f, h), resp.Body)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(f.Name())
		return "", fmt.Errorf("download snapshot: %w", redact(err))
	}
	if got := hex.EncodeToString(h.Sum(nil)); got != want {
		os.Remove(f.Name())
		return "", fmt.Errorf("snapshot checksum mismatch: got %s, want %s", got, want)
	}
	return f.Name(), nil
}

// ClonePayload is volume.clone.
type ClonePayload struct {
	Source      Ref               `json:"source"`
	Target      Ref               `json:"target"`
	SizeBytes   int64             `json:"size_bytes,omitempty"`
	Labels      map[string]string `json:"labels,omitempty"`
	Consistency string            `json:"consistency,omitempty"`
}

// Clone copies a volume into a new or empty one on the same server (a tar stream piped into the extractor).
func (s *Service) Clone(ctx context.Context, p ClonePayload, st commands.Stream) (any, error) {
	start := time.Now()
	if err := s.check(p.Target); err != nil {
		return nil, err
	}
	if p.Source.ID == p.Target.ID {
		return nil, payloadErr("source and target are the same volume")
	}
	src, hp, err := s.openRoot(ctx, p.Source)
	if err != nil {
		return nil, err
	}
	defer src.Close()
	if _, err := s.create(ctx, p.Target, p.SizeBytes, p.Labels, st); err != nil {
		return nil, err
	}
	dst, _, err := s.openRoot(ctx, p.Target)
	if err != nil {
		return nil, err
	}
	defer dst.Close()
	if ok, err := empty(dst); err != nil {
		return nil, err
	} else if !ok {
		return nil, fmt.Errorf("volume %s is not empty: clones go into a new or empty volume", p.Target.ID)
	}
	undo, touched, err := s.quiesce(ctx, p.Source, hp, p.Consistency, st)
	if err != nil {
		return nil, err
	}
	pr, pw := io.Pipe()
	done := make(chan struct{})
	go func() {
		defer close(done)
		_, err := writeTar(ctx, src, ".", pw)
		undo()
		pw.CloseWithError(err)
	}()
	stats, err := extract(ctx, dst, pr, 0)
	if err != nil {
		pr.CloseWithError(err) // unblocks the writer
	} else {
		_, _ = io.Copy(io.Discard, pr) // the tar trailer
	}
	<-done
	if err != nil {
		return nil, fmt.Errorf("clone into volume %s: %w", p.Target.ID, err)
	}
	fmt.Fprintf(st.Stdout(), "cloned volume %s into %s: %d files, %d bytes\n", p.Source.ID, p.Target.ID, stats.files, stats.bytes)
	return RestoreResult{Bytes: stats.bytes, Files: stats.files, DurationMS: time.Since(start).Milliseconds(), Containers: touched}, nil
}
