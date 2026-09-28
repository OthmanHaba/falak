package version

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"testing"
)

func TestBinarySHA256IsTheRunningExecutable(t *testing.T) {
	exe, err := os.Executable()
	if err != nil {
		t.Skip(err)
	}
	f, err := os.Open(exe)
	if err != nil {
		t.Skip(err)
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		t.Fatal(err)
	}
	if got, want := BinarySHA256(), hex.EncodeToString(h.Sum(nil)); got != want {
		t.Fatalf("BinarySHA256() = %s, want %s", got, want)
	}
}
