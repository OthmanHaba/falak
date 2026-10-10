package hostfs

import (
	"os"
	"testing"
)

func TestEnsureTraversableOpensOnlyTheSearchBit(t *testing.T) {
	f := FS{Root: t.TempDir()}
	if err := os.MkdirAll(f.P("/etc/falak"), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(f.P("/etc/falak"), 0o700); err != nil {
		t.Fatal(err)
	}
	changed, err := EnsureTraversable(f, "/etc/falak")
	if err != nil || !changed {
		t.Fatalf("changed %v err %v", changed, err)
	}
	fi, _ := os.Stat(f.P("/etc/falak"))
	if fi.Mode().Perm() != 0o711 {
		t.Fatalf("mode %o", fi.Mode().Perm())
	}
	// Already traversable: untouched; missing: no error, nothing created.
	if changed, err := EnsureTraversable(f, "/etc/falak"); err != nil || changed {
		t.Fatalf("second call changed %v err %v", changed, err)
	}
	if changed, err := EnsureTraversable(f, "/etc/missing"); err != nil || changed {
		t.Fatalf("missing changed %v err %v", changed, err)
	}
	if _, err := os.Stat(f.P("/etc/missing")); !os.IsNotExist(err) {
		t.Fatal("created a missing directory")
	}
}
