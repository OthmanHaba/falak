package edge

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
)

// fakeCaddy implements /load, GET /config/, PATCH /id/<id>/<field>.
type fakeCaddy struct {
	mu    sync.Mutex
	cfg   any
	loads int
}

func (f *fakeCaddy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	body, _ := io.ReadAll(r.Body)
	switch {
	case r.Method == "POST" && r.URL.Path == "/load":
		var v any
		if err := json.Unmarshal(body, &v); err != nil {
			http.Error(w, err.Error(), 400)
			return
		}
		f.cfg = v
		f.loads++
	case r.Method == "GET" && r.URL.Path == "/config/":
		_ = json.NewEncoder(w).Encode(f.cfg)
	case r.Method == "PATCH" && strings.HasPrefix(r.URL.Path, "/id/"):
		parts := strings.SplitN(strings.TrimPrefix(r.URL.Path, "/id/"), "/", 2)
		node := findID(f.cfg, parts[0])
		if node == nil {
			http.Error(w, "unknown id", 404)
			return
		}
		var v any
		_ = json.Unmarshal(body, &v)
		node[parts[1]] = v
	default:
		http.NotFound(w, r)
	}
}

func findID(v any, id string) map[string]any {
	switch t := v.(type) {
	case map[string]any:
		if t["@id"] == id {
			return t
		}
		for _, c := range t {
			if r := findID(c, id); r != nil {
				return r
			}
		}
	case []any:
		for _, c := range t {
			if r := findID(c, id); r != nil {
				return r
			}
		}
	}
	return nil
}

func setup(t *testing.T) (*Manager, *fakeCaddy, hostfs.FS) {
	fc := &fakeCaddy{}
	srv := httptest.NewServer(fc)
	t.Cleanup(srv.Close)
	fs := hostfs.FS{Root: t.TempDir()}
	return New(Options{Client: &Client{Base: srv.URL}, FS: fs}), fc, fs
}

func stream() commands.Stream { return commands.NewTestStream("t", &commands.Collector{}) }

var payload = Payload{
	ACMEEmail: "ops@example.com",
	Sites: []Site{
		{ID: "shop", Domains: []string{"shop.example.com"}, RedirectDomains: []string{"www.shop.example.com"}, Kind: "frankenphp", Root: "/srv/kiln/sites/shop/current/public",
			Headers: map[string]string{"X-Frame-Options": "DENY"}, BasicAuth: []BasicAuth{{Username: "a", PasswordHash: "$2a$14$x"}}},
		{ID: "legacy", Domains: []string{"legacy.example.com"}, Kind: "php_fpm", Root: "/srv/kiln/sites/legacy/current/public", PHPFPMSocket: "/run/php/kiln-legacy-8.3.sock", TLS: &TLS{Mode: "custom", CertName: "legacy"}},
		{ID: "api", Domains: []string{"api.example.com"}, Kind: "reverse_proxy", Upstreams: []Upstream{{Dial: "127.0.0.1:3000"}}, HealthURI: "/health", DenyIPs: []string{"10.0.0.0/8"}},
		{ID: "intranet", Domains: []string{"intranet.lan"}, Kind: "static", Root: "/srv/static", TLS: &TLS{Mode: "off"}},
	},
}

func TestApplyIdempotentAndPersisted(t *testing.T) {
	m, fc, fs := setup(t)
	r1, err := m.Apply(context.Background(), payload, stream())
	if err != nil {
		t.Fatal(err)
	}
	if !r1.(ApplyResult).Changed || fc.loads != 1 || r1.(ApplyResult).Routes != 4 {
		t.Fatalf("first apply %+v loads=%d", r1, fc.loads)
	}
	r2, err := m.Apply(context.Background(), payload, stream())
	if err != nil {
		t.Fatal(err)
	}
	if r2.(ApplyResult).Changed || fc.loads != 1 {
		t.Fatalf("second apply must be a no-op: %+v loads=%d", r2, fc.loads)
	}
	if r1.(ApplyResult).ConfigSHA256 != r2.(ApplyResult).ConfigSHA256 {
		t.Fatal("hash unstable")
	}
	b, err := fs.ReadFile("/etc/kiln/caddy/bootstrap.json")
	if err != nil || !strings.Contains(string(b), `"listen": "localhost:2019"`) {
		t.Fatalf("bootstrap config not persisted: %v", err)
	}
	s := string(b)
	for _, want := range []string{`"handler": "php"`, `"resolve_root_symlink": true`, `"dial": "unix//run/php/kiln-legacy-8.3.sock"`,
		`"skip_certificates"`, `/etc/kiln/certs/legacy.crt`, `"kiln_http"`, `"@id": "kiln-upstreams-api"`, `"email": "ops@example.com"`,
		`https://shop.example.com{http.request.uri}`, `"remote_ip"`, `"X-Frame-Options"`, `"http_basic"`} {
		if !strings.Contains(s, want) {
			t.Errorf("rendered config missing %s", want)
		}
	}
	// Changing desired state triggers a new load.
	p2 := payload
	p2.Sites = payload.Sites[:1]
	if r, _ := m.Apply(context.Background(), p2, stream()); !r.(ApplyResult).Changed || fc.loads != 2 {
		t.Fatal("changed payload not loaded")
	}
}

