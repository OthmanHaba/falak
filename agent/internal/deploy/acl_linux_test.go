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
	if fi.Mode().Perm() != 0o664 {
		t.Fatalf("new log file mode %v, want 0664 (group-writable whatever the umask)", fi.Mode().Perm())
	}
}

func TestCloseDirKeepsOthersOutButTheEdgeIn(t *testing.T) {
	dir := t.TempDir()
	old := EdgeUser
	defer func() { EdgeUser = old }()
	EdgeUser = "root" // an existing user stands in for the edge user
	closed, err := closeDir(dir)
	if err != nil {
		t.Fatal(err)
	}
	fi, _ := os.Stat(dir)
	if !closed {
		if fi.Mode().Perm() != 0o755 {
			t.Fatalf("without ACLs the dir must stay open, got %v", fi.Mode().Perm())
		}
		t.Skip("filesystem without POSIX ACLs")
	}
	if fi.Mode().Perm() != 0o750 {
		t.Fatalf("mode %v, want 0750", fi.Mode().Perm())
	}
	buf := make([]byte, 128)
	n, err := syscall.Getxattr(dir, aclAccessXattr, buf)
	if err != nil || n != len(edgeAccessACL(0o750, 0)) {
		t.Fatalf("access ACL: %d bytes, %v", n, err)
	}
	EdgeUser = "kiln-no-such-user"
	if _, err := closeDir(dir); err != nil {
		t.Fatal(err)
	}
}
