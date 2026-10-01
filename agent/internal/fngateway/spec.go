// Package fngateway is the per-server function gateway (`kiln-agent fn-gateway`, kiln-fn-gateway.service).
//
// Caddy proxies every function's domains to the gateway (127.0.0.1:7070) and names the function in the
// X-Kiln-Function request header. The gateway owns the function's containers: it starts one when a request
// arrives and none is running (scale from zero), adds instances while every running one is at its concurrency
// (up to max_instances), stops instances idle for idle_timeout_s (down to min_instances, zero by default) and
// switches releases without dropping requests. Stopped instances keep their container, so waking one is a plain
// `docker start`.
//
// The agent registers releases over the admin API (HTTP on a unix socket). The gateway runs as its own systemd
// unit so agent upgrades never cut function traffic, and it adopts the running containers when it restarts.
package fngateway

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"time"

	"github.com/kiln/agent/internal/docker"
)

// Defaults of the wire contract (docs/plans/FUNCTIONS.md, "Phase 1 wire contract").
const (
	DefaultListen   = "127.0.0.1:7070"
	DefaultAdmin    = "/run/kiln-fn/gateway.sock"
	DefaultStateDir = "/var/lib/kiln/functions"
	// Header is the request header Caddy names the function in; it is removed before the request reaches the
	// function.
	Header = "X-Kiln-Function"
	// Network is the bridge network every function container is attached to.
	Network = "kiln-fn"
	// ContainerPort is the port the runtime listens on inside the container (PORT).
	ContainerPort = 8080
	// UID runs every function container (nobody).
	UID = "65534:65534"
	// ServeCommand / InstallCommand are the runtime image's entry points.
	ServeCommand   = "kiln-fn-serve"
	InstallCommand = "kiln-fn-install"
)

// Container labels.
const (
	LabelManaged = "kiln.managed"
	LabelSite    = "kiln.site"
	LabelService = "kiln.service"
	LabelRelease = "kiln.release"
	LabelSlot    = "kiln.fn.slot"
	LabelSpec    = "kiln.fn.spec"
	ServiceName  = "function"
)

var (
	siteRe    = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)
	releaseRe = regexp.MustCompile(`^[a-z0-9]{1,64}$`)
	envKeyRe  = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)
)

// Scaling of a function.
type Scaling struct {
	MinInstances int `json:"min_instances"`
	MaxInstances int `json:"max_instances"`
	Concurrency  int `json:"concurrency"`
	IdleTimeoutS int `json:"idle_timeout_s"`
}

// Limits of every instance.
type Limits struct {
	MemoryBytes     int64   `json:"memory_bytes,omitempty"`
	CPUs            float64 `json:"cpus,omitempty"`
	Pids            int64   `json:"pids,omitempty"`
	RequestTimeoutS int     `json:"request_timeout_s,omitempty"`
	StartTimeoutS   int     `json:"start_timeout_s,omitempty"`
}

// Spec is one function's release as registered with the gateway (PUT /v1/functions/{site}).
type Spec struct {
	Site       string            `json:"site"`
	Release    string            `json:"release"`
	Image      string            `json:"image"`
	Entrypoint string            `json:"entrypoint"`
	ReleaseDir string            `json:"release_dir"` // host path mounted read-only at /app
	Env        map[string]string `json:"env,omitempty"`
	Scaling    Scaling           `json:"scaling"`
	Limits     Limits            `json:"limits"`
	Labels     map[string]string `json:"labels,omitempty"`
	// Access is enforced by the gateway, not baked into containers (changing it keeps the instances).
	Access Access `json:"access,omitempty"`
}

// ApplyResult is the PUT response.
type ApplyResult struct {
	Release         string `json:"release"`
	PreviousRelease string `json:"previous_release,omitempty"`
	BootMS          int64  `json:"boot_ms"`
	Changed         bool   `json:"changed"`
}

// Status is one function's counters (GET /v1/functions).
type Status struct {
	Site          string     `json:"site"`
	Release       string     `json:"release"`
	Running       int        `json:"running"`
	Starting      int        `json:"starting"`
	InFlight      int        `json:"in_flight"`
	ColdStarts    uint64     `json:"cold_starts"`
	Requests      uint64     `json:"requests"`
	LastRequestAt *time.Time `json:"last_request_at,omitempty"`
}

