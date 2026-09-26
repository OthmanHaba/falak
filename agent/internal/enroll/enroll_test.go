package enroll

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"
	"time"
)

// testCA is a minimal CA that signs CSRs like the Fleet module does.
type testCA struct {
	key  *ecdsa.PrivateKey
	cert *x509.Certificate
	pem  []byte
}

func newTestCA(t *testing.T) *testCA {
	t.Helper()
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Kiln Test CA"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(24 * time.Hour),
		IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign,
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &k.PublicKey, k)
	if err != nil {
		t.Fatal(err)
	}
	c, _ := x509.ParseCertificate(der)
	return &testCA{key: k, cert: c, pem: pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})}
}

func (ca *testCA) sign(t *testing.T, csrPEM string, cn string, validity time.Duration) string {
	t.Helper()
	blk, _ := pem.Decode([]byte(csrPEM))
	if blk == nil {
		t.Fatal("bad csr pem")
	}
	csr, err := x509.ParseCertificateRequest(blk.Bytes)
	if err != nil {
		t.Fatal(err)
	}
	if err := csr.CheckSignature(); err != nil {
		t.Fatal(err)
	}
	if _, ok := csr.PublicKey.(*ecdsa.PublicKey); !ok {
		t.Fatal("csr key is not ECDSA")
	}
	tpl := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: pkix.Name{CommonName: cn},
		NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(validity),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, ca.cert, csr.PublicKey, ca.key)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
}

const agentID = "01J9Z8Y7X6W5V4T3S2R1Q0P9N8"

func TestEnrollPersistsCredentials(t *testing.T) {
	ca := newTestCA(t)
	var gotReq Request
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/agent/v1/enroll" || r.Method != http.MethodPost {
			http.NotFound(w, r)
			return
		}
		if err := json.NewDecoder(r.Body).Decode(&gotReq); err != nil {
			t.Error(err)
		}
		if gotReq.Token != "tok-123" {
			http.Error(w, "bad token", 403)
			return
		}
		_ = json.NewEncoder(w).Encode(Response{
			AgentID: agentID, CertPEM: ca.sign(t, gotReq.CSRPEM, agentID, 90*24*time.Hour), CAPEM: string(ca.pem),
			Endpoints: Endpoints{API: "https://agents.example/agent/v1", OTLP: "https://otlp.example"},
		})
	}))
	defer srv.Close()

	dir := t.TempDir()
	p := Paths{Dir: dir}
	st, err := Enroll(context.Background(), Options{PanelURL: srv.URL, Token: "tok-123", Facts: map[string]any{"hostname": "web-1"}, Paths: p, Client: srv.Client()})
	if err != nil {
		t.Fatal(err)
	}
	if st.AgentID != agentID || st.Endpoints.API != "https://agents.example/agent/v1" {
		t.Fatalf("state %+v", st)
	}
	if gotReq.Facts.(map[string]any)["hostname"] != "web-1" {
		t.Fatalf("facts not sent: %+v", gotReq.Facts)
	}
	for _, f := range []string{p.Key(), p.Cert(), p.CA(), p.State()} {
		fi, err := os.Stat(f)
		if err != nil {
			t.Fatal(err)
		}
		if fi.Mode().Perm() != 0o600 {
			t.Errorf("%s mode %v, want 0600", f, fi.Mode().Perm())
		}
	}
	id, err := Load(p)
	if err != nil {
		t.Fatal(err)
	}
	if id.NeedsRenewal(time.Now()) {
		t.Error("fresh 90d cert should not need renewal")
	}
	if len(id.Fingerprint()) != 64 {
		t.Error("fingerprint")
	}
	// Idempotent: second enroll does not call the server (token is one-time).
	srv.Close()
	if _, err := Enroll(context.Background(), Options{PanelURL: srv.URL, Token: "tok-123", Paths: p}); err != nil {
		t.Fatalf("re-enroll should be a no-op: %v", err)
	}
}

