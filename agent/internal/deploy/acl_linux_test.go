//go:build linux

package deploy

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

func TestGroupWritableDefaultACLOverridesUmask(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "storage", "logs")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := groupWritable(root, filepath.Join(root, "storage")); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 64)
	if _, err := syscall.Getxattr(dir, aclDefaultXattr, buf); err != nil {
		t.Skipf("filesystem without POSIX ACLs: %v", err)
	}
	old := syscall.Umask(0o022)
	defer syscall.Umask(old)
	f, err := os.OpenFile(filepath.Join(dir, "laravel.log"), os.O_CREATE|os.O_WRONLY, 0o666)
	if err != nil {
		t.Fatal(err)
	}
	f.Close()
	fi, _ := os.Stat(filepath.Join(dir, "laravel.log"))
	if fi.Mode().Perm() != 0o660 {
		t.Fatalf("new log file mode %v, want 0660 (group-writable, not world-readable)", fi.Mode().Perm())
	}
}
