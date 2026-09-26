package edge

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net/http"
	"os"
	"os/user"
	"path"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
)

// Client is a minimal Caddy admin API client.
type Client struct {
	Base string // http://127.0.0.1:2019
	HTTP *http.Client
}

func (c *Client) hc() *http.Client {
	if c.HTTP != nil {
		return c.HTTP
	}
	return &http.Client{Timeout: 60 * time.Second}
}

func (c *Client) req(ctx context.Context, method, p string, body []byte, headers ...string) ([]byte, error) {
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	r, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(c.Base, "/")+p, rd)
	if err != nil {
		return nil, err
	}
	if body != nil {
		r.Header.Set("Content-Type", "application/json")
	}
	// Caddy's admin API enforces origin checks; a matching Origin keeps requests accepted.
	r.Header.Set("Origin", strings.TrimRight(c.Base, "/"))
	for i := 0; i+1 < len(headers); i += 2 {
		r.Header.Set(headers[i], headers[i+1])
	}
	resp, err := c.hc().Do(r)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	out, _ := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	if resp.StatusCode/100 != 2 {
		return nil, fmt.Errorf("caddy admin %s %s: HTTP %d: %s", method, p, resp.StatusCode, strings.TrimSpace(string(out)))
	}
	return out, nil
}

// Load replaces the whole config atomically (Caddy rolls back on failure).
func (c *Client) Load(ctx context.Context, cfg []byte) error {
	_, err := c.req(ctx, http.MethodPost, "/load", cfg)
	return err
}

// Config returns the running config (JSON, "null" when empty).
func (c *Client) Config(ctx context.Context) ([]byte, error) {
	return c.req(ctx, http.MethodGet, "/config/", nil)
}

// SetUpstreams replaces the upstreams of the reverse_proxy handler of an edge site (used by
// deploy.container.swap). The change is persisted by the Manager.
func (c *Client) SetUpstreams(ctx context.Context, routeID string, dials []string) error {
	ups := make([]map[string]string, 0, len(dials))
	for _, d := range dials {
		ups = append(ups, map[string]string{"dial": d})
	}
	b, _ := json.Marshal(ups)
	_, err := c.req(ctx, http.MethodPatch, "/id/"+UpstreamsID(routeID)+"/upstreams", b)
	return err
}

// ReloadFrankenPHP makes a freshly activated release live. FrankenPHP resolves a site's root symlink
// (current -> releases/<id>) when its php handler is provisioned, not per request, so the running
// config is force-reloaded (Caddy skips identical configs unless told to revalidate); that also
// restarts worker scripts gracefully.
func (c *Client) ReloadFrankenPHP(ctx context.Context) error {
	cfg, err := c.Config(ctx)
	if err != nil {
		return err
	}
	if t := bytes.TrimSpace(cfg); len(t) == 0 || bytes.Equal(t, []byte("null")) {
		_, err = c.req(ctx, http.MethodPost, "/frankenphp/workers/restart", nil)
		return err
	}
	_, err = c.req(ctx, http.MethodPost, "/load", cfg, "Cache-Control", "must-revalidate")
	return err
}

// Options for the Manager.
type Options struct {
	Client *Client
	FS     hostfs.FS
	EtcDir string // /etc/kiln (certs/ and caddy/bootstrap.json live here)
	Logger *slog.Logger
}

// Manager implements edge.* executors.
type Manager struct {
	o  Options
	mu sync.Mutex
}

// New creates a Manager.
func New(o Options) *Manager {
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	if o.EtcDir == "" {
		o.EtcDir = "/etc/kiln"
	}
	return &Manager{o: o}
}

func (m *Manager) certDir() string       { return path.Join(m.o.EtcDir, "certs") }
func (m *Manager) bootstrapPath() string { return path.Join(m.o.EtcDir, "caddy", "bootstrap.json") }

// Register adds edge.caddy.apply and edge.cert.install.
func (m *Manager) Register(reg *commands.Registry) {
	reg.Register("edge.caddy.apply", commands.Typed(m.Apply))
	reg.Register("edge.cert.install", commands.Typed(m.InstallCert))
}

// ApplyResult is edge.caddy.apply's result.
type ApplyResult struct {
	Changed      bool   `json:"changed"`
	ConfigSHA256 string `json:"config_sha256"`
	Routes       int    `json:"routes"`
}

