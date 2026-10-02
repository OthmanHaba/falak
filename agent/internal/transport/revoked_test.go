package transport

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestRevokedAgentIsReportedOnceAndRecognised(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(401)
		w.Write([]byte(`{"message":"This agent was revoked.","error":"agent_revoked"}`))
	}))
	defer srv.Close()
	var logs bytes.Buffer
	c := NewWithHTTPClient(srv.URL, srv.Client())
	c.AgentID, c.Log = "01J9Z8Y7X6W5V4T3S2R1Q0P9N8", slog.New(slog.NewTextHandler(&logs, nil))

	hb := &Heartbeater{Client: c, Log: c.Log}
	for i := 0; i < 3; i++ {
		hb.Beat(context.Background())
	}
	err := c.Heartbeat(context.Background(), map[string]any{})
	if !IsRevoked(err) || !IsRevoked(errors.Join(errors.New("renew"), err)) {
		t.Fatalf("not recognised as revoked: %v", err)
	}
	out := logs.String()
	if strings.Count(out, "this server was removed from Kiln (agent 01J9Z8Y7X6W5V4T3S2R1Q0P9N8)") != 1 || strings.Contains(out, "heartbeat failed") {
		t.Fatalf("want exactly one revoked message and no heartbeat noise:\n%s", out)
	}
	// Rate limit: reported again once RevokedReportEvery has passed.
	c.revokedMu.Lock()
	c.revokedLast = time.Now().Add(-RevokedReportEvery)
	c.revokedMu.Unlock()
	hb.Beat(context.Background())
	if strings.Count(logs.String(), "removed from Kiln") != 2 {
		t.Fatalf("want the message again after the interval:\n%s", logs.String())
	}
}

func TestPlain401IsNotRevoked(t *testing.T) {
	for _, body := range []string{`{"message":"Unknown, expired or revoked client certificate."}`, `{"message":"x","error":"unknown_certificate"}`, `not json`} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(401)
			w.Write([]byte(body))
		}))
		c := NewWithHTTPClient(srv.URL, srv.Client())
		err := c.Heartbeat(context.Background(), map[string]any{})
		srv.Close()
		var se *StatusError
		if !errors.As(err, &se) || se.Code != 401 || IsRevoked(err) || !Retryable(err) {
			t.Fatalf("%s: %v", body, err)
		}
	}
}

func TestPing(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.URL.Path != "/agent/v1/ping" {
			http.NotFound(w, r)
			return
		}
		w.Write([]byte(`{"agent_id":"01J9Z8Y7X6W5V4T3S2R1Q0P9N8","time":"2026-10-02T12:00:00+00:00"}`))
	}))
	defer srv.Close()
	p, err := NewWithHTTPClient(srv.URL+"/agent/v1", srv.Client()).Ping(context.Background())
	if err != nil || p.AgentID != "01J9Z8Y7X6W5V4T3S2R1Q0P9N8" || !p.Time.Equal(time.Date(2026, 10, 2, 12, 0, 0, 0, time.UTC)) {
		t.Fatalf("%+v %v", p, err)
	}
	if _, err := NewWithHTTPClient(srv.URL+"/nope", srv.Client()).Ping(context.Background()); err == nil {
		t.Fatal("404 must fail")
	}
}
