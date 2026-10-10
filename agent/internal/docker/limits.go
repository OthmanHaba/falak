package docker

import (
	"context"
	"fmt"
	"math"
	"net/http"
	"strconv"

	"github.com/OthmanHaba/falak/agent/internal/commands"
)

// Limits are a container's resource limits (docker.run, deploy.container.swap; docker.update changes the live ones).
// Zero values are "unset": no limit, Docker's default.
type Limits struct {
	MemoryBytes int64 `json:"memory_bytes,omitempty"`
	// MemoryReservationBytes is the soft limit the kernel reclaims a container down to under memory pressure.
	MemoryReservationBytes int64   `json:"memory_reservation_bytes,omitempty"`
	CPUs                   float64 `json:"cpus,omitempty"`
	PidsLimit              int64   `json:"pids_limit,omitempty"`
	// RestartPolicy defaults to unless-stopped; MaxRestarts only applies to on-failure.
	RestartPolicy string     `json:"restart_policy,omitempty"`
	MaxRestarts   int        `json:"max_restarts,omitempty"`
	Log           *LogLimits `json:"log,omitempty"`
	// OomScoreAdj is the container's OOM preference (-1000..1000; negative = killed last).
	OomScoreAdj int `json:"oom_score_adj,omitempty"`
}

// LogLimits cap a container's json-file log (Docker's default driver keeps everything).
type LogLimits struct {
	MaxSizeMB int `json:"max_size_mb"`
	MaxFiles  int `json:"max_files,omitempty"`
}

// LogConfig is HostConfig.LogConfig.
type LogConfig struct {
	Type   string            `json:"Type"`
	Config map[string]string `json:"Config,omitempty"`
}

var restartPolicies = map[string]bool{"no": true, "always": true, "unless-stopped": true, "on-failure": true}

// validate rejects limits Docker would refuse at create time (after the container it replaces was removed).
func (l Limits) validate() error {
	switch {
	case l.MemoryBytes < 0, l.MemoryReservationBytes < 0, l.CPUs < 0, l.PidsLimit < 0, l.MaxRestarts < 0:
		return fmt.Errorf("limits must not be negative")
	case l.MemoryBytes > 0 && l.MemoryBytes < 6<<20:
		return fmt.Errorf("memory limit below Docker's 6 MB minimum")
	case l.MemoryBytes > 0 && l.MemoryReservationBytes > l.MemoryBytes:
		return fmt.Errorf("memory reservation above the memory limit")
	case l.RestartPolicy != "" && !restartPolicies[l.RestartPolicy]:
		return fmt.Errorf("invalid restart policy %q", l.RestartPolicy)
	case l.MaxRestarts > 0 && l.RestartPolicy != "on-failure":
		return fmt.Errorf("max_restarts needs restart policy on-failure")
	case l.OomScoreAdj < -1000 || l.OomScoreAdj > 1000:
		return fmt.Errorf("oom_score_adj out of range")
	case l.Log != nil && (l.Log.MaxSizeMB < 1 || l.Log.MaxFiles < 0):
		return fmt.Errorf("invalid log limits")
	}
	return nil
}

// apply maps the limits onto a container's HostConfig. A memory limit also caps swap at the same value (no swap on
// top), so a service that outgrows its limit is OOM-killed instead of paging the host to a crawl.
func (l Limits) apply(h *HostConfig) {
	rp := l.RestartPolicy
	if rp == "" {
		rp = "unless-stopped"
	}
	h.RestartPolicy = RestartPolicy{Name: rp}
	if rp == "on-failure" {
		h.RestartPolicy.MaximumRetryCount = l.MaxRestarts
	}
	h.Memory = l.MemoryBytes
	if l.MemoryBytes > 0 {
		h.MemorySwap = l.MemoryBytes
	}
	h.MemoryReservation = l.MemoryReservationBytes
	h.NanoCPUs = nanoCPUs(l.CPUs)
	h.PidsLimit = l.PidsLimit
	h.OomScoreAdj = l.OomScoreAdj
	if l.Log != nil && l.Log.MaxSizeMB > 0 {
		files := max(1, l.Log.MaxFiles)
		h.LogConfig = &LogConfig{Type: "json-file", Config: map[string]string{
			"max-size": strconv.Itoa(l.Log.MaxSizeMB) + "m",
			"max-file": strconv.Itoa(files),
		}}
	}
}

