package runtime

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
)

// NodePayload is runtime.node.install.
type NodePayload struct {
	Version string `json:"version"`
	SHA256  string `json:"sha256"`
	Default bool   `json:"default"`
	Mirror  string `json:"mirror"`
}

// NodeResult is its result.
type NodeResult struct {
	Changed bool   `json:"changed"`
	Prefix  string `json:"prefix"`
}

var semver = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+$`)

func nodeArch(a string) (string, error) {
	switch a {
	case "amd64":
		return "x64", nil
	case "arm64":
		return "arm64", nil
	}
	return "", fmt.Errorf("unsupported arch %s", a)
}

// NodeInstall installs an official Node.js tarball into /opt/kiln/node/<version>.
func (rt *Runtime) NodeInstall(ctx context.Context, p NodePayload, st commands.Stream) (any, error) {
	if !semver.MatchString(p.Version) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid node version %q", p.Version)}
	}
	mirror := strings.TrimRight(p.Mirror, "/")
	if mirror == "" {
		mirror = "https://nodejs.org/dist"
	}
	arch, err := nodeArch(rt.d.Arch)
	if err != nil {
		return nil, err
	}
	prefix := "/opt/kiln/node/" + p.Version
	res := NodeResult{Prefix: prefix}
	if !rt.d.FS.Exists(prefix + "/bin/node") {
		name := fmt.Sprintf("node-v%s-linux-%s.tar.gz", p.Version, arch)
		base := mirror + "/v" + p.Version + "/"
		sum := p.SHA256
		if sum == "" {
			if sum, err = rt.shasum(ctx, base+"SHASUMS256.txt", name); err != nil {
				return nil, err
			}
		}
		if err := rt.d.FS.MkdirAll("/opt/kiln/node", 0o755); err != nil {
			return nil, err
		}
		tmpDir, err := os.MkdirTemp(rt.d.FS.P("/opt/kiln/node"), ".dl-")
		if err != nil {
			return nil, err
		}
		defer os.RemoveAll(tmpDir)
		fmt.Fprintf(st.Stdout(), "downloading %s\n", base+name)
		tgz := filepath.Join(tmpDir, name)
		if _, _, err := Download(ctx, rt.d.HTTP, base+name, sum, tgz, 0o644, nil); err != nil {
			return nil, err
		}
		f, err := os.Open(tgz)
		if err != nil {
			return nil, err
		}
		out := filepath.Join(tmpDir, "tree")
		err = ExtractTarGz(f, out, 1)
		f.Close()
		if err != nil {
			return nil, err
		}
		if err := os.Rename(out, rt.d.FS.P(prefix)); err != nil {
			return nil, err
		}
		res.Changed = true
	}
	if p.Default {
		for _, b := range []string{"node", "npm", "npx", "corepack"} {
			if !rt.d.FS.Exists(prefix + "/bin/" + b) {
				continue
			}
			c, err := EnsureSymlink(rt.d.FS, "/usr/local/bin/"+b, prefix+"/bin/"+b)
			if err != nil {
				return nil, err
			}
			res.Changed = res.Changed || c
		}
	}
	return res, nil
}

func (rt *Runtime) shasum(ctx context.Context, url, name string) (string, error) {
	tmp, err := os.CreateTemp("", "shasums-")
	if err != nil {
		return "", err
	}
	tmp.Close()
	defer os.Remove(tmp.Name())
	if _, _, err := Download(ctx, rt.d.HTTP, url, "", tmp.Name(), 0o644, nil); err != nil {
		return "", err
	}
	f, err := os.Open(tmp.Name())
	if err != nil {
		return "", err
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fs := strings.Fields(sc.Text())
		if len(fs) == 2 && fs[1] == name {
			return fs[0], nil
		}
	}
	return "", fmt.Errorf("%s not listed in %s", name, url)
}

// EnsureSymlink atomically points link (host path) at target (host path, stored unrooted).
func EnsureSymlink(fs hostfs.FS, link, target string) (bool, error) {
	if cur, err := os.Readlink(fs.P(link)); err == nil && cur == target {
		return false, nil
	}
	if err := fs.MkdirAll(path.Dir(link), 0o755); err != nil {
		return false, err
	}
	tmp := fs.P(link) + ".kiln-tmp"
	os.Remove(tmp)
	if err := os.Symlink(target, tmp); err != nil {
		return false, err
	}
	return true, os.Rename(tmp, fs.P(link))
}

// ExtractTarGz safely extracts a gzip tarball into dst, stripping `strip` leading path components.
// Absolute paths, ".." escapes and links pointing outside dst are rejected.
func ExtractTarGz(r io.Reader, dst string, strip int) error {
	gz, err := gzip.NewReader(r)
	if err != nil {
		return err
	}
	defer gz.Close()
	return ExtractTar(gz, dst, strip)
}

// ExtractTar is ExtractTarGz without decompression.
func ExtractTar(r io.Reader, dst string, strip int) error {
	if err := os.MkdirAll(dst, 0o755); err != nil {
		return err
	}
	root, err := filepath.Abs(dst)
	if err != nil {
		return err
	}
	inside := func(p string) bool {
		return p == root || strings.HasPrefix(p, root+string(filepath.Separator))
	}
	tr := tar.NewReader(r)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		name := h.Name
		if strings.HasPrefix(name, "/") {
			return fmt.Errorf("tar: absolute path %q", name)
		}
		for _, part := range strings.Split(name, "/") {
			if part == ".." {
				return fmt.Errorf("tar: path traversal in %q", name)
			}
		}
		parts := strings.Split(strings.Trim(path.Clean(name), "/"), "/")
		if len(parts) <= strip {
			continue
		}
		rel := filepath.Join(parts[strip:]...)
		target := filepath.Join(root, rel)
		if !inside(target) {
			return fmt.Errorf("tar: path escapes destination: %q", name)
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
			f, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
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
			if filepath.IsAbs(h.Linkname) {
				return fmt.Errorf("tar: absolute symlink %q -> %q", name, h.Linkname)
			}
			if !inside(filepath.Join(filepath.Dir(target), h.Linkname)) {
				return fmt.Errorf("tar: symlink escapes destination: %q -> %q", name, h.Linkname)
			}
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			os.Remove(target)
			if err := os.Symlink(h.Linkname, target); err != nil {
				return err
			}
		case tar.TypeLink:
			lp := strings.Split(strings.Trim(path.Clean(h.Linkname), "/"), "/")
			if len(lp) <= strip || strings.Contains(h.Linkname, "..") {
				return fmt.Errorf("tar: bad hardlink %q", h.Linkname)
			}
			src := filepath.Join(root, filepath.Join(lp[strip:]...))
			if !inside(src) {
				return fmt.Errorf("tar: hardlink escapes destination: %q", h.Linkname)
			}
			os.Remove(target)
			if err := os.Link(src, target); err != nil {
				return err
			}
		default:
			// devices, fifos etc. are skipped
		}
	}
}