// Apply renders and loads the full config; no-op when the running config is already identical.
func (m *Manager) Apply(ctx context.Context, p Payload, s commands.Stream) (any, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	cfg, err := Render(p, m.certDir())
	if err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	want, err := canonical(cfg)
	if err != nil {
		return nil, err
	}
	res := ApplyResult{ConfigSHA256: hostfs.SHA256(want), Routes: countRoutes(cfg)}
	if cur, err := m.o.Client.Config(ctx); err == nil {
		if c, err := canonicalBytes(cur); err == nil && bytes.Equal(c, want) {
			fmt.Fprintf(s.Stdout(), "caddy config unchanged (%s)\n", res.ConfigSHA256[:12])
			_, err := m.persist(want) // make sure the boot config matches even if it was lost
			return res, err
		}
	} else {
		return nil, fmt.Errorf("caddy admin API unreachable: %w", err)
	}
	if err := m.ensureRoots(p, s); err != nil {
		return nil, err
	}
	if err := m.o.Client.Load(ctx, want); err != nil {
		return nil, err
	}
	fmt.Fprintf(s.Stdout(), "loaded caddy config: %d site routes (%s)\n", res.Routes, res.ConfigSHA256[:12])
	res.Changed = true
	_, err = m.persist(want)
	return res, err
}

// PlaceholderRelease is the release `current` points at until a site's first deploy. It is not a ULID,
// so deploy's release listing, pruning and rollback never treat it as a release.
const PlaceholderRelease = ".kiln-placeholder"

const placeholderPage = `<?php
http_response_code(503);
header('Retry-After: 30');
echo "This site has not been deployed yet.\n";
`

// ensureRoots makes every PHP site's document root resolvable before loading: FrankenPHP (and the
// fastcgi transport with resolve_root_symlink) refuse a config whose root does not exist, which before
// a site's first deploy would take every route on the server down. `current` is pointed at a
// placeholder release that answers 503 until the first real release is activated.
func (m *Manager) ensureRoots(p Payload, s commands.Stream) error {
	for _, site := range p.Sites {
		if site.Root == "" || (site.Kind != "frankenphp" && site.Kind != "php_fpm" && site.Kind != "php-fpm") {
			continue
		}
		if _, err := os.Stat(m.o.FS.P(site.Root)); err == nil {
			continue
		}
		base, rest, ok := strings.Cut(site.Root, "/current")
		if !ok || (rest != "" && !strings.HasPrefix(rest, "/")) {
			if err := m.o.FS.MkdirAll(site.Root, 0o755); err != nil {
				return err
			}
			continue
		}
		current := base + "/current"
		if _, err := os.Lstat(m.o.FS.P(current)); err == nil {
			continue // a real (possibly broken) current link belongs to deploy; never replace it
		}
		release := base + "/releases/" + PlaceholderRelease
		if _, err := m.o.FS.WriteFile(release+rest+"/index.php", []byte(placeholderPage), 0o644); err != nil {
			return err
		}
		if err := os.Symlink(filepath.Join("releases", PlaceholderRelease), m.o.FS.P(current)); err != nil && !errors.Is(err, fs.ErrExist) {
			return err
		}
		fmt.Fprintf(s.Stdout(), "%s: not deployed yet, serving a placeholder\n", site.ID)
	}
	return nil
}

// PersistRunning snapshots the live config into bootstrap.json (after out-of-band PATCHes).
func (m *Manager) PersistRunning(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	cur, err := m.o.Client.Config(ctx)
	if err != nil {
		return err
	}
	c, err := canonicalBytes(cur)
	if err != nil {
		return err
	}
	_, err = m.persist(c)
	return err
}

// SetUpstreams patches upstreams live and persists the resulting config so a restart keeps them.
func (m *Manager) SetUpstreams(ctx context.Context, routeID string, dials []string) error {
	if err := m.o.Client.SetUpstreams(ctx, routeID, dials); err != nil {
		return err
	}
	return m.PersistRunning(ctx)
}

func (m *Manager) persist(cfg []byte) (bool, error) {
	var pretty bytes.Buffer
	_ = json.Indent(&pretty, cfg, "", "  ")
	pretty.WriteByte('\n')
	changed, err := m.o.FS.WriteFile(m.bootstrapPath(), pretty.Bytes(), 0o640)
	if err != nil {
		return changed, err
	}
	// kiln-edge.service runs as the caddy user and reads this file at boot.
	if g := m.edgeGroup(); g != "" {
		return changed, m.o.FS.Chown(m.bootstrapPath(), "root", g)
	}
	return changed, nil
}

// edgeGroup returns the group of the edge service user ("caddy") when it exists on a real host.
func (m *Manager) edgeGroup() string {
	if !m.o.FS.IsReal() {
		return ""
	}
	if _, err := user.LookupGroup("caddy"); err == nil {
		return "caddy"
	}
	return ""
}

