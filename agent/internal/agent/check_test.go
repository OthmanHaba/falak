package agent

import (
	"strings"
	"testing"

	"github.com/kiln/agent/internal/transport"
)

func TestCheckReasonsFor401(t *testing.T) {
	const id, host = "01J9Z8Y7X6W5V4T3S2R1Q0P9AG", "agents.kiln.test"
	reinstall := "run a new install command"
	cases := []struct {
		reason, body   string
		want           string
		sendsReinstall bool
	}{
		{"agent_revoked", "", "revoked: this agent was revoked or its server was removed from Kiln (agent " + id + ")", true},
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