func nanoCPUs(cpus float64) int64 { return int64(math.Round(cpus * 1e9)) }

// ---- docker.update ----

// UpdatePayload is docker.update: new limits for the running containers of a Docker site (both colors) or of one
// compose service, applied in place (no restart). Only what Docker changes live is here: log options and the OOM
// preference need a new container (a redeploy). A zero field leaves that limit as it is.
type UpdatePayload struct {
	Site                   string  `json:"site,omitempty"`
	Project                string  `json:"project,omitempty"`
	Service                string  `json:"service,omitempty"`
	MemoryBytes            int64   `json:"memory_bytes,omitempty"`
	MemoryReservationBytes int64   `json:"memory_reservation_bytes,omitempty"`
	CPUs                   float64 `json:"cpus,omitempty"`
	PidsLimit              int64   `json:"pids_limit,omitempty"`
	RestartPolicy          string  `json:"restart_policy,omitempty"`
	MaxRestarts            int     `json:"max_restarts,omitempty"`
}

// UpdateResult names the containers updated.
type UpdateResult struct {
	Changed    bool     `json:"changed"`
	Containers []string `json:"containers"`
}

// UpdateBody is POST /containers/{id}/update.
type UpdateBody struct {
	Memory            int64          `json:"Memory,omitempty"`
	MemorySwap        int64          `json:"MemorySwap,omitempty"`
	MemoryReservation int64          `json:"MemoryReservation,omitempty"`
	NanoCPUs          int64          `json:"NanoCpus,omitempty"`
	PidsLimit         int64          `json:"PidsLimit,omitempty"`
	RestartPolicy     *RestartPolicy `json:"RestartPolicy,omitempty"`
}

func (p UpdatePayload) body() UpdateBody {
	b := UpdateBody{Memory: p.MemoryBytes, MemoryReservation: p.MemoryReservationBytes, NanoCPUs: nanoCPUs(p.CPUs), PidsLimit: p.PidsLimit}
	if p.MemoryBytes > 0 {
		b.MemorySwap = p.MemoryBytes
	}
	if p.RestartPolicy != "" {
		b.RestartPolicy = &RestartPolicy{Name: p.RestartPolicy}
		if p.RestartPolicy == "on-failure" {
			b.RestartPolicy.MaximumRetryCount = p.MaxRestarts
		}
	}
	return b
}

// ContainerUpdateLimits changes a container's limits in place.
func (c *Client) ContainerUpdateLimits(ctx context.Context, id string, body UpdateBody) error {
	_, err := c.do(ctx, http.MethodPost, "/containers/"+id+"/update", nil, body, nil)
	return err
}

func (s *Service) update(ctx context.Context, p UpdatePayload, st commands.Stream) (any, error) {
	var labels []string
	switch {
	case p.Site != "" && p.Project == "" && p.Service == "":
		if !siteRe.MatchString(p.Site) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid site %q", p.Site)}
		}
		labels = []string{LabelSite + "=" + p.Site}
	case p.Site == "" && p.Project != "" && p.Service != "":
		if !projectRe.MatchString(p.Project) || !serviceNameRe.MatchString(p.Service) {
			return nil, &commands.PayloadError{Err: fmt.Errorf("invalid project or service")}
		}
		labels = []string{"com.docker.compose.project=" + p.Project, "com.docker.compose.service=" + p.Service}
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("either site, or project and service, are required")}
	}
	l := Limits{MemoryBytes: p.MemoryBytes, MemoryReservationBytes: p.MemoryReservationBytes, CPUs: p.CPUs, PidsLimit: p.PidsLimit,
		RestartPolicy: p.RestartPolicy, MaxRestarts: p.MaxRestarts}
	if err := l.validate(); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	list, err := s.c.ContainerList(ctx, true, labels)
	if err != nil {
		return nil, err
	}
	res := UpdateResult{Containers: []string{}}
	body := p.body()
	for _, c := range list {
		name := containerName(c)
		if err := s.c.ContainerUpdateLimits(ctx, c.ID, body); err != nil {
			return nil, fmt.Errorf("updating %s: %w", name, err)
		}
		fmt.Fprintf(st.Stdout(), "updated %s\n", name)
		res.Containers = append(res.Containers, name)
	}
	res.Changed = len(res.Containers) > 0
	return res, nil
}
