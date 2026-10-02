// Package enroll implements agent enrollment (one-time token + CSR) and certificate renewal, and
// persists credentials under the Kiln etc dir with 0600 permissions.
package enroll

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"time"
)

// RenewBefore is the remaining validity below which the agent renews its certificate.
const RenewBefore = 30 * 24 * time.Hour

// Endpoints returned at enrollment.
type Endpoints struct {
	API  string `json:"api"`
	OTLP string `json:"otlp"`
}

// Response mirrors enroll-response.schema.json.
type Response struct {
	AgentID   string    `json:"agent_id"`
	CertPEM   string    `json:"cert_pem"`
	CAPEM     string    `json:"ca_pem"`
	Endpoints Endpoints `json:"endpoints"`
}

// Request mirrors enroll-request.schema.json.
type Request struct {
	Token  string `json:"token"`
	CSRPEM string `json:"csr_pem"`
	Facts  any    `json:"facts"`
}

// State is persisted to agent.json (non-secret identity info).
type State struct {
	AgentID    string    `json:"agent_id"`
	Endpoints  Endpoints `json:"endpoints"`
	EnrolledAt time.Time `json:"enrolled_at"`
}

// Paths of the persisted credential files.
type Paths struct{ Dir string }

func (p Paths) Key() string   { return filepath.Join(p.Dir, "agent.key") }
func (p Paths) Cert() string  { return filepath.Join(p.Dir, "agent.crt") }
func (p Paths) CA() string    { return filepath.Join(p.Dir, "ca.crt") }
func (p Paths) State() string { return filepath.Join(p.Dir, "agent.json") }

// Files are the identity files, agent.json last.
func (p Paths) Files() []string { return []string{p.Key(), p.Cert(), p.CA(), p.State()} }

// Enrolled reports whether credentials exist.
func (p Paths) Enrolled() bool {
	for _, f := range p.Files() {
		if _, err := os.Stat(f); err != nil {
			return false
		}
	}
	return true
}

// Options for Enroll.
type Options struct {
	PanelURL string // https://panel.example (enroll endpoint is <panel>/agent/v1/enroll)
	Token    string
	Facts    any
	Hostname string // CSR common name hint; the control plane sets CN = agent id in the issued cert
	Paths    Paths
	Client   *http.Client // plain TLS (system roots) — nil uses a default client
}

// Enroll generates a P-256 key and CSR, posts it with the token and facts, and persists the
// returned credentials. Existing credentials are never overwritten (idempotent); delete them to re-enroll.
func Enroll(ctx context.Context, o Options) (*State, error) {
	if o.Paths.Enrolled() {
		return LoadState(o.Paths)
	}
	if o.PanelURL == "" || o.Token == "" {
		return nil, errors.New("enroll: panel URL and token are required")
	}
	key, csrPEM, err := NewKeyAndCSR(o.Hostname)
	if err != nil {
		return nil, err
	}
	body, _ := json.Marshal(Request{Token: o.Token, CSRPEM: string(csrPEM), Facts: o.Facts})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(o.PanelURL, "/")+"/agent/v1/enroll", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	hc := o.Client
	if hc == nil {
		hc = &http.Client{Timeout: 60 * time.Second}
	}
	resp, err := hc.Do(req)
	if err != nil {
		return nil, fmt.Errorf("enroll: %w", err)
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode/100 != 2 {
		return nil, fmt.Errorf("enroll: HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(raw)))
	}
	var er Response
	if err := json.Unmarshal(raw, &er); err != nil {
		return nil, fmt.Errorf("enroll: decode response: %w", err)
	}
	if er.AgentID == "" || er.CertPEM == "" || er.CAPEM == "" || er.Endpoints.API == "" {
		return nil, errors.New("enroll: incomplete response")
	}
	if err := verifyIssued(key, []byte(er.CertPEM), []byte(er.CAPEM)); err != nil {
		return nil, fmt.Errorf("enroll: %w", err)
	}
	keyPEM, err := MarshalKey(key)
	if err != nil {
		return nil, err
	}
	st := &State{AgentID: er.AgentID, Endpoints: er.Endpoints, EnrolledAt: time.Now().UTC()}
	stJSON, _ := json.MarshalIndent(st, "", "  ")
	// 0711: traversable (kiln-edge reads caddy/ and certs/ as the caddy user) but not listable;
	// every secret inside is 0600.
	if err := os.MkdirAll(o.Paths.Dir, 0o711); err != nil {
		return nil, err
	}
	// Write key/cert/ca first, state last: Enrolled() only becomes true once everything is on disk.
	for _, f := range []struct {
		path string
		data []byte
	}{{o.Paths.Key(), keyPEM}, {o.Paths.Cert(), []byte(er.CertPEM)}, {o.Paths.CA(), []byte(er.CAPEM)}, {o.Paths.State(), stJSON}} {
		if err := writeSecret(f.path, f.data); err != nil {
			return nil, err
		}
	}
	return st, nil
}

// LoadState reads agent.json.
func LoadState(p Paths) (*State, error) {
	b, err := os.ReadFile(p.State())
	if err != nil {
		return nil, err
	}
	var st State
	if err := json.Unmarshal(b, &st); err != nil {
		return nil, fmt.Errorf("parse %s: %w", p.State(), err)
	}
	return &st, nil
}

// NewKeyAndCSR generates an ECDSA P-256 key and a PEM CSR.
func NewKeyAndCSR(cn string) (*ecdsa.PrivateKey, []byte, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	if cn == "" {
		cn, _ = os.Hostname()
	}
	der, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{
		Subject:            pkix.Name{CommonName: cn, Organization: []string{"kiln-agent"}},
		SignatureAlgorithm: x509.ECDSAWithSHA256,
	}, key)
	if err != nil {
		return nil, nil, err
	}
	return key, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: der}), nil
}

