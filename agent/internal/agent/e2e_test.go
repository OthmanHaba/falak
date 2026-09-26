package agent

import (
	"bufio"
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
	"io"
	"log/slog"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/config"
)

// fakeFleet is a minimal Fleet module: CA, enrollment, mTLS fingerprint auth, command queue, events.
type fakeFleet struct {
	t      *testing.T
	caKey  *ecdsa.PrivateKey
	ca     *x509.Certificate
	mu     sync.Mutex
	fps    map[string]bool
	queue  []commands.Envelope
	events map[string][]commands.Event
	beats  int
	srv    *httptest.Server
}

func newFakeFleet(t *testing.T) *fakeFleet {
	f := &fakeFleet{t: t, fps: map[string]bool{}, events: map[string][]commands.Event{}}
	f.caKey, _ = ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Kiln CA"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(24 * time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &f.caKey.PublicKey, f.caKey)
	f.ca, _ = x509.ParseCertificate(der)
	sk, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	sder, _ := x509.CreateCertificate(rand.Reader, &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "agents"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}, f.ca, &sk.PublicKey, f.caKey)
	f.srv = httptest.NewUnstartedServer(f)
	f.srv.TLS = &tls.Config{ClientAuth: tls.VerifyClientCertIfGiven, ClientCAs: pool(f.ca), Certificates: []tls.Certificate{{Certificate: [][]byte{sder}, PrivateKey: sk}}}
	f.srv.StartTLS()
	t.Cleanup(f.srv.Close)
	return f
}

func pool(c *x509.Certificate) *x509.CertPool { p := x509.NewCertPool(); p.AddCert(c); return p }

func (f *fakeFleet) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/agent/v1/enroll" {
		var req struct {
			Token  string         `json:"token"`
			CSRPEM string         `json:"csr_pem"`
			Facts  map[string]any `json:"facts"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if req.Token != "one-time" || req.Facts["agent_version"] == nil {
			http.Error(w, "bad enrollment", 403)
			return
		}
		blk, _ := pem.Decode([]byte(req.CSRPEM))
		csr, err := x509.ParseCertificateRequest(blk.Bytes)
		if err != nil || csr.CheckSignature() != nil {
			http.Error(w, "bad csr", 400)
			return
		}
		der, _ := x509.CreateCertificate(rand.Reader, &x509.Certificate{SerialNumber: big.NewInt(3), Subject: pkix.Name{CommonName: "01J9Z8Y7X6W5V4T3S2R1Q0P9AG"}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(90 * 24 * time.Hour), ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}, f.ca, csr.PublicKey, f.caKey)
		s := sha256.Sum256(der)
		f.mu.Lock()
		f.fps[hex.EncodeToString(s[:])] = true
		f.mu.Unlock()
		_ = json.NewEncoder(w).Encode(map[string]any{
			"agent_id":  "01J9Z8Y7X6W5V4T3S2R1Q0P9AG",
			"cert_pem":  string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})),
			"ca_pem":    string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: f.ca.Raw})),
			"endpoints": map[string]string{"api": f.srv.URL + "/agent/v1", "otlp": "https://127.0.0.1:1"},
		})
		return
	}
	// Everything else requires an enrolled client certificate (fingerprint match, like the edge).
	if r.TLS == nil || len(r.TLS.PeerCertificates) == 0 {
		http.Error(w, "client cert required", 401)
		return
	}
	s := sha256.Sum256(r.TLS.PeerCertificates[0].Raw)
	f.mu.Lock()
	ok := f.fps[hex.EncodeToString(s[:])]
	f.mu.Unlock()
	if !ok {
		http.Error(w, "unknown agent", 401)
		return
	}
	switch {
	case r.URL.Path == "/agent/v1/commands":
		f.mu.Lock()
		q := f.queue
		f.queue = nil
		f.mu.Unlock()
		if len(q) == 0 {
			select {
			case <-time.After(200 * time.Millisecond):
			case <-r.Context().Done():
			}
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"commands": q})
	case strings.HasSuffix(r.URL.Path, "/events"):
		id := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/agent/v1/commands/"), "/events")
		sc := bufio.NewScanner(r.Body)
		f.mu.Lock()
		for sc.Scan() {
			var ev commands.Event
			_ = json.Unmarshal(sc.Bytes(), &ev)
			f.events[id] = append(f.events[id], ev)
		}
		f.mu.Unlock()
		w.WriteHeader(204)
	case r.URL.Path == "/agent/v1/heartbeat":
		io.Copy(io.Discard, r.Body)
		f.mu.Lock()
		f.beats++
		f.mu.Unlock()
		w.WriteHeader(204)
	case r.URL.Path == "/agent/v1/insights":
		w.WriteHeader(204)
	default:
		http.NotFound(w, r)
	}
}

func TestEndToEndRun(t *testing.T) {
	fleet := newFakeFleet(t)
	root := t.TempDir()
	cfg := config.Default()
	cfg.PanelURL, cfg.Token, cfg.Insecure = fleet.srv.URL, "one-time", true
	cfg.HostRoot = root
	cfg.EtcDir = root + "/etc/kiln"
	cfg.OTLPSocket = ""
	cfg.OTLPHTTP = "127.0.0.1:0"
	cfg.DockerSock = root + "/nope.sock"
	cfg.PollWait = 1
	cfg.Heartbeat = 200 * time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- Run(ctx, cfg, slog.New(slog.NewTextHandler(io.Discard, nil))) }()

	const id = "01J9Z8Y7X6W5V4T3S2R1Q0P9E2"
	fleet.mu.Lock()
	fleet.queue = append(fleet.queue, commands.Envelope{ID: id, Type: "system.exec", TimeoutS: 30, IdempotencyKey: "e2e",
		Payload: json.RawMessage(`{"script":"echo hello from kiln","shell":"/bin/sh"}`)})
	fleet.mu.Unlock()

	deadline := time.Now().Add(15 * time.Second)
	for {
		fleet.mu.Lock()
		evs := append([]commands.Event(nil), fleet.events[id]...)
		beats := fleet.beats
		fleet.mu.Unlock()
		var out string
		var fin *commands.Event
		for i := range evs {
			if evs[i].Kind == commands.KindOutput {
				out += evs[i].Data
			}
			if evs[i].Kind == commands.KindFinished {
				fin = &evs[i]
			}
		}
		if fin != nil && beats > 0 {
			if *fin.ExitCode != 0 || !strings.Contains(out, "hello from kiln") {
				t.Fatalf("finished=%+v output=%q", fin, out)
			}
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("timeout: events=%+v beats=%d", evs, beats)
		}
		time.Sleep(50 * time.Millisecond)
	}
	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(20 * time.Second):
		t.Fatal("Run did not shut down")
	}
}
