package db

import (
	"io"
	"os"
	"strings"
	"testing"
)

// A read error while streaming cancels the consumer's exec before it can see the end of its input, and a truncated
// file never passes the verification pass run before any restore.
func TestDumpReaderCancelsOnStreamErrors(t *testing.T) {
	h := newHarness(t)
	file, _ := sealedFile(t, h, strings.Repeat("row\n", 100000))
	b, _ := os.ReadFile(h.path(file))
	os.WriteFile(h.path(file), b[:len(b)/2], 0o600)
	in, err := openDump(h.path(file), testEnc, "")
	if err != nil {
		t.Fatal(err)
	}
	defer in.Close()
	cancelled := false
	in.cancel = func() { cancelled = true }
	if _, err := io.Copy(io.Discard, in); err == nil || !cancelled || in.check(nil) == nil {
		t.Fatalf("err %v cancelled %v", err, cancelled)
	}
	if err := verifyDump(h.path(file), testEnc, ""); err == nil {
		t.Fatal("a truncated backup verified")
	}
}