// MarshalKey PEM-encodes an EC private key.
func MarshalKey(k *ecdsa.PrivateKey) ([]byte, error) {
	der, err := x509.MarshalECPrivateKey(k)
	if err != nil {
		return nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der}), nil
}

// verifyIssued checks that the issued cert matches our key and chains to the CA.
func verifyIssued(key *ecdsa.PrivateKey, certPEM, caPEM []byte) error {
	cert, err := parseCert(certPEM)
	if err != nil {
		return err
	}
	pub, ok := cert.PublicKey.(*ecdsa.PublicKey)
	if !ok || !pub.Equal(&key.PublicKey) {
		return errors.New("issued certificate does not match our key")
	}
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(caPEM) {
		return errors.New("invalid CA PEM")
	}
	_, err = cert.Verify(x509.VerifyOptions{Roots: pool, KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}})
	if err != nil {
		return fmt.Errorf("issued certificate does not verify against CA: %w", err)
	}
	return nil
}

func parseCert(certPEM []byte) (*x509.Certificate, error) {
	blk, _ := pem.Decode(certPEM)
	if blk == nil || blk.Type != "CERTIFICATE" {
		return nil, errors.New("invalid certificate PEM")
	}
	return x509.ParseCertificate(blk.Bytes)
}

// writeSecret writes a file atomically with 0600 permissions.
func writeSecret(path string, data []byte) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}

// Identity holds the live client certificate and is safe for concurrent use; renewal swaps it
// in place so the mTLS transport picks up the new cert on the next handshake.
type Identity struct {
	paths Paths
	cert  atomic.Pointer[tls.Certificate]
	leaf  atomic.Pointer[x509.Certificate]
	CA    *x509.CertPool
	State *State
}

// Load reads the persisted credentials.
func Load(p Paths) (*Identity, error) {
	st, err := LoadState(p)
	if err != nil {
		return nil, err
	}
	caPEM, err := os.ReadFile(p.CA())
	if err != nil {
		return nil, err
	}
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(caPEM) {
		return nil, errors.New("invalid CA file " + p.CA())
	}
	id := &Identity{paths: p, CA: pool, State: st}
	if err := id.reload(); err != nil {
		return nil, err
	}
	return id, nil
}

func (id *Identity) reload() error {
	c, err := tls.LoadX509KeyPair(id.paths.Cert(), id.paths.Key())
	if err != nil {
		return err
	}
	leaf, err := x509.ParseCertificate(c.Certificate[0])
	if err != nil {
		return err
	}
	c.Leaf = leaf
	id.cert.Store(&c)
	id.leaf.Store(leaf)
	return nil
}

// TLSConfig returns an mTLS client config pinned to the Kiln CA.
func (id *Identity) TLSConfig() *tls.Config {
	return &tls.Config{
		MinVersion: tls.VersionTLS12,
		RootCAs:    id.CA,
		GetClientCertificate: func(*tls.CertificateRequestInfo) (*tls.Certificate, error) {
			return id.cert.Load(), nil
		},
	}
}

// NotAfter returns the current certificate expiry.
func (id *Identity) NotAfter() time.Time { return id.leaf.Load().NotAfter }

// NotBefore returns the start of the current certificate's validity.
func (id *Identity) NotBefore() time.Time { return id.leaf.Load().NotBefore }

// Fingerprint returns the SHA-256 of the DER cert (lowercase hex), as forwarded by the edge.
func (id *Identity) Fingerprint() string {
	s := sha256.Sum256(id.leaf.Load().Raw)
	return hex.EncodeToString(s[:])
}

// NeedsRenewal reports whether fewer than RenewBefore remain.
func (id *Identity) NeedsRenewal(now time.Time) bool {
	return id.NotAfter().Sub(now) < RenewBefore
}

// RenewFunc posts a CSR to POST /agent/v1/renew over mTLS and returns the new cert PEM.
type RenewFunc func(ctx context.Context, csrPEM []byte) (certPEM []byte, err error)

// Renew generates a new key + CSR, obtains a new certificate and swaps it atomically on disk and in
// memory. The old key/cert stay in place if anything fails.
func (id *Identity) Renew(ctx context.Context, post RenewFunc) error {
	key, csr, err := NewKeyAndCSR(id.State.AgentID)
	if err != nil {
		return err
	}
	certPEM, err := post(ctx, csr)
	if err != nil {
		return fmt.Errorf("renew: %w", err)
	}
	caPEM, err := os.ReadFile(id.paths.CA())
	if err != nil {
		return err
	}
	if err := verifyIssued(key, certPEM, caPEM); err != nil {
		return fmt.Errorf("renew: %w", err)
	}
	keyPEM, err := MarshalKey(key)
	if err != nil {
		return err
	}
	// Key first then cert; if the cert write fails, roll the key back.
	oldKey, _ := os.ReadFile(id.paths.Key())
	if err := writeSecret(id.paths.Key(), keyPEM); err != nil {
		return err
	}
	if err := writeSecret(id.paths.Cert(), certPEM); err != nil {
		_ = writeSecret(id.paths.Key(), oldKey)
		return err
	}
	return id.reload()
}