func TestEnrollRejectsBadResponses(t *testing.T) {
	ca := newTestCA(t)
	other := newTestCA(t)
	cases := map[string]func(t *testing.T, csr string) (int, any){
		"forbidden": func(t *testing.T, csr string) (int, any) { return 403, map[string]string{"error": "used token"} },
		"wrong CA": func(t *testing.T, csr string) (int, any) {
			return 200, Response{AgentID: agentID, CertPEM: other.sign(t, csr, agentID, time.Hour), CAPEM: string(ca.pem), Endpoints: Endpoints{API: "https://x"}}
		},
		"foreign key": func(t *testing.T, _ string) (int, any) {
			_, csr, _ := NewKeyAndCSR("x")
			return 200, Response{AgentID: agentID, CertPEM: ca.sign(t, string(csr), agentID, time.Hour), CAPEM: string(ca.pem), Endpoints: Endpoints{API: "https://x"}}
		},
	}
	for name, fn := range cases {
		t.Run(name, func(t *testing.T) {
			srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				var req Request
				_ = json.NewDecoder(r.Body).Decode(&req)
				code, body := fn(t, req.CSRPEM)
				w.WriteHeader(code)
				_ = json.NewEncoder(w).Encode(body)
			}))
			defer srv.Close()
			p := Paths{Dir: t.TempDir()}
			if _, err := Enroll(context.Background(), Options{PanelURL: srv.URL, Token: "t", Paths: p, Client: srv.Client()}); err == nil {
				t.Fatal("expected error")
			}
			if p.Enrolled() {
				t.Fatal("must not persist credentials on failure")
			}
		})
	}
}

func TestRenewal(t *testing.T) {
	ca := newTestCA(t)
	p := Paths{Dir: t.TempDir()}
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req Request
		_ = json.NewDecoder(r.Body).Decode(&req)
		_ = json.NewEncoder(w).Encode(Response{AgentID: agentID, CertPEM: ca.sign(t, req.CSRPEM, agentID, 10*24*time.Hour), CAPEM: string(ca.pem), Endpoints: Endpoints{API: "https://x"}})
	}))
	defer srv.Close()
	if _, err := Enroll(context.Background(), Options{PanelURL: srv.URL, Token: "t", Paths: p, Client: srv.Client()}); err != nil {
		t.Fatal(err)
	}
	id, err := Load(p)
	if err != nil {
		t.Fatal(err)
	}
	if !id.NeedsRenewal(time.Now()) {
		t.Fatal("10-day cert must need renewal")
	}
	oldFP := id.Fingerprint()
	err = id.Renew(context.Background(), func(ctx context.Context, csr []byte) ([]byte, error) {
		return []byte(ca.sign(t, string(csr), agentID, 90*24*time.Hour)), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if id.Fingerprint() == oldFP || id.NeedsRenewal(time.Now()) {
		t.Fatal("certificate not swapped")
	}
	if c, _ := id.TLSConfig().GetClientCertificate(nil); c.Leaf.NotAfter != id.NotAfter() {
		t.Fatal("tls config does not serve the renewed cert")
	}
	// Reload from disk yields the new cert too.
	id2, err := Load(p)
	if err != nil || id2.Fingerprint() != id.Fingerprint() {
		t.Fatalf("persisted renewal mismatch: %v", err)
	}
	// A failed renewal (cert for a foreign key) keeps the current identity.
	fp := id.Fingerprint()
	err = id.Renew(context.Background(), func(ctx context.Context, _ []byte) ([]byte, error) {
		_, csr, _ := NewKeyAndCSR("x")
		return []byte(ca.sign(t, string(csr), agentID, 90*24*time.Hour)), nil
	})
	if err == nil || id.Fingerprint() != fp {
		t.Fatal("bad renewal must be rejected and leave identity untouched")
	}
	if _, err := Load(p); err != nil {
		t.Fatalf("on-disk creds broken after failed renewal: %v", err)
	}
}