// CertPayload is edge.cert.install.
type CertPayload struct {
	Name     string `json:"name"`
	CertPEM  string `json:"cert_pem,omitempty"`
	KeyPEM   string `json:"key_pem,omitempty"`
	ChainPEM string `json:"chain_pem,omitempty"`
	State    string `json:"state,omitempty"`
}

// CertResult is edge.cert.install's result.
type CertResult struct {
	Changed           bool   `json:"changed"`
	FingerprintSHA256 string `json:"fingerprint_sha256,omitempty"`
	NotAfter          string `json:"not_after,omitempty"`
	CertPath          string `json:"cert_path,omitempty"`
	KeyPath           string `json:"key_path,omitempty"`
}

// InstallCert validates the key pair and stores it where Render's load_files points.
func (m *Manager) InstallCert(ctx context.Context, p CertPayload, s commands.Stream) (any, error) {
	certPath := path.Join(m.certDir(), p.Name+".crt")
	keyPath := path.Join(m.certDir(), p.Name+".key")
	if p.State == "absent" {
		a, err := m.o.FS.Remove(certPath)
		if err != nil {
			return nil, err
		}
		b, err := m.o.FS.Remove(keyPath)
		return CertResult{Changed: a || b}, err
	}
	if p.CertPEM == "" || p.KeyPEM == "" {
		return nil, &commands.PayloadError{Err: errors.New("cert_pem and key_pem are required")}
	}
	full := strings.TrimSpace(p.CertPEM) + "\n"
	if p.ChainPEM != "" {
		full += strings.TrimSpace(p.ChainPEM) + "\n"
	}
	pair, err := tls.X509KeyPair([]byte(full), []byte(p.KeyPEM))
	if err != nil {
		return nil, &commands.PayloadError{Err: fmt.Errorf("certificate/key mismatch or invalid PEM: %w", err)}
	}
	leaf, err := x509.ParseCertificate(pair.Certificate[0])
	if err != nil {
		return nil, err
	}
	if time.Now().After(leaf.NotAfter) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("certificate expired at %s", leaf.NotAfter)}
	}
	if err := m.o.FS.MkdirAll(m.certDir(), 0o750); err != nil {
		return nil, err
	}
	group := m.edgeGroup()
	if group != "" {
		if err := m.o.FS.Chown(m.certDir(), "root", group); err != nil {
			return nil, err
		}
	}
	c1, err := m.o.FS.WriteFile(certPath, []byte(full), 0o644)
	if err != nil {
		return nil, err
	}
	keyMode := 0o600
	if group != "" {
		keyMode = 0o640 // readable by the edge (caddy group), never world-readable
	}
	c2, err := m.o.FS.WriteFile(keyPath, normalizeKey(p.KeyPEM), osMode(keyMode))
	if err != nil {
		return nil, err
	}
	if group != "" {
		if err := m.o.FS.Chown(keyPath, "root", group); err != nil {
			return nil, err
		}
	}
	fp := sha256.Sum256(leaf.Raw)
	res := CertResult{Changed: c1 || c2, FingerprintSHA256: hex.EncodeToString(fp[:]), NotAfter: leaf.NotAfter.UTC().Format(time.RFC3339), CertPath: certPath, KeyPath: keyPath}
	fmt.Fprintf(s.Stdout(), "certificate %s (CN=%s, expires %s) changed=%v\n", p.Name, leaf.Subject.CommonName, res.NotAfter, res.Changed)
	return res, nil
}

func normalizeKey(k string) []byte {
	if blk, _ := pem.Decode([]byte(k)); blk != nil {
		return pem.EncodeToMemory(blk)
	}
	return []byte(k)
}

func canonical(v any) ([]byte, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return nil, err
	}
	return canonicalBytes(b)
}

// canonicalBytes re-encodes JSON with sorted keys so configs can be compared byte-wise.
func canonicalBytes(b []byte) ([]byte, error) {
	var v any
	dec := json.NewDecoder(bytes.NewReader(b))
	dec.UseNumber()
	if err := dec.Decode(&v); err != nil {
		return nil, err
	}
	var out bytes.Buffer
	enc := json.NewEncoder(&out)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return nil, err
	}
	return bytes.TrimSpace(out.Bytes()), nil
}

func countRoutes(cfg obj) int {
	n := 0
	apps, _ := cfg["apps"].(obj)
	httpApp, _ := apps["http"].(obj)
	servers, _ := httpApp["servers"].(obj)
	for _, s := range servers {
		if so, ok := s.(obj); ok {
			if r, ok := so["routes"].([]any); ok {
				for _, x := range r {
					if xo, ok := x.(obj); ok && xo["@id"] != nil {
						n++
					}
				}
			}
		}
	}
	return n
}
