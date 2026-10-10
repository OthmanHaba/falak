package facts

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

func writeCert(t *testing.T, root, issuer, name string, notAfter time.Time) {
	t.Helper()
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: name}, NotBefore: notAfter.Add(-90 * 24 * time.Hour), NotAfter: notAfter}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(root, CaddyCertificates, issuer, name)
	os.MkdirAll(dir, 0o755)
	os.WriteFile(filepath.Join(dir, name+".crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644)
}

func TestTLSCertificates(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	if TLSCertificates(fs) != nil {
		t.Fatal("certificates without a storage directory")
	}
	soon := time.Date(2026, 10, 20, 0, 0, 0, 0, time.UTC)
	later := time.Date(2026, 12, 30, 0, 0, 0, 0, time.UTC)
	writeCert(t, root, "acme-v02.api.letsencrypt.org-directory", "example.com", soon)
	writeCert(t, root, "acme.zerossl.com-v2-dv90", "example.com", later) // the newer one wins
	writeCert(t, root, "acme-v02.api.letsencrypt.org-directory", "a.example.com", soon)
	os.MkdirAll(filepath.Join(root, CaddyCertificates, "acme-v02.api.letsencrypt.org-directory", "broken"), 0o755)
	os.WriteFile(filepath.Join(root, CaddyCertificates, "acme-v02.api.letsencrypt.org-directory", "broken", "broken.crt"), []byte("junk"), 0o644)

	got := TLSCertificates(fs)
	if len(got) != 2 || got[0] != (TLSCertificate{"a.example.com", "2026-10-20T00:00:00Z"}) || got[1] != (TLSCertificate{"example.com", "2026-12-30T00:00:00Z"}) {
		t.Errorf("certificates %+v", got)
	}
}

func TestRebootRequired(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	if RebootRequired(fs) {
		t.Fatal("reboot required without the flag file")
	}
	os.MkdirAll(filepath.Join(root, "var", "run"), 0o755)
	os.WriteFile(filepath.Join(root, "var", "run", "reboot-required"), []byte("*** System restart required ***\n"), 0o644)
	if !RebootRequired(fs) {
		t.Error("flag file ignored")
	}
}