func TestSetUpstreamsPatchesAndPersists(t *testing.T) {
	m, _, fs := setup(t)
	if _, err := m.Apply(context.Background(), payload, stream()); err != nil {
		t.Fatal(err)
	}
	if err := m.SetUpstreams(context.Background(), "api", []string{"127.0.0.1:3001"}); err != nil {
		t.Fatal(err)
	}
	b, _ := fs.ReadFile("/etc/kiln/caddy/bootstrap.json")
	if !strings.Contains(string(b), "127.0.0.1:3001") || strings.Contains(string(b), "127.0.0.1:3000") {
		t.Fatal("patched upstream not persisted")
	}
	if err := m.SetUpstreams(context.Background(), "nope", []string{"x:1"}); err == nil {
		t.Fatal("unknown route must fail")
	}
}

func TestRenderValidation(t *testing.T) {
	bad := []Payload{
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "php_fpm", Root: "/r"}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "static", Root: "/r"}, {ID: "b", Domains: []string{"x.com"}, Kind: "static", Root: "/r"}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "reverse_proxy"}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "static", Root: "/r", TLS: &TLS{Mode: "custom"}}}},
		{Sites: []Site{{ID: "a", Domains: []string{"x.com"}, Kind: "teleport"}}},
	}
	for i, p := range bad {
		if _, err := Render(p, "/c"); err == nil {
			t.Errorf("case %d: expected error", i)
		}
	}
	raw, err := Render(Payload{Raw: map[string]any{"admin": map[string]any{"listen": "0.0.0.0:2019"}, "apps": map[string]any{}}}, "/c")
	if err != nil || raw["admin"].(obj)["listen"] != AdminListen {
		t.Fatal("raw config must keep admin local")
	}
}

func selfSigned(t *testing.T, notAfter time.Time) (string, string) {
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "legacy.example.com"}, NotBefore: time.Now().Add(-48 * time.Hour), NotAfter: notAfter, DNSNames: []string{"legacy.example.com"}}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &k.PublicKey, k)
	kd, _ := x509.MarshalECPrivateKey(k)
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})), string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kd}))
}

func TestInstallCert(t *testing.T) {
	m, _, fs := setup(t)
	c, k := selfSigned(t, time.Now().Add(30*24*time.Hour))
	r, err := m.InstallCert(context.Background(), CertPayload{Name: "legacy", CertPEM: c, KeyPEM: k}, stream())
	if err != nil {
		t.Fatal(err)
	}
	res := r.(CertResult)
	if !res.Changed || len(res.FingerprintSHA256) != 64 {
		t.Fatalf("%+v", res)
	}
	st, _ := os.Stat(fs.P("/etc/kiln/certs/legacy.key"))
	if st.Mode().Perm() != 0o600 {
		t.Fatalf("key mode %v", st.Mode().Perm())
	}
	r2, _ := m.InstallCert(context.Background(), CertPayload{Name: "legacy", CertPEM: c, KeyPEM: k}, stream())
	if r2.(CertResult).Changed {
		t.Fatal("reinstall should be unchanged")
	}
	_, otherKey := selfSigned(t, time.Now().Add(time.Hour))
	if _, err := m.InstallCert(context.Background(), CertPayload{Name: "legacy", CertPEM: c, KeyPEM: otherKey}, stream()); err == nil {
		t.Fatal("mismatched key must be rejected")
	}
	exp, ek := selfSigned(t, time.Now().Add(-time.Hour))
	if _, err := m.InstallCert(context.Background(), CertPayload{Name: "old", CertPEM: exp, KeyPEM: ek}, stream()); err == nil {
		t.Fatal("expired cert must be rejected")
	}
	r3, _ := m.InstallCert(context.Background(), CertPayload{Name: "legacy", State: "absent"}, stream())
	if !r3.(CertResult).Changed || fs.Exists("/etc/kiln/certs/legacy.crt") {
		t.Fatal("absent did not remove")
	}
}
