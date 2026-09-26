package deploy

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
)

// Artifact to download.
type Artifact struct {
	URL       string            `json:"url"`
	SHA256    string            `json:"sha256"`
	SizeBytes int64             `json:"size_bytes,omitempty"`
	Format    string            `json:"format,omitempty"`
	Headers   map[string]string `json:"headers,omitempty"`
}

// FetchPayload is deploy.fetch.
type FetchPayload struct {
	Site      string   `json:"site"`
	ReleaseID string   `json:"release_id"`
	SitesRoot string   `json:"sites_root,omitempty"`
	Artifact  Artifact `json:"artifact"`
	Owner     *Owner   `json:"owner,omitempty"`
}

// FetchResult is deploy.fetch's result.
type FetchResult struct {
	Changed    bool   `json:"changed"`
	ReleaseDir string `json:"release_dir"`
	Bytes      int64  `json:"bytes,omitempty"`
}

// markerFile records which artifact a release was extracted from (idempotency).
const markerFile = ".kiln-release.json"

type marker struct {
	ReleaseID string    `json:"release_id"`
	SHA256    string    `json:"sha256"`
	FetchedAt time.Time `json:"fetched_at"`
}

// Fetch downloads, verifies and extracts the artifact into releases/<id>. Extraction happens in a
// hidden staging dir that is renamed into place, so a release dir is always complete.
func (d *Deployer) Fetch(ctx context.Context, p FetchPayload, s commands.Stream) (any, error) {
	st, err := d.site(p.Site, p.SitesRoot)
	if err != nil {
		return nil, err
	}
	if err := checkRelease(p.ReleaseID); err != nil {
		return nil, err
	}
	if !strings.HasPrefix(p.Artifact.URL, "https://") {
		return nil, &commands.PayloadError{Err: errors.New("artifact url must be https")}
	}
	want := strings.ToLower(p.Artifact.SHA256)
	res := FetchResult{ReleaseDir: st.hostRelease(p.ReleaseID)}
	if b, err := os.ReadFile(filepath.Join(st.release(p.ReleaseID), markerFile)); err == nil {
		var m marker
		if json.Unmarshal(b, &m) == nil && m.SHA256 == want {
			fmt.Fprintf(s.Stdout(), "release %s already fetched\n", p.ReleaseID)
			return res, nil
		}
		return nil, fmt.Errorf("release %s exists with a different artifact (sha256 %s)", p.ReleaseID, m.SHA256)
	} else if _, err := os.Stat(st.release(p.ReleaseID)); err == nil {
		return nil, fmt.Errorf("release dir %s exists but has no marker; refusing to overwrite", res.ReleaseDir)
	}
	for _, dir := range []string{st.real, st.releases(), st.shared()} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			return nil, err
		}
	}
	tmpDir := filepath.Join(st.real, ".tmp")
	if err := os.MkdirAll(tmpDir, 0o700); err != nil {
		return nil, err
	}
	archive := filepath.Join(tmpDir, p.ReleaseID+".artifact")
	defer os.Remove(archive)
	n, err := d.download(ctx, p.Artifact, want, archive, s)
	if err != nil {
		return nil, err
	}
	res.Bytes = n
	staging := filepath.Join(st.releases(), "."+p.ReleaseID+".partial-"+randHex(4))
	defer os.RemoveAll(staging)
	if err := os.MkdirAll(staging, 0o755); err != nil {
		return nil, err
	}
	format := p.Artifact.Format
	if format == "" {
		format = "tar.gz"
	}
	switch format {
	case "tar.gz", "tar":
		f, err := os.Open(archive)
		if err != nil {
			return nil, err
		}
		var r io.Reader = f
		if format == "tar.gz" {
			gz, err := gzip.NewReader(f)
			if err != nil {
				f.Close()
				return nil, fmt.Errorf("artifact is not gzip: %w", err)
			}
			r = gz
		}
		err = Extract(r, staging)
		f.Close()
		if err != nil {
			return nil, err
		}
	case "tar.zst":
		// No zstd in the stdlib: GNU tar (which refuses absolute and ../ member names) does it.
		if _, err := runner.Check(ctx, d.o.Runner, runner.Cmd{Name: "tar", Args: []string{"--zstd", "-xf", archive, "-C", staging, "--no-same-owner"}, Stderr: s.Stderr()}); err != nil {
			return nil, err
		}
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("unsupported artifact format %q", format)}
	}
	mb, _ := json.Marshal(marker{ReleaseID: p.ReleaseID, SHA256: want, FetchedAt: time.Now().UTC()})
	if err := os.WriteFile(filepath.Join(staging, markerFile), mb, 0o644); err != nil {
		return nil, err
	}
	if p.Owner != nil && p.Owner.User != "" && d.o.FS.IsReal() {
		if err := d.o.FS.ChownR(filepath.Join(st.host, "releases", filepath.Base(staging)), p.Owner.User, p.Owner.Group); err != nil {
			return nil, err
		}
	}
	if err := os.Rename(staging, st.release(p.ReleaseID)); err != nil {
		return nil, err
	}
	res.Changed = true
	fmt.Fprintf(s.Stdout(), "release %s extracted (%d bytes)\n", p.ReleaseID, n)
	return res, nil
}

