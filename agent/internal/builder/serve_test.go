package builder

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner/runnertest"
)

func TestServePollsAndPostsEvents(t *testing.T) {
	var mu sync.Mutex
	var events bytes.Buffer
	polls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer bt_123" {
			http.Error(w, "unauthorized", 401)
			return
		}
		mu.Lock()
		defer mu.Unlock()
		switch r.URL.Path {
		case PathNextBuild:
			polls++
			if r.URL.Query().Get("builder") != "b1" || r.URL.Query().Get("wait") != "0" {
				http.Error(w, "bad query", 400)
				return
			}
			if polls == 1 {
				w.WriteHeader(http.StatusNoContent)
				return
			}
			json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"id": "b-srv", "mode": "native", "repo": map[string]any{"url": "https://x/y.git"}}})
		case EventsPath("b-srv"):
			if r.Header.Get("Content-Type") != "application/x-ndjson" {
				http.Error(w, "ct", 415)
				return
			}
			b, _ := io.ReadAll(r.Body)
			events.Write(b)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	f := &runnertest.Fake{}
	fakeGit(t, f, filepath.Join(fixtures, "static"))
	s := &Server{URL: srv.URL, Token: "bt_123", Name: "b1", Once: true, Idle: 10 * time.Millisecond, Builder: newBuilder(t, f), HTTP: srv.Client()}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := s.Run(ctx); err != nil {
		t.Fatal(err)
	}
	mu.Lock()
	defer mu.Unlock()
	evs := validateNDJSON(t, events.Bytes())
	if *evs[len(evs)-1].ExitCode != 0 || polls != 2 {
		t.Fatalf("polls=%d last=%+v", polls, evs[len(evs)-1])
	}
}

func TestHTTPSinkGoneCancelsBuild(t *testing.T) {
	var posts int
	var mu sync.Mutex
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		posts++
		mu.Unlock()
		w.WriteHeader(http.StatusGone)
	}))
	defer srv.Close()
	cancelled := make(chan struct{})
	var calls int
	sink := (&HTTPSink{URL: srv.URL, Token: "t", Client: srv.Client(), Interval: 10 * time.Millisecond, OnGone: func() {
		calls++
		close(cancelled)
	}}).Start()
	sink.Emit(commandsEvent("b1", 0))
	select {
	case <-cancelled:
	case <-time.After(5 * time.Second):
		t.Fatal("OnGone was not called")
	}
	sink.Emit(commandsEvent("b1", 1))
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := sink.Close(ctx); err != nil {
		t.Fatalf("close after 410 must not retry forever: %v", err)
	}
	if calls != 1 {
		t.Fatalf("OnGone calls = %d", calls)
	}
}

func commandsEvent(id string, seq int64) commands.Event {
	return commands.Event{CommandID: id, Seq: seq, Kind: commands.KindOutput, At: time.Now(), Stream: "stdout", Data: "x\n"}
}