// Normalize fills defaults and validates the spec.
func (s *Spec) Normalize() error {
	if !siteRe.MatchString(s.Site) {
		return fmt.Errorf("invalid site %q", s.Site)
	}
	if !releaseRe.MatchString(s.Release) {
		return fmt.Errorf("invalid release %q", s.Release)
	}
	if s.Image == "" || s.Entrypoint == "" || s.ReleaseDir == "" {
		return errors.New("image, entrypoint and release_dir are required")
	}
	for k := range s.Env {
		if !envKeyRe.MatchString(k) {
			return fmt.Errorf("invalid env name %q", k)
		}
	}
	if err := s.Access.Normalize(); err != nil {
		return err
	}
	sc := &s.Scaling
	if sc.MaxInstances <= 0 {
		sc.MaxInstances = 5
	}
	if sc.MinInstances < 0 {
		sc.MinInstances = 0
	}
	if sc.MinInstances > sc.MaxInstances {
		sc.MinInstances = sc.MaxInstances
	}
	if sc.Concurrency <= 0 {
		sc.Concurrency = 50
	}
	if sc.IdleTimeoutS <= 0 {
		sc.IdleTimeoutS = 300
	}
	l := &s.Limits
	if l.RequestTimeoutS <= 0 {
		l.RequestTimeoutS = 30
	}
	if l.StartTimeoutS <= 0 {
		l.StartTimeoutS = 30
	}
	if l.Pids <= 0 {
		l.Pids = 256
	}
	return nil
}

// containerHash covers everything baked into a container (not scaling), so a scaling change keeps the
// instances and anything else replaces them.
func (s Spec) containerHash() string {
	h := sha256.New()
	env := make([]string, 0, len(s.Env))
	for k, v := range s.Env {
		env = append(env, k+"="+v)
	}
	sort.Strings(env)
	// "otlp-1": containers mount the function's telemetry socket (containers made before are recreated).
	b, _ := json.Marshal([]any{"otlp-1", s.Release, s.Image, s.Entrypoint, s.ReleaseDir, env, s.Limits, s.Labels})
	h.Write(b)
	return hex.EncodeToString(h.Sum(nil))[:16]
}

func (s Spec) startTimeout() time.Duration {
	return time.Duration(s.Limits.StartTimeoutS) * time.Second
}
func (s Spec) requestTimeout() time.Duration {
	return time.Duration(s.Limits.RequestTimeoutS) * time.Second
}
func (s Spec) idleTimeout() time.Duration { return time.Duration(s.Scaling.IdleTimeoutS) * time.Second }

// ContainerName is kiln-fn-<site>-<release[:12]>-<slot>.
func ContainerName(site, release string, slot int) string {
	r := release
	if len(r) > 12 {
		r = r[:12]
	}
	return fmt.Sprintf("kiln-fn-%s-%s-%d", site, r, slot)
}

// Hardened is the HostConfig every function container gets (serve and install).
func Hardened(memory int64, cpus float64, pids int64, tmpfsSize string) docker.HostConfig {
	return docker.HostConfig{
		NetworkMode:    Network,
		RestartPolicy:  docker.RestartPolicy{Name: "no"},
		Memory:         memory,
		NanoCPUs:       int64(cpus * 1e9),
		ReadonlyRootfs: true,
		Tmpfs:          map[string]string{"/tmp": "rw,nosuid,nodev,size=" + tmpfsSize},
		CapDrop:        []string{"ALL"},
		SecurityOpt:    []string{"no-new-privileges"},
		PidsLimit:      pids,
	}
}

// createBody is the serve container of one instance slot. It publishes no host port: the gateway reaches the
// instance on its kiln-fn bridge address (<container ip>:8080), so readiness is a real connect to the runtime
// (a published loopback port would be accepted by docker-proxy before the runtime listens) and no port range
// has to be allocated.
func (s Spec) createBody(slot int) docker.CreateBody {
	env := []string{"PORT=" + strconv.Itoa(ContainerPort), "KILN_ENTRYPOINT=" + s.Entrypoint, "HOME=/tmp", OTLPSocketEnv + "=" + OTLPMount + "/" + otlpSocketName}
	keys := make([]string, 0, len(s.Env))
	for k := range s.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		if k == "PORT" || k == "KILN_ENTRYPOINT" || k == OTLPSocketEnv {
			continue
		}
		env = append(env, k+"="+s.Env[k])
	}
	labels := map[string]string{}
	for k, v := range s.Labels {
		labels[k] = v
	}
	for k, v := range map[string]string{
		LabelManaged: "true", LabelSite: s.Site, LabelService: ServiceName, LabelRelease: s.Release,
		LabelSlot: strconv.Itoa(slot), LabelSpec: s.containerHash(),
	} {
		labels[k] = v
	}
	cp := strconv.Itoa(ContainerPort) + "/tcp"
	hc := Hardened(s.Limits.MemoryBytes, s.Limits.CPUs, s.Limits.Pids, "64m")
	hc.Binds = []string{s.ReleaseDir + ":/app:ro", otlpDir(s.ReleaseDir) + ":" + OTLPMount + ":ro"}
	return docker.CreateBody{
		Image: s.Image, Env: env, Cmd: []string{ServeCommand}, User: UID, WorkingDir: "/app", Labels: labels,
		ExposedPorts: map[string]struct{}{cp: {}}, HostConfig: hc,
	}
}