func (d *Deployer) download(ctx context.Context, a Artifact, want, dst string, s commands.Stream) (int64, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, a.URL, nil)
	if err != nil {
		return 0, err
	}
	for k, v := range a.Headers {
		req.Header.Set(k, v)
	}
	resp, err := d.o.HTTP.Do(req)
	if err != nil {
		return 0, fmt.Errorf("download artifact: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return 0, fmt.Errorf("download artifact: HTTP %d", resp.StatusCode)
	}
	f, err := os.OpenFile(dst, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return 0, err
	}
	h := sha256.New()
	total := a.SizeBytes
	if total <= 0 {
		total = resp.ContentLength
	}
	pw := &progressWriter{s: s, total: total}
	n, err := io.Copy(io.MultiWriter(f, h, pw), resp.Body)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return n, fmt.Errorf("download artifact: %w", err)
	}
	if a.SizeBytes > 0 && n != a.SizeBytes {
		return n, fmt.Errorf("artifact size %d != expected %d", n, a.SizeBytes)
	}
	if got := hex.EncodeToString(h.Sum(nil)); got != want {
		return n, fmt.Errorf("artifact sha256 mismatch: got %s want %s", got, want)
	}
	return n, nil
}

type progressWriter struct {
	s       commands.Stream
	total   int64
	n       int64
	lastPct int
}

func (p *progressWriter) Write(b []byte) (int, error) {
	p.n += int64(len(b))
	if p.total > 0 {
		pct := int(p.n * 100 / p.total)
		if pct >= p.lastPct+10 {
			p.lastPct = pct - pct%10
			p.s.Progress(float64(p.n) / float64(p.total))
		}
	}
	return len(b), nil
}

// Extract untars r into dst safely: member names must stay inside dst, and nothing is ever written
// through a symlink (a malicious archive cannot place `x -> /etc` and then `x/passwd`).
func Extract(r io.Reader, dst string) error {
	tr := tar.NewReader(r)
	root, err := filepath.Abs(dst)
	if err != nil {
		return err
	}
	for {
		h, err := tr.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return fmt.Errorf("read tar: %w", err)
		}
		name := filepath.Clean(strings.TrimPrefix(h.Name, "./"))
		if name == "." {
			continue
		}
		if filepath.IsAbs(h.Name) || name == ".." || strings.HasPrefix(name, "../") {
			return fmt.Errorf("tar member %q escapes the release dir", h.Name)
		}
		target := filepath.Join(root, name)
		if err := noSymlinkParents(root, filepath.Dir(target)); err != nil {
			return fmt.Errorf("tar member %q: %w", h.Name, err)
		}
		mode := os.FileMode(h.Mode).Perm()
		switch h.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, mode|0o700); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			if st, err := os.Lstat(target); err == nil && st.Mode()&os.ModeSymlink != 0 {
				return fmt.Errorf("tar member %q would overwrite a symlink", h.Name)
			}
			f, err := os.OpenFile(target, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, mode)
			if err != nil {
				return err
			}
			if _, err := io.Copy(f, tr); err != nil {
				f.Close()
				return err
			}
			if err := f.Close(); err != nil {
				return err
			}
		case tar.TypeSymlink:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			// Symlinks may point anywhere (e.g. to shared/), but are never followed during extraction.
			_ = os.Remove(target)
			if err := os.Symlink(h.Linkname, target); err != nil {
				return err
			}
		case tar.TypeLink:
			src := filepath.Join(root, filepath.Clean(h.Linkname))
			if !strings.HasPrefix(src, root+string(filepath.Separator)) {
				return fmt.Errorf("hard link %q escapes the release dir", h.Name)
			}
			if err := os.Link(src, target); err != nil {
				return err
			}
		default:
			// Devices, fifos etc. are ignored.
		}
	}
}

func noSymlinkParents(root, dir string) error {
	rel, err := filepath.Rel(root, dir)
	if err != nil || strings.HasPrefix(rel, "..") {
		return errors.New("path escapes root")
	}
	if rel == "." {
		return nil
	}
	cur := root
	for _, part := range strings.Split(rel, string(filepath.Separator)) {
		cur = filepath.Join(cur, part)
		st, err := os.Lstat(cur)
		if errors.Is(err, os.ErrNotExist) {
			return nil
		}
		if err != nil {
			return err
		}
		if st.Mode()&os.ModeSymlink != 0 {
			return fmt.Errorf("parent %s is a symlink", cur)
		}
	}
	return nil
}
