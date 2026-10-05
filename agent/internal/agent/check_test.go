package agent

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/enroll"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

func TestCheckReasonsFor401(t *testing.T) {
	const id, host = "01J9Z8Y7X6W5V4T3S2R1Q0P9AG", "agents.falak.test"
	reinstall := "run a new install command"
	cases := []struct {
		reason, body   string
		want           string
		sendsReinstall bool
	}{
		{"agent_revoked", "", "revoked: this agent was revoked or its server was removed from Falak (agent " + id + ")", true},
		{"unknown_certificate", "", "does not know this agent's certificate", true},
		{"certificate_revoked", "", "this agent's certificate was revoked", true},
		{"certificate_expired", "", "check this machine's clock", true},
		{"untrusted_peer", "", "does not trust the proxy in front of it", false},
		{"missing_certificate", "", "got no client certificate fingerprint from the edge", false},
		{"", `{"message":"client certificate required"}`, "did not receive this agent's client certificate", false},
		{"", `{"message":"Unknown, expired or revoked client certificate."}`, "rejected this agent's certificate", false},
	}
	for _, c := range cases {
		got, final := checkReason(&transport.StatusError{Code: 401, Reason: c.reason, Body: c.body}, id, host)
		if !final || !strings.Contains(got, c.want) || strings.Contains(got, reinstall) != c.sendsReinstall {
			t.Errorf("%s %s: %q", c.reason, c.body, got)
		}
	}
}

// Real handshakes over TCP: a peer's alert arrives as *net.OpError{Op: "remote error"}, and every client failure
// is wrapped in *url.Error (a net.Error), so TLS problems must be recognised before "cannot reach".
func TestCheckReasonsForTLSAndNetworkFailures(t *testing.T) {
	fleet := newFakeFleet(t)
	cfg := identityConfig(t, fleet)
	if err := EnrollOnly(context.Background(), cfg, quiet(), io.Discard, &runnertest.Fake{}); err != nil {
		t.Fatal(err)
	}
	id, err := enroll.Load(enroll.Paths{Dir: cfg.EtcDir})
	if err != nil {
		t.Fatal(err)
	}
	ping := func(url string, tc *tls.Config) error {
		_, err := transport.New(url, tc).Ping(context.Background())
		return err
	}
	other := newFakeFleet(t) // another Falak install: another CA

	// The agents host refuses the client certificate (issued by another CA) with a TLS alert.
	strict := httptest.NewUnstartedServer(http.NotFoundHandler())
	strict.TLS = &tls.Config{ClientAuth: tls.RequireAndVerifyClientCert, ClientCAs: pool(other.ca), Certificates: fleet.srv.TLS.Certificates}
	strict.StartTLS()
	defer strict.Close()
	err = ping(strict.URL, id.TLSConfig())
	var opErr *net.OpError
	if !errors.As(err, &opErr) || opErr.Op != "remote error" {
		t.Fatalf("expected a remote error alert, got %T %v", err, err)
	}

	// A TLS alert that has nothing to do with certificates: the agents host only speaks TLS 1.3.
	tls13 := httptest.NewUnstartedServer(http.NotFoundHandler())
	tls13.TLS = &tls.Config{MinVersion: tls.VersionTLS13, Certificates: fleet.srv.TLS.Certificates}
	tls13.StartTLS()
	defer tls13.Close()
	oldTLS := id.TLSConfig()
	oldTLS.MaxVersion = tls.VersionTLS12
	versionErr := ping(tls13.URL, oldTLS)
	if !errors.As(versionErr, &opErr) || opErr.Op != "remote error" || strings.Contains(versionErr.Error(), "certificate") {
		t.Fatalf("expected a non-certificate remote error alert, got %T %v", versionErr, versionErr)
	}

	unknownCA := id.TLSConfig()
	unknownCA.RootCAs = pool(other.ca)
	closed := httptest.NewServer(http.NotFoundHandler())
	closedURL := closed.URL
	closed.Close()

	cases := []struct {
		name, want string
		err        error
		final      bool
	}{
		{"client certificate refused", "refused this agent's client certificate", err, true},
		{"other TLS alert", "ended the handshake", versionErr, true},
		{"server certificate from another CA", "must serve a certificate issued by the Falak Fleet CA", ping(fleet.srv.URL, unknownCA), true},
		{"not TLS", "does not speak TLS", &url.Error{Op: "Get", URL: "https://x", Err: tls.RecordHeaderError{Msg: "first record does not look like a TLS handshake"}}, true},
		{"expired server certificate", "check this machine's clock", &url.Error{Op: "Get", URL: "https://x", Err: x509.CertificateInvalidError{Reason: x509.Expired}}, true},
		{"connection refused", "cannot reach the agents host", ping(closedURL, id.TLSConfig()), false},
		{"DNS", "cannot reach the agents host", ping("https://agents.invalid", id.TLSConfig()), false},
	}
	for _, c := range cases {
		got, final := checkReason(c.err, fleetAgentIDs[0], "agents.falak.test")
		if !strings.Contains(got, c.want) || final != c.final {
			t.Errorf("%s: final=%v %q (err %T %v)", c.name, final, got, c.err, c.err)
		}
	}
}
