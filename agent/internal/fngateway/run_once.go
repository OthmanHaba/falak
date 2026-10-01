package fngateway

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/kiln/agent/internal/docker"
)

// Scheduled runs: a schedule (or "Run now") runs the function's live release once, in its own container
// (kiln-fn-run), outside the request instances. The runtime calls the function's scheduled() export and exits 0
// on success. Output streams back to the caller (the agent's cron job, or the fn.run command).

// RunCommand is the runtime image's entry point for scheduled runs.
const RunCommand = "kiln-fn-run"

// LabelRun marks scheduled-run containers (never adopted as instances; removed when the gateway restarts).
const LabelRun = "kiln.fn.run"

// RunRequest is one run of a schedule.
type RunRequest struct {
	// Schedule is the schedule's key; Name and Cron describe it to the function.
	Schedule string `json:"schedule"`
	Name     string `json:"name,omitempty"`
	Cron     string `json:"cron,omitempty"`
	// Trigger is "cron" (the server's scheduler) or "manual" (Run now).
	Trigger  string `json:"trigger,omitempty"`
	TimeoutS int    `json:"timeout_s,omitempty"`
}

var scheduleKey = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,40}$`)

func (r *RunRequest) normalize() error {
	if !scheduleKey.MatchString(r.Schedule) {
		return errors.New("invalid schedule")
	}
	if r.Trigger == "" {
		r.Trigger = "cron"
	}
	if r.Trigger != "cron" && r.Trigger != "manual" {
		return errors.New("trigger must be cron or manual")
	}
	if r.TimeoutS <= 0 {
		r.TimeoutS = 300
	}
	if r.TimeoutS > 86400 {
		r.TimeoutS = 86400
	}
	r.Name = truncate(strings.TrimSpace(strings.ReplaceAll(r.Name, "\n", " ")), 128)
	r.Cron = truncate(r.Cron, 64)
	return nil
}

// truncate cuts s to at most max bytes without splitting a UTF-8 character.
func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	for max > 0 && !utf8.RuneStart(s[max]) {
		max--
	}
	return s[:max]
}

// ExitTimeout is the exit code of a run stopped at its timeout (timeout(1)'s; the scheduler records "timeout").
const ExitTimeout = 124

// ErrRunTimeout is returned when a run exceeds its timeout (the container is stopped).
var ErrRunTimeout = errors.New("the run timed out")

// Run runs a schedule of the function's live release once and returns the runtime's exit code.
func (g *Gateway) Run(ctx context.Context, site string, r RunRequest, w io.Writer) (int, error) {
	if err := r.normalize(); err != nil {
		return -1, err
	}
	g.mu.Lock()
	f := g.fns[site]
	var spec Spec
	if f != nil {
		spec = f.spec
	}
	g.mu.Unlock()
	if f == nil {
		return -1, errUnknown
	}
	if g.tel != nil {
		g.tel.ensure(site, otlpDir(spec.ReleaseDir))
	}
	if err := g.o.Engine.EnsureNetwork(ctx); err != nil {
		return -1, err
	}
	rctx, cancel := context.WithTimeout(ctx, time.Duration(r.TimeoutS)*time.Second)
	defer cancel()
	name := "kiln-fn-run-" + site + "-" + strconv.FormatInt(time.Now().UnixNano(), 36)
	code, err := g.o.Engine.RunOnce(rctx, name, spec.runBody(r), w)
	if rctx.Err() == context.DeadlineExceeded && ctx.Err() == nil {
		return ExitTimeout, fmt.Errorf("%w after %ds", ErrRunTimeout, r.TimeoutS)
	}
	return code, err
}

// runBody is an instance's container (same image, release, variables, limits and isolation) running the
// schedule instead of serving.
func (s Spec) runBody(r RunRequest) docker.CreateBody {
	b := s.createBody(0)
	b.Cmd = []string{RunCommand}
	b.Env = append(b.Env, "KILN_TRIGGER="+r.Trigger, "KILN_SCHEDULE="+r.Schedule, "KILN_SCHEDULE_NAME="+r.Name, "KILN_SCHEDULE_CRON="+r.Cron)
	b.ExposedPorts = nil
	b.Labels[LabelSlot] = "run"
	b.Labels[LabelSpec] = "run"
	b.Labels[LabelRun] = r.Schedule
	return b
}

// runHandler is POST /v1/functions/{site}/run: the run's output streams as the body; the exit code follows in
// the X-Kiln-Exit-Code trailer (-1 when the run did not finish).
func (g *Gateway) runHandler(w http.ResponseWriter, req *http.Request) {
	var r RunRequest
	dec := json.NewDecoder(io.LimitReader(req.Body, 64<<10))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&r); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	site := req.PathValue("site")
	g.mu.Lock()
	known := g.fns[site] != nil
	g.mu.Unlock()
	if !known {
		http.Error(w, "unknown function", http.StatusNotFound)
		return
	}
	w.Header().Set("Trailer", "X-Kiln-Exit-Code, X-Kiln-Error")
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	code, err := g.Run(req.Context(), site, r, flushWriter{w})
	w.Header().Set("X-Kiln-Exit-Code", strconv.Itoa(code))
	if err != nil {
		w.Header().Set("X-Kiln-Error", strings.ReplaceAll(err.Error(), "\n", " "))
	}
}

type flushWriter struct{ w http.ResponseWriter }

func (f flushWriter) Write(p []byte) (int, error) {
	n, err := f.w.Write(p)
	if fl, ok := f.w.(http.Flusher); ok {
		fl.Flush()
	}
	return n, err
}

// Run runs a schedule through the gateway, copying its output to w; it returns the exit code.
func (c *Client) Run(ctx context.Context, site string, r RunRequest, w io.Writer) (int, error) {
	body, err := json.Marshal(r)
	if err != nil {
		return -1, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, "http://fn-gateway/v1/functions/"+site+"/run", strings.NewReader(string(body)))
	if err != nil {
		return -1, err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.hc.Do(req)
	if err != nil {
		return -1, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		return -1, fmt.Errorf("function gateway: %s: %s", resp.Status, strings.TrimSpace(string(b)))
	}
	if _, err := io.Copy(w, resp.Body); err != nil {
		return -1, err
	}
	code, convErr := strconv.Atoi(resp.Trailer.Get("X-Kiln-Exit-Code"))
	if msg := resp.Trailer.Get("X-Kiln-Error"); msg != "" {
		if convErr != nil {
			code = -1
		}
		if code == ExitTimeout {
			return code, fmt.Errorf("%w: %s", ErrRunTimeout, msg)
		}
		return code, errors.New(msg)
	}
	if convErr != nil {
		return -1, errors.New("the run ended without an exit code")
	}
	return code, nil
}
