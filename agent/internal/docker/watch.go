package docker

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/resources"
)

// EngineEvent is one GET /events message.
type EngineEvent struct {
	Type   string `json:"Type"`
	Action string `json:"Action"`
	Actor  struct {
		ID         string            `json:"ID"`
		Attributes map[string]string `json:"Attributes"`
	} `json:"Actor"`
	Time int64 `json:"time"`
}

// Events streams engine events matching filters to fn until ctx ends or the stream breaks.
func (c *Client) Events(ctx context.Context, filters map[string][]string, fn func(EngineEvent)) error {
	q := url.Values{}
	if len(filters) > 0 {
		f, _ := json.Marshal(filters)
		q.Set("filters", string(f))
	}
	resp, err := c.raw(ctx, http.MethodGet, "/v"+c.apiVersion(ctx)+"/events", q, nil, nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	dec := json.NewDecoder(resp.Body)
	for {
		var e EngineEvent
		if err := dec.Decode(&e); err != nil {
			if errors.Is(err, io.EOF) {
				return io.ErrUnexpectedEOF
			}
			return err
		}
		fn(e)
	}
}

// Labels a container event is mapped to its service by.
const (
	labelComposeProject = "com.docker.compose.project"
	labelComposeService = "com.docker.compose.service"
	labelDBInstance     = "falak.db.instance"
)

// serviceEvent describes a container by the labels the control plane maps it with; ok=false for containers Falak
// doesn't manage.
func serviceEvent(kind, name string, labels map[string]string, count int) (resources.Event, bool) {
	e := resources.Event{Kind: kind, Source: resources.SourceContainer, Name: strings.TrimPrefix(name, "/"), Count: count,
		Site: labels[LabelSite], Project: labels[labelComposeProject], Service: labels[labelComposeService], Instance: labels[labelDBInstance]}
	return e, e.Site != "" || (e.Project != "" && e.Service != "") || e.Instance != ""
}

// RestartPollEvery is how often restart counts of managed containers are read.
var RestartPollEvery = time.Minute

// Watch reports OOM kills (the engine's `oom` events: a process in the container's cgroup was killed by the OOM
// killer, its own limit or the host's) and restarts by the restart policy (RestartCount increases; the engine has no
// event for those) of the containers Falak manages, until ctx ends.
func (s *Service) Watch(ctx context.Context, add func(resources.Event)) {
	go s.pollRestarts(ctx, add)
	backoff := time.Second
	for ctx.Err() == nil {
		started := time.Now()
		err := s.c.Events(ctx, map[string][]string{"type": {"container"}, "event": {"oom"}}, func(e EngineEvent) {
			if ev, ok := serviceEvent(resources.KindOOMKill, e.Actor.Attributes["name"], e.Actor.Attributes, 1); ok {
				if e.Time > 0 {
					ev.At = time.Unix(e.Time, 0).UTC()
				}
				add(ev)
			}
		})
		if ctx.Err() != nil {
			return
		}
		if time.Since(started) > time.Minute {
			backoff = time.Second
		}
		s.log.Debug("docker events stream ended", "err", err, "retry_in", backoff)
		select {
		case <-ctx.Done():
			return
		case <-time.After(backoff):
		}
		backoff = min(backoff*2, time.Minute)
	}
}

func (s *Service) pollRestarts(ctx context.Context, add func(resources.Event)) {
	var counts resources.Counter
	t := time.NewTicker(RestartPollEvery)
	defer t.Stop()
	for {
		s.restartsOnce(ctx, &counts, add)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// restartsOnce reads the restart count of every container Falak manages (sites, compose services, databases).
func (s *Service) restartsOnce(ctx context.Context, counts *resources.Counter, add func(resources.Event)) {
	list, err := s.c.ContainerList(ctx, true, nil)
	if err != nil {
		return
	}
	keep := map[string]bool{}
	for _, c := range list {
		if _, ok := serviceEvent(resources.KindRestart, "", c.Labels, 1); !ok {
			continue
		}
		cur, ok, err := s.c.ContainerInspect(ctx, c.ID)
		if err != nil || !ok {
			continue
		}
		keep[c.ID] = true
		if d := counts.Delta(c.ID, cur.RestartCount); d > 0 {
			if ev, ok := serviceEvent(resources.KindRestart, containerName(c), c.Labels, d); ok {
				add(ev)
			}
		}
	}
	counts.Forget(keep)
}
