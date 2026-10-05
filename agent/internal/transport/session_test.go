package transport

import (
	"context"
	"net/http"
	"net/http/httptest"
	"regexp"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

func TestSessionHeaderOnPollAndHeartbeat(t *testing.T) {
	var mu sync.Mutex
	seen := map[string]string{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		seen[r.URL.Path] = r.Header.Get(SessionHeader)
		mu.Unlock()
		if r.Method == http.MethodGet {
			_, _ = w.Write([]byte(`{"commands":[]}`))
			return
		}
		w.WriteHeader(http.StatusNoContent)
	}))
	defer srv.Close()

	c := NewWithHTTPClient(srv.URL+"/agent/v1", srv.Client())
	c.Session = NewSessionID()
	if _, err := c.Poll(context.Background(), 0); err != nil {
		t.Fatal(err)
	}
	if err := c.Heartbeat(context.Background(), Heartbeat{}); err != nil {
		t.Fatal(err)
	}
	if err := c.PostEvents(context.Background(), "01J", nil); err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{"/agent/v1/commands", "/agent/v1/heartbeat", "/agent/v1/commands/01J/events"} {
		if seen[p] != c.Session {
			t.Errorf("%s: session header %q, want %q", p, seen[p], c.Session)
		}
	}
}

func TestNewSessionIDIsUniqueAndAccepted(t *testing.T) {
	// The control plane accepts 8-64 characters of [A-Za-z0-9._:-].
	valid := regexp.MustCompile(`^[A-Za-z0-9._:-]{8,64}$`)
	a, b := NewSessionID(), NewSessionID()
	if a == b {
		t.Fatalf("session ids must be unique per process: %s", a)
	}
	if !valid.MatchString(a) {
		t.Fatalf("session id %q is not accepted by the control plane", a)
	}
}

// stoppingClient returns envelopes only after the poller's context was cancelled, like a long-poll answered while
// the agent shuts down.
type stoppingClient struct {
	cancel context.CancelFunc
	polls  atomic.Int32
}

func (s *stoppingClient) Poll(ctx context.Context, wait int) ([]commands.Envelope, error) {
	s.polls.Add(1)
	s.cancel()
	return []commands.Envelope{{ID: "01J", Type: "edge.caddy.apply"}}, nil
}

func TestPollerDoesNotSubmitCommandsReceivedWhileStopping(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	client := &stoppingClient{cancel: cancel}
	var submitted atomic.Int32
	p := &Poller{Client: client, Submit: func(commands.Envelope) bool { submitted.Add(1); return true }}

	done := make(chan struct{})
	go func() { p.Run(ctx); close(done) }()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("poller did not stop")
	}
	if n := submitted.Load(); n != 0 {
		t.Fatalf("submitted %d command(s) received after shutdown started; they must be left for redelivery", n)
	}
	if n := client.polls.Load(); n != 1 {
		t.Fatalf("polled %d times after cancellation, want no new poll", n)
	}
}
