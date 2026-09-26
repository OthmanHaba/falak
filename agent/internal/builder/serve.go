package builder

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Server polls the control plane for build jobs and runs them one at a time.
type Server struct {
	URL     string // control-plane base URL
	Token   string // builder token
	Name    string // builder name (reported to the control plane)
	Wait    int    // long-poll seconds
	HTTP    *http.Client
	Builder *Builder
	Log     *slog.Logger
	// Once stops after one job (tests, cron-style runs).
	Once bool
	// Idle is the pause after an empty poll when Wait is 0 (no long-poll). Default 5s.
	Idle time.Duration
}

// ErrNoJob is returned by Next when the queue is empty.
var ErrNoJob = errors.New("no build job queued")

// Next long-polls PathNextBuild.
func (s *Server) Next(ctx context.Context) (Job, error) {
	q := url.Values{"wait": {strconv.Itoa(s.Wait)}}
	if s.Name != "" {
		q.Set("builder", s.Name)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimRight(s.URL, "/")+PathNextBuild+"?"+q.Encode(), nil)
	if err != nil {
		return Job{}, err
	}
	req.Header.Set("Authorization", "Bearer "+s.Token)
	req.Header.Set("Accept", "application/json")
	resp, err := s.HTTP.Do(req)
	if err != nil {
		return Job{}, err
	}
	defer resp.Body.Close()
	switch resp.StatusCode {
	case http.StatusNoContent:
		return Job{}, ErrNoJob
	case http.StatusOK:
	default:
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return Job{}, fmt.Errorf("GET %s: HTTP %d: %s", PathNextBuild, resp.StatusCode, strings.TrimSpace(string(b)))
	}
	// Accept both a bare job and a {"data": job} envelope (Laravel resources).
	raw, err := io.ReadAll(resp.Body)
	if err != nil {
		return Job{}, err
	}
	var env struct {
		Data json.RawMessage `json:"data"`
	}
	if json.Unmarshal(raw, &env) == nil && len(env.Data) > 0 {
		raw = env.Data
	}
	var j Job
	if err := json.Unmarshal(raw, &j); err != nil {
		return Job{}, fmt.Errorf("decode job: %w", err)
	}
	return j, nil
}

// Run loops until ctx is cancelled.
func (s *Server) Run(ctx context.Context) error {
	if s.HTTP == nil {
		s.HTTP = &http.Client{Timeout: time.Duration(s.Wait+30) * time.Second}
	}
	if s.Log == nil {
		s.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	if s.Idle <= 0 {
		s.Idle = 5 * time.Second
	}
	backoff := time.Second
	for ctx.Err() == nil {
		j, err := s.Next(ctx)
		switch {
		case errors.Is(err, ErrNoJob):
			backoff = time.Second
			if s.Wait == 0 {
				sleep(ctx, s.Idle)
			}
			continue
		case err != nil:
			if ctx.Err() != nil {
				return nil
			}
			s.Log.Warn("poll builds", "err", err, "retry_in", backoff)
			sleep(ctx, backoff)
			backoff = min(backoff*2, time.Minute)
			continue
		}
		backoff = time.Second
		s.runJob(ctx, j)
		if s.Once {
			return nil
		}
	}
	return nil
}

func (s *Server) runJob(ctx context.Context, j Job) {
	id := j.ID
	if id == "" {
		id = "invalid"
	}
	sink := (&HTTPSink{URL: strings.TrimRight(s.URL, "/") + EventsPath(id), Token: s.Token, Client: s.HTTP, Log: s.Log}).Start()
	s.Log.Info("build started", "build_id", id, "mode", j.Mode)
	res, err := s.Builder.Build(ctx, j, sink)
	cctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	if ferr := sink.Close(cctx); ferr != nil {
		s.Log.Error("deliver build events", "build_id", id, "err", ferr)
	}
	if err != nil {
		s.Log.Error("build failed", "build_id", id, "err", err)
		return
	}
	s.Log.Info("build finished", "build_id", id, "duration_ms", res.DurationMS)
}

func sleep(ctx context.Context, d time.Duration) {
	select {
	case <-ctx.Done():
	case <-time.After(d):
	}
}
