package main

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"filippo.io/age"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
)

func backup(t *testing.T, enc backupcrypt.Encryption, data []byte) string {
	t.Helper()
	var b bytes.Buffer
	w, err := enc.Seal(&b)
	if err != nil {
		t.Fatal(err)
	}
	w.Write(data)
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(t.TempDir(), "backup.fkb")
	if err := os.WriteFile(p, b.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
	return p
}

func runCLI(args ...string) (int, string, string) {
	var out, errb bytes.Buffer
	code := run(args, strings.NewReader(""), &out, &errb)
	return code, out.String(), errb.String()
}

func TestCPKey(t *testing.T) {
	key := make([]byte, 32)
	rand.Read(key)
	data := []byte("pg_dump custom format bytes")
	file := backup(t, backupcrypt.Encryption{Mode: "cp", KeyID: "01jbackup", Key: base64.StdEncoding.EncodeToString(key)}, data)
	dir := t.TempDir()
	keyFile := filepath.Join(dir, "key")
	os.WriteFile(keyFile, []byte("# Falak backup key 01jbackup\n"+hex.EncodeToString(key)+"\n"), 0o600)

	code, out, errs := runCLI("--key-file", keyFile, file)
	if code != 0 || out != string(data) || !strings.Contains(errs, "verified") {
		t.Fatalf("code %d out %q err %q", code, out, errs)
	}
	sum := sha256.Sum256(data)
	target := filepath.Join(dir, "dump")
	if code, _, errs := runCLI("--key-file", keyFile, "--out", target, "--expect-sha256", hex.EncodeToString(sum[:]), file); code != 0 {
		t.Fatalf("--out: %d %s", code, errs)
	}
	if b, _ := os.ReadFile(target); !bytes.Equal(b, data) {
		t.Fatal("--out content differs")
	}
	if fi, _ := os.Stat(target); fi.Mode().Perm() != 0o600 {
		t.Fatalf("mode %v", fi.Mode())
	}
	// A wrong expected sha leaves nothing behind.
	other := filepath.Join(dir, "other")
	if code, _, _ := runCLI("--key-file", keyFile, "--out", other, "--expect-sha256", strings.Repeat("0", 64), file); code != 1 {
		t.Fatalf("wrong sha: %d", code)
	}
	if _, err := os.Stat(other); !os.IsNotExist(err) {
		t.Fatal("output left after a failed verification")
	}
	code, out, _ = runCLI("--info", file)
	if code != 0 || !strings.Contains(out, "key mode: cp") || !strings.Contains(out, "key id: 01jbackup") {
		t.Fatalf("info: %d %q", code, out)
	}
}

func TestTamperedAndWrongKey(t *testing.T) {
	key := make([]byte, 32)
	rand.Read(key)
	file := backup(t, backupcrypt.Encryption{Mode: "cp", KeyID: "k", Key: hex.EncodeToString(key)}, bytes.Repeat([]byte("row\n"), 50000))
	keyFile := filepath.Join(t.TempDir(), "key")
	os.WriteFile(keyFile, []byte(hex.EncodeToString(key)), 0o600)
	b, _ := os.ReadFile(file)
	b[len(b)-10] ^= 1
	os.WriteFile(file+".bad", b, 0o600)
	out := filepath.Join(t.TempDir(), "out")
	if code, _, errs := runCLI("--key-file", keyFile, "--out", out, file+".bad"); code != 1 || !strings.Contains(errs, "corrupt") {
		t.Fatalf("tampered: %d %s", code, errs)
	}
	if _, err := os.Stat(out); !os.IsNotExist(err) {
		t.Fatal("output left for a tampered file")
	}
	wrong := filepath.Join(t.TempDir(), "wrong")
	os.WriteFile(wrong, []byte(strings.Repeat("ab", 32)), 0o600)
	if code, _, _ := runCLI("--key-file", wrong, file); code != 1 {
		t.Fatalf("wrong key: %d", code)
	}
}

func TestAgeIdentity(t *testing.T) {
	id, _ := age.GenerateX25519Identity()
	data := []byte("REDIS0011 snapshot")
	file := backup(t, backupcrypt.Encryption{Mode: "age", KeyID: "kv", Recipient: id.Recipient().String()}, data)
	idFile := filepath.Join(t.TempDir(), "id.txt")
	os.WriteFile(idFile, []byte("# created: today\n# public key: "+id.Recipient().String()+"\n"+id.String()+"\n"), 0o600)
	code, out, errs := runCLI("--identity", idFile, file)
	if code != 0 || out != string(data) {
		t.Fatalf("%d %q %s", code, out, errs)
	}
	// The other kind of key is refused with a hint.
	keyFile := filepath.Join(t.TempDir(), "key")
	os.WriteFile(keyFile, []byte(strings.Repeat("00", 32)), 0o600)
	if code, _, errs := runCLI("--key-file", keyFile, file); code != 1 || !strings.Contains(errs, "customer-held") {
		t.Fatalf("key file for an age backup: %d %s", code, errs)
	}
}

func TestUsage(t *testing.T) {
	if code, _, _ := runCLI(); code != 2 {
		t.Fatal("no args")
	}
	if code, _, _ := runCLI("x"); code != 2 {
		t.Fatal("no key")
	}
	if code, _, _ := runCLI("--key-file", "a", "--identity", "b", "x"); code != 2 {
		t.Fatal("both keys")
	}
	if code, _, _ := runCLI("--key-file", "a", "--expect-sha256", "zz", "x"); code != 2 {
		t.Fatal("bad sha")
	}
	if code, _, errs := runCLI("--info", filepath.Join(t.TempDir(), "missing")); code != 1 || errs == "" {
		t.Fatal("missing file")
	}
}
