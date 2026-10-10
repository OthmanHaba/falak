package facts

import (
	"crypto/x509"
	"encoding/pem"
	"os"
	"path/filepath"
	"sort"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
)

// CaddyCertificates is where the edge (falak-edge.service, XDG_DATA_HOME=/var/lib/caddy) keeps the certificates it
// obtained over ACME: <issuer>/<name>/<name>.crt.
const CaddyCertificates = "/var/lib/caddy/caddy/certificates"

// MaxTLSCertificates caps the certificates in the facts.
const MaxTLSCertificates = 500

// TLSCertificate is a certificate the edge manages (ACME), with its expiry: renewals start 30 days before, so one
// expiring sooner means renewal is failing.
type TLSCertificate struct {
	Name     string `json:"name"`
	NotAfter string `json:"not_after"`
}

// RebootRequired reports whether the distribution asked for a reboot (Debian/Ubuntu: /var/run/reboot-required).
func RebootRequired(fs hostfs.FS) bool {
	return fs.Exists("/var/run/reboot-required") || fs.Exists("/run/reboot-required")
}

// TLSCertificates lists the edge's ACME certificates (the latest expiry per name, sorted by name); nil when there are
// none. Facts are re-sent only when they change, i.e. after a renewal.
func TLSCertificates(fs hostfs.FS) []TLSCertificate {
	issuers, err := os.ReadDir(fs.P(CaddyCertificates))
	if err != nil {
		return nil
	}
	latest := map[string]time.Time{}
	for _, issuer := range issuers {
		if !issuer.IsDir() {
			continue
		}
		names, err := os.ReadDir(filepath.Join(fs.P(CaddyCertificates), issuer.Name()))
		if err != nil {
			continue
		}
		for _, n := range names {
			if !n.IsDir() {
				continue
			}
			b, err := os.ReadFile(filepath.Join(fs.P(CaddyCertificates), issuer.Name(), n.Name(), n.Name()+".crt"))
			if err != nil {
				continue
			}
			if na, ok := leafNotAfter(b); ok && na.After(latest[n.Name()]) {
				latest[n.Name()] = na
			}
		}
	}
	if len(latest) == 0 {
		return nil
	}
	out := make([]TLSCertificate, 0, len(latest))
	for name, na := range latest {
		out = append(out, TLSCertificate{Name: name, NotAfter: na.UTC().Format(time.RFC3339)})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	if len(out) > MaxTLSCertificates {
		out = out[:MaxTLSCertificates]
	}
	return out
}

func leafNotAfter(b []byte) (time.Time, bool) {
	block, _ := pem.Decode(b)
	if block == nil || block.Type != "CERTIFICATE" {
		return time.Time{}, false
	}
	c, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return time.Time{}, false
	}
	return c.NotAfter, true
}
