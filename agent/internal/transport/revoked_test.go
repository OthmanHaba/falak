package transport

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
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
	if strings.Count(out, "this agent was revoked or its server was removed from Kiln (agent 01J9Z8Y7X6W5V4T3S2R1Q0P9N8)") != 1 || strings.Contains(out, "heartbeat failed") {
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

type revokedPoster struct {
	mu    sync.Mutex
	posts int
}

func (p *revokedPoster) PostEvents(ctx context.Context, _ string, _ []commands.Event) error {
	if err := ctx.Err(); err != nil {
		return err // like the real client: a cancelled request never reaches the server
	}
	p.mu.Lock()
	p.posts++
	p.mu.Unlock()
	return &StatusError{Code: 401, Body: `{"message":"revoked","error":"agent_revoked"}`, Reason: ReasonAgentRevoked}
}

func (p *revokedPoster) Poll(context.Context, int) ([]commands.Envelope, error) {
	_ = p.PostEvents(context.Background(), "", nil)
	return nil, &StatusError{Code: 401, Reason: ReasonAgentRevoked}
}

func (p *revokedPoster) count() int {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.posts
}

// Live AWS test on v0.5.2-rc.1: the outbox logged every retry with the full 401 body.
func TestLoopsBackOffQuietlyWhenRevoked(t *testing.T) {
	var logs bytes.Buffer
	log := slog.New(slog.NewTextHandler(&logs, nil))
	ctx, cancel := context.WithTimeout(context.Background(), 400*time.Millisecond)
	defer cancel()

	outboxPoster := &revokedPoster{}
	o := NewOutbox(outboxPoster, log)
	o.FlushInterval = 5 * time.Millisecond
	o.Emit(commands.Event{CommandID: "01J9Z8Y7X6W5V4T3S2R1Q0P9N8", Seq: 0, Kind: commands.KindStarted})
	pollPoster := &revokedPoster{}
	p := &Poller{Client: pollPoster, Submit: func(commands.Envelope) bool { return true }, Wait: 1, Log: log}

	var wg sync.WaitGroup
	wg.Add(2)
	go func() { defer wg.Done(); o.Run(ctx) }()
	go func() { defer wg.Done(); p.Run(ctx) }()
	wg.Wait()

	// One attempt each, then RevokedRetry (10 min) of quiet; the outbox's final flush on shutdown is a second post.
	if n := outboxPoster.count(); n > 2 {
		t.Fatalf("outbox posted %d times, want a long back-off", n)
	}
	if n := pollPoster.count(); n != 1 {
		t.Fatalf("poller polled %d times", n)
	}
	if strings.Contains(logs.String(), "posting events failed") || strings.Contains(logs.String(), "command poll failed") {
		t.Fatalf("per-attempt warnings for a revoked agent:\n%s", logs.String())
	}
	if o.Pending() != 1 {
		t.Fatal("events must stay queued (a revoked agent's events are not dropped by the agent)")
	}
}

func TestHeartbeaterPausesWhenRevoked(t *testing.T) {
	var beats int
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		beats++
		w.WriteHeader(401)
		w.Write([]byte(`{"error":"agent_revoked"}`))
	}))
	defer srv.Close()
	hb := &Heartbeater{Client: NewWithHTTPClient(srv.URL, srv.Client()), Interval: 5 * time.Millisecond}
	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	hb.Run(ctx)
	if beats != 1 {
		t.Fatalf("%d heartbeats while revoked, want 1", beats)
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
