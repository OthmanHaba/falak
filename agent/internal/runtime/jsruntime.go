package runtime

import (
	"archive/zip"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// JSRuntimePayload is runtime.bun.install / runtime.deno.install.
type JSRuntimePayload struct {
	Version string `json:"version"`
	SHA256  string `json:"sha256"`
	Default bool   `json:"default"`
	Mirror  string `json:"mirror"`
}

// jsRuntime describes a single-binary JavaScript runtime published as a zip per platform.
type jsRuntime struct {
	name   string
	mirror string
	// asset returns the release directory URL (under mirror) and zip name for a version/arch.
	asset func(mirror, version, arch string) (dir, zip string, err error)
	// checksum returns the sha256 for the zip from the release's published checksums.
	checksum func(rt *Runtime, ctx context.Context, dir, zip string) (string, error)
}

var bun = jsRuntime{
	name:   "bun",
	mirror: "https://github.com/oven-sh/bun/releases/download",
	asset: func(mirror, version, arch string) (string, string, error) {
		a := map[string]string{"amd64": "x64", "arm64": "aarch64"}[arch]
		if a == "" {
			return "", "", fmt.Errorf("unsupported arch %s", arch)
		}
		return mirror + "/bun-v" + version + "/", "bun-linux-" + a + ".zip", nil
	},
	checksum: func(rt *Runtime, ctx context.Context, dir, zip string) (string, error) {
		return rt.shasum(ctx, dir+"SHASUMS256.txt", zip)
	},
}

var deno = jsRuntime{
	name:   "deno",
	mirror: "https://github.com/denoland/deno/releases/download",
	asset: func(mirror, version, arch string) (string, string, error) {
		a := map[string]string{"amd64": "x86_64", "arm64": "aarch64"}[arch]
		if a == "" {
			return "", "", fmt.Errorf("unsupported arch %s", arch)
		}
		return mirror + "/v" + version + "/", "deno-" + a + "-unknown-linux-gnu.zip", nil
	},
	checksum: func(rt *Runtime, ctx context.Context, dir, zip string) (string, error) {
		return rt.shasum(ctx, dir+zip+".sha256sum", zip) // "<sha>  <zip>"
	},
}

// BunInstall installs the official Bun binary into /opt/falak/bun/<version>/bin/bun.
func (rt *Runtime) BunInstall(ctx context.Context, p JSRuntimePayload, st commands.Stream) (any, error) {
	return rt.installJSRuntime(ctx, bun, p, st)
}

// DenoInstall installs the official Deno binary into /opt/falak/deno/<version>/bin/deno.
func (rt *Runtime) DenoInstall(ctx context.Context, p JSRuntimePayload, st commands.Stream) (any, error) {
	return rt.installJSRuntime(ctx, deno, p, st)
}

func (rt *Runtime) installJSRuntime(ctx context.Context, r jsRuntime, p JSRuntimePayload, st commands.Stream) (any, error) {
	if !semver.MatchString(p.Version) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid %s version %q", r.name, p.Version)}
	}
	mirror := strings.TrimRight(p.Mirror, "/")
	if mirror == "" {
		mirror = r.mirror
	}
	dir, name, err := r.asset(mirror, p.Version, rt.d.Arch)
	if err != nil {
		return nil, err
	}
	prefix := "/opt/falak/" + r.name + "/" + p.Version
	bin := prefix + "/bin/" + r.name
	res := NodeResult{Prefix: prefix}
	if !rt.d.FS.Exists(bin) {
		sum := p.SHA256
		if sum == "" {
			if sum, err = r.checksum(rt, ctx, dir, name); err != nil {
				return nil, err
			}
		}
		if err := rt.d.FS.MkdirAll("/opt/falak/"+r.name, 0o755); err != nil {
			return nil, err
		}
		tmpDir, err := os.MkdirTemp(rt.d.FS.P("/opt/falak/"+r.name), ".dl-")
		if err != nil {
			return nil, err
		}
		defer os.RemoveAll(tmpDir)
		fmt.Fprintf(st.Stdout(), "downloading %s\n", dir+name)
		archive := filepath.Join(tmpDir, name)
		if _, _, err := Download(ctx, rt.d.HTTP, dir+name, sum, archive, 0o644, nil); err != nil {
			return nil, err
		}
		tree := filepath.Join(tmpDir, "tree")
		if err := extractZipBinary(archive, r.name, filepath.Join(tree, "bin", r.name)); err != nil {
			return nil, err
		}
		if err := os.Rename(tree, rt.d.FS.P(prefix)); err != nil {
			return nil, err
		}
		res.Changed = true
	}
	if p.Default {
		c, err := EnsureSymlink(rt.d.FS, "/usr/local/bin/"+r.name, bin)
		if err != nil {
			return nil, err
		}
		res.Changed = res.Changed || c
	}
	return res, nil
}

// extractZipBinary writes the zip entry whose base name is `name` (at any depth, e.g.
// bun-linux-x64/bun) to dst with mode 0755. Only that single entry is read, so entry paths are
// never used as filesystem paths.
func extractZipBinary(archive, name, dst string) error {
	zr, err := zip.OpenReader(archive)
	if err != nil {
		return err
	}
	defer zr.Close()
	for _, f := range zr.File {
		if f.FileInfo().IsDir() || filepath.Base(f.Name) != name {
			continue
		}
		if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
			return err
		}
		rc, err := f.Open()
		if err != nil {
			return err
		}
		defer rc.Close()
		out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
		if err != nil {
			return err
		}
		if _, err := io.Copy(out, io.LimitReader(rc, 512<<20)); err != nil {
			out.Close()
			return err
		}
		return out.Close()
	}
	return fmt.Errorf("%s not found in %s", name, filepath.Base(archive))
}
