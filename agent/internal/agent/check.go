package agent

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/enroll"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

// MaxClockSkew is how far this machine's clock may be off the control plane's: certificates are time-sensitive.
const MaxClockSkew = 5 * time.Minute

// CheckOptions for `falak-agent check`.
type CheckOptions struct {
	Config config.Config
	Wait   time.Duration // keep retrying transient failures this long (0: one attempt)
	Out    io.Writer
	Now    func() time.Time // tests
	Retry  time.Duration    // pause between attempts (default 2s)
}

// Check is `falak-agent check`: the identity is present and currently valid, and an authenticated request to the
// agent API (GET /ping) succeeds. Failures that cannot heal by waiting (revoked agent, clock) return at once; the
// rest are retried until Wait has passed. The error says why the agent cannot connect.
func Check(ctx context.Context, o CheckOptions) error {
	now := o.Now
	if now == nil {
		now = time.Now
	}
	retry := o.Retry
	if retry <= 0 {
		retry = 2 * time.Second
	}
	paths := enroll.Paths{Dir: o.Config.EtcDir}
	if !paths.Enrolled() {
		return fmt.Errorf("no agent identity in %s: run the install command from the panel", o.Config.EtcDir)
	}
	id, err := enroll.Load(paths)
	if err != nil {
		return fmt.Errorf("agent identity in %s is unreadable: %w", o.Config.EtcDir, err)
	}
	agentID := id.State.AgentID
	t := now()
	switch {
	case t.Before(id.NotBefore()):
		return fmt.Errorf("clock: the agent certificate is valid from %s but this machine's clock says %s; fix the time (timedatectl, NTP)",
			id.NotBefore().UTC().Format(time.RFC3339), t.UTC().Format(time.RFC3339))
	case t.After(id.NotAfter()):
		return fmt.Errorf("the agent certificate expired at %s (this machine's clock says %s); if the clock is right, run a new install command from the panel",
			id.NotAfter().UTC().Format(time.RFC3339), t.UTC().Format(time.RFC3339))
	}

	api := id.State.Endpoints.API
	host := api
	if u, err := url.Parse(api); err == nil && u.Host != "" {
		host = u.Host
	}
	client := transport.New(api, id.TLSConfig())
	client.UserAgent = "falak-agent/" + version.Version + " (check)"
	// No session header: this is not the running agent and must not look like a restart of it.

	deadline := now().Add(o.Wait)
	for {
		pong, err := client.Ping(ctx)
		if err == nil {
			if skew := pong.Time.Sub(now()); !pong.Time.IsZero() && (skew > MaxClockSkew || skew < -MaxClockSkew) {
				return fmt.Errorf("clock: this machine's clock is %s off the control plane's; fix the time (timedatectl, NTP)", skew.Abs().Round(time.Second))
			}
			if o.Out != nil {
				fmt.Fprintf(o.Out, "falak-agent connected as %s\n", pong.AgentID)
			}
			return nil
		}
		reason, final := checkReason(err, agentID, host)
		if final || ctx.Err() != nil || !now().Add(retry).Before(deadline) {
			return errors.New(reason)
		}
		select {
		case <-ctx.Done():
			return errors.New(reason)
		case <-time.After(retry):
		}
	}
}

// unauthorizedReason explains a 401 by its reason code (contracts/agent-protocol/README.md). Only the reasons
// about this agent's identity send the operator to a new install command; the others are control-plane setup.
func unauthorizedReason(se *transport.StatusError, agentID, host string) string {
	switch se.Reason {
	case "unknown_certificate":
		return fmt.Sprintf("the control plane does not know this agent's certificate (agent %s): this machine was enrolled with another Falak install, or the control plane's database was restored from an older backup; run a new install command from the panel", agentID)
	case "certificate_revoked":
		return fmt.Sprintf("this agent's certificate was revoked (agent %s); run a new install command from the panel", agentID)
	case "certificate_expired":
		return fmt.Sprintf("the control plane considers this agent's certificate expired (agent %s); check this machine's clock (timedatectl), otherwise run a new install command from the panel", agentID)
	case "untrusted_peer":
		return fmt.Sprintf("the control plane does not trust the proxy in front of it (HTTP 401 untrusted_peer from %s): the edge's address must be in the control plane's trusted proxies (FALAK_EDGE_SUBNET); run falak-ctl doctor on the control plane", host)
	case "missing_certificate":
		return fmt.Sprintf("the control plane got no client certificate fingerprint from the edge (HTTP 401 missing_certificate from %s): a proxy in between terminates TLS, or the edge does not forward the fingerprint; run falak-ctl doctor on the control plane", host)
	}
	if strings.Contains(se.Body, "client certificate required") {
		return fmt.Sprintf("the agents host %s did not receive this agent's client certificate: a proxy between this machine and it terminates TLS, or it is not the Falak edge", host)
	}
	return fmt.Sprintf("the agents host %s rejected this agent's certificate (agent %s, HTTP 401: %s)", host, agentID, se.Body)
}

