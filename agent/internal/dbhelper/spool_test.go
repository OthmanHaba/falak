package dbhelper

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writeTemp(t *testing.T, dir, name, content string) string {
	t.Helper()
	p := filepath.Join(dir, name)
	if err := os.WriteFile(p, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestSpoolFile(t *testing.T) {
	src := t.TempDir()
	spool := filepath.Join(t.TempDir(), "spool")
	seg := writeTemp(t, src, "000000010000000000000001", "segment one")

	s, err := spoolFile(seg, spool, spoolWAL, "000000010000000000000001")
	if err != nil {
		t.Fatal(err)
	}
	if s.Existed || s.Bytes != 11 || s.Path != filepath.Join(spool, "wal", "000000010000000000000001") {
		t.Errorf("first spool: %+v", s)
	}
	if b, _ := os.ReadFile(s.Path); string(b) != "segment one" {
		t.Errorf("spooled content %q", b)
	}

	// A retried archive_command with the same file: fine.
	again, err := spoolFile(seg, spool, spoolWAL, "000000010000000000000001")
	if err != nil || !again.Existed || again.SHA256 != s.SHA256 {
		t.Errorf("same file again: %+v, %v", again, err)
	}

	// Different content under the same name: refused, and the spooled file is untouched.
	other := writeTemp(t, src, "other", "segment two")
	if _, err := spoolFile(other, spool, spoolWAL, "000000010000000000000001"); ExitCode(err) != ExitConflict {
		t.Errorf("different content: %v (exit %d)", err, ExitCode(err))
	}
	if b, _ := os.ReadFile(s.Path); string(b) != "segment one" {
		t.Errorf("spooled file changed to %q", b)
	}

	// No temporary files left behind.
	entries, _ := os.ReadDir(filepath.Join(spool, "wal"))
	if len(entries) != 1 {
		t.Errorf("spool holds %d entries", len(entries))
	}
}

func TestSpoolFileRefuses(t *testing.T) {
	src := t.TempDir()
	spool := t.TempDir()
	f := writeTemp(t, src, "x", "x")
	for _, name := range []string{"", ".hidden", "../x", "a/b", "."} {
		if _, err := spoolFile(f, spool, spoolWAL, name); ExitCode(err) != ExitUsage {
			t.Errorf("name %q: %v", name, err)
		}
	}
	if _, err := spoolFile(src, spool, spoolWAL, "dir"); ExitCode(err) != ExitUsage {
		t.Errorf("a directory: %v", err)
	}
	if _, err := spoolFile(filepath.Join(src, "missing"), spool, spoolWAL, "missing"); err == nil {
		t.Error("a missing file was spooled")
	}
}

func TestFetchFile(t *testing.T) {
	from := t.TempDir()
	writeTemp(t, from, "000000010000000000000002", "wal")
	dstDir := t.TempDir()
	dst := filepath.Join(dstDir, "RECOVERYXLOG")
	s, err := fetchFile(from, "000000010000000000000002", dst)
	if err != nil || s.Bytes != 3 {
		t.Fatalf("fetch: %+v, %v", s, err)
	}
	if b, _ := os.ReadFile(dst); string(b) != "wal" {
		t.Errorf("fetched %q", b)
	}
	if _, err := fetchFile(from, "00000002.history", dst); ExitCode(err) != ExitNotFound {
		t.Errorf("missing segment: %v (exit %d)", err, ExitCode(err))
	}
	if _, err := fetchFile(from, "../etc/passwd", dst); ExitCode(err) != ExitUsage {
		t.Errorf("traversal: %v", err)
	}
}

func TestWriteFileAtomic(t *testing.T) {
	p := filepath.Join(t.TempDir(), "f.conf")
	if err := writeFileAtomic(p, []byte("a"), 0o640); err != nil {
		t.Fatal(err)
	}
	if err := writeFileAtomic(p, []byte("b"), 0o600); err != nil {
		t.Fatal(err)
	}
	st, _ := os.Stat(p)
	b, _ := os.ReadFile(p)
	if string(b) != "b" || st.Mode().Perm() != 0o600 {
		t.Errorf("content %q mode %v", b, st.Mode())
	}
	entries, _ := os.ReadDir(filepath.Dir(p))
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), ".tmp-") {
			t.Errorf("left %s", e.Name())
		}
	}
}
