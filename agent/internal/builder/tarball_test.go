package builder

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"io"
	"os"
	"path/filepath"
	"sort"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/deploy"
)

func tarEntries(t *testing.T, b []byte) []*tar.Header {
	t.Helper()
	gz, err := gzip.NewReader(bytes.NewReader(b))
	if err != nil {
		t.Fatal(err)
	}
	if !gz.ModTime.IsZero() || gz.Name != "" {
		t.Fatalf("gzip header not neutral: %v %q", gz.ModTime, gz.Name)
	}
	tr := tar.NewReader(gz)
	var hs []*tar.Header
	for {
		h, err := tr.Next()
		if err == io.EOF {
			return hs
		}
		if err != nil {
			t.Fatal(err)
		}
		hs = append(hs, h)
	}
}

func TestTarballDeterministic(t *testing.T) {
	mtime := time.Unix(1700000000, 0)
	a, b := t.TempDir(), t.TempDir()
	copyDir(t, filepath.Join(fixtures, "laravel"), a)
	// b: same content, created in a different order, different mtimes/perms-noise, plus excluded junk.
	copyDir(t, filepath.Join(fixtures, "laravel"), b)
	for _, dir := range []string{a, b} {
		_ = os.MkdirAll(filepath.Join(dir, "vendor", "composer"), 0o755)
		_ = os.WriteFile(filepath.Join(dir, "vendor", "autoload.php"), []byte("<?php // autoload\n"), 0o644)
		_ = os.Symlink("../storage/app/public", filepath.Join(dir, "public", "storage"))
	}
	past := time.Now().Add(-72 * time.Hour)
	filepath.Walk(b, func(p string, _ os.FileInfo, _ error) error { return os.Chtimes(p, past, past) })
	_ = os.Chmod(filepath.Join(b, "routes", "web.php"), 0o600) // non-exec perms normalize to 0644
	_ = os.MkdirAll(filepath.Join(b, ".git", "objects"), 0o755)
	_ = os.WriteFile(filepath.Join(b, ".git", "HEAD"), []byte("x"), 0o644)
	_ = os.MkdirAll(filepath.Join(b, "node_modules", "vite"), 0o755)
	_ = os.WriteFile(filepath.Join(b, "node_modules", "vite", "index.js"), []byte("x"), 0o644)
	_ = os.WriteFile(filepath.Join(b, ".env"), []byte("APP_KEY=secret"), 0o644)
	_ = os.WriteFile(filepath.Join(b, ".DS_Store"), []byte("x"), 0o644)

	ex := append(append([]string{}, DefaultExcludes...), "/node_modules")
	var ba, bb bytes.Buffer
	ia, err := WriteTarball(&ba, a, ex, mtime)
	if err != nil {
		t.Fatal(err)
	}
	ib, err := WriteTarball(&bb, b, ex, mtime)
	if err != nil {
		t.Fatal(err)
	}
	if ia.SHA256 != ib.SHA256 || !bytes.Equal(ba.Bytes(), bb.Bytes()) {
		t.Fatalf("same tree, different archives: %s vs %s", ia.SHA256, ib.SHA256)
	}
	if ia.SizeBytes != int64(ba.Len()) || len(ia.SHA256) != 64 {
		t.Fatalf("info = %+v (len %d)", ia, ba.Len())
	}
	// Re-running is stable too.
	var bc bytes.Buffer
	if ic, _ := WriteTarball(&bc, a, ex, mtime); ic.SHA256 != ia.SHA256 {
		t.Fatal("second run differs")
	}

	hs := tarEntries(t, ba.Bytes())
	var names []string
	for _, h := range hs {
		names = append(names, h.Name)
		if !h.ModTime.Equal(mtime) || h.Uid != 0 || h.Gid != 0 || h.Uname != "" || h.Gname != "" {
			t.Fatalf("non-normalized header %+v", h)
		}
		switch h.Name {
		case "artisan":
			if h.Mode != 0o755 {
				t.Fatalf("artisan mode %o", h.Mode)
			}
		case "routes/web.php":
			if h.Mode != 0o644 {
				t.Fatalf("web.php mode %o", h.Mode)
			}
		case "public/storage":
			if h.Typeflag != tar.TypeSymlink || h.Linkname != "../storage/app/public" {
				t.Fatalf("symlink %+v", h)
			}
		case ".git/", ".git/HEAD", "node_modules/", ".env", ".DS_Store":
			t.Fatalf("excluded entry %s archived", h.Name)
		}
	}
	if !sort.StringsAreSorted(names) {
		t.Fatalf("entries not sorted: %v", names)
	}
	for _, want := range []string{"vendor/autoload.php", ".env.example", "storage/app/.gitignore", "public/index.php"} {
		if !contains(names, want) {
			t.Fatalf("missing %s in %v", want, names)
		}
	}

	// Different content → different sha.
	_ = os.WriteFile(filepath.Join(a, "routes", "web.php"), []byte("changed"), 0o644)
	var bd bytes.Buffer
	if id, _ := WriteTarball(&bd, a, ex, mtime); id.SHA256 == ia.SHA256 {
		t.Fatal("changed tree, same sha")
	}

	// Round-trips through the agent's deploy.fetch extractor.
	dst := t.TempDir()
	gz, _ := gzip.NewReader(bytes.NewReader(bb.Bytes()))
	if err := deploy.Extract(gz, dst); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(filepath.Join(dst, "vendor", "autoload.php")); string(got) != "<?php // autoload\n" {
		t.Fatalf("extracted autoload = %q", got)
	}
}

func TestTarballLongNames(t *testing.T) {
	dir := t.TempDir()
	long := filepath.Join(dir, "a-very-long-directory-name-that-goes-on-and-on", "and-another-long-directory-name-for-good-measure", "file-with-a-long-name.txt")
	_ = os.MkdirAll(filepath.Dir(long), 0o755)
	_ = os.WriteFile(long, []byte("x"), 0o644)
	var a, b bytes.Buffer
	ia, err := WriteTarball(&a, dir, nil, defaultMTime)
	if err != nil {
		t.Fatal(err)
	}
	ib, _ := WriteTarball(&b, dir, nil, defaultMTime)
	if ia.SHA256 != ib.SHA256 {
		t.Fatal("not deterministic with PAX names")
	}
	hs := tarEntries(t, a.Bytes())
	if hs[len(hs)-1].Name != filepath.ToSlash(long[len(dir)+1:]) {
		t.Fatalf("long name = %q", hs[len(hs)-1].Name)
	}
}

func TestExcluded(t *testing.T) {
	cases := map[string]bool{".git": true, "vendor/pkg/.git": true, "node_modules": false, ".env": true, "config/.env": false, ".env.production.local": true, ".env.example": false,
		// The build's own logs never ship (they would land in shared storage); the directory placeholder does.
		"storage/logs/laravel.log": true, "storage/logs/laravel-2026-09-28.log": true, "storage/logs/.gitignore": false, "storage/logs": false,
		"vendor/pkg/storage/logs/x.log": false}
	for rel, want := range cases {
		if got := excluded(rel, DefaultExcludes); got != want {
			t.Errorf("excluded(%q) = %v want %v", rel, got, want)
		}
	}
	if !excluded("node_modules", []string{"/node_modules"}) || excluded("vendor/x/node_modules", []string{"/node_modules"}) {
		t.Error("anchored pattern")
	}
}

func contains(ss []string, s string) bool {
	for _, x := range ss {
		if x == s {
			return true
		}
	}
	return false
}