// checkReason turns a ping error into an operator-facing reason; final reasons do not heal by waiting.
func checkReason(err error, agentID, host string) (string, bool) {
	var se *transport.StatusError
	if errors.As(err, &se) {
		switch {
		case transport.IsRevoked(err):
			return "revoked: " + transport.RevokedMessage(agentID), true
		case se.Code == 401:
			return unauthorizedReason(se, agentID, host), true
		case se.Code == 404:
			return fmt.Sprintf("the control plane at %s has no /agent/v1/ping endpoint (older than this agent); check the agent with journalctl -u falak-agent", host), true
		default:
			return fmt.Sprintf("the agents host %s answered HTTP %d: %s", host, se.Code, se.Body), false
		}
	}
	// TLS and identity problems first: they do not heal by waiting, and every http.Client failure is a *url.Error,
	// which is itself a net.Error.
	if reason, ok := tlsReason(err, host); ok {
		return reason, true
	}
	var dnsErr *net.DNSError
	var opErr *net.OpError
	var netErr net.Error
	if errors.As(err, &dnsErr) || errors.As(err, &opErr) || errors.As(err, &netErr) {
		return fmt.Sprintf("cannot reach the agents host %s: %v (firewall, DNS or the control plane is down)", host, err), false
	}
	return fmt.Sprintf("request to the agents host %s failed: %v", host, err), false
}

// tlsReason recognises a failed TLS handshake with the agents host.
func tlsReason(err error, host string) (string, bool) {
	var ue *url.Error
	if errors.As(err, &ue) {
		err = ue.Err
	}
	var (
		opErr     *net.OpError
		alert     tls.AlertError // QUIC only; over TCP a peer's alert is an *net.OpError{Op: "remote error"}
		unknownCA x509.UnknownAuthorityError
		hostErr   x509.HostnameError
		certErr   x509.CertificateInvalidError
		recErr    tls.RecordHeaderError
		certVErr  *tls.CertificateVerificationError
	)
	switch {
	case (errors.As(err, &opErr) && opErr.Op == "remote error" || errors.As(err, &alert)) && strings.Contains(err.Error(), "certificate"):
		return fmt.Sprintf("TLS error: the agents host %s refused this agent's client certificate (%v); if the control plane's Fleet CA was replaced, run a new install command from the panel", host, err), true
	case errors.As(err, &opErr) && opErr.Op == "remote error", errors.As(err, &alert):
		// Any other alert (protocol version, handshake failure, …) comes from the TLS setup in front of the agent API.
		return fmt.Sprintf("TLS error: the agents host %s ended the handshake (%v); check the edge or proxy in front of it (falak-ctl doctor on the control plane)", host, err), true
	case errors.As(err, &certErr) && certErr.Reason == x509.Expired:
		return fmt.Sprintf("TLS error from %s: %v; check this machine's clock (timedatectl) and the agents host certificate", host, err), true
	case errors.As(err, &recErr):
		return fmt.Sprintf("TLS error from %s: %v; the address does not speak TLS (wrong port, or a plain HTTP proxy)", host, err), true
	case errors.As(err, &unknownCA), errors.As(err, &hostErr), errors.As(err, &certErr), errors.As(err, &certVErr):
		return fmt.Sprintf("TLS error from %s: %v; the agents host must serve a certificate issued by the Falak Fleet CA for this name (falak-ctl doctor on the control plane)", host, err), true
	}
	return "", false
}
