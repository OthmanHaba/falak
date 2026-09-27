package docker

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"regexp"
	"sort"
	"strconv"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
)

// UpstreamSetter repoints a Caddy route's reverse_proxy upstreams (implemented by internal/edge).
type UpstreamSetter interface {
	SetUpstreams(ctx context.Context, routeID string, dials []string) error
}

// Options configures the docker executors.
type Options struct {
	Socket    string // default /var/run/docker.sock
	Runner    runner.Runner
	FS        hostfs.FS
	Upstreams UpstreamSetter
	HTTP      *http.Client // health checks
	Logger    *slog.Logger
	// Client overrides the Engine client (tests).
	Client *Client
}

// Service implements docker.* and deploy.container.swap.
type Service struct {
	opts Options
	c    *Client
	log  *slog.Logger
}

// New creates the service.
func New(o Options) *Service {
	if o.Runner == nil {
		o.Runner = runner.Exec{}
	}
	if o.HTTP == nil {
		o.HTTP = &http.Client{Timeout: 5 * time.Second}
	}
	if o.Logger == nil {
		o.Logger = slog.Default()
	}
	c := o.Client
	if c == nil {
		c = NewClient(o.Socket)
	}
	return &Service{opts: o, c: c, log: o.Logger.With("component", "docker")}
}

// Client exposes the Engine client (facts).
func (s *Service) Client() *Client { return s.c }

// Register adds docker.* and deploy.container.swap.
func (s *Service) Register(reg *commands.Registry) {
	reg.Register("docker.pull", commands.Typed(s.pull))
	reg.Register("docker.run", commands.Typed(s.run))
	reg.Register("docker.stop", commands.Typed(s.stop))
	reg.Register("docker.prune", commands.Typed(s.prune))
	reg.Register("docker.compose.up", commands.Typed(s.composeUp))
	reg.Register("docker.compose.down", commands.Typed(s.composeDown))
	reg.Register("docker.compose.pull", commands.Typed(s.composePull))
	reg.Register("docker.compose.ps", commands.Typed(s.composePs))
	reg.Register("docker.compose.restart", commands.Typed(s.composeRestart))
	reg.Register("deploy.container.swap", commands.Typed(s.swap))
}

// ---- docker.pull ----

type PullPayload struct {
	Image string `json:"image"`
	Auth  *Auth  `json:"auth,omitempty"`
}

type PullResult struct {
	ImageID string `json:"image_id"`
}

func (s *Service) pull(ctx context.Context, p PullPayload, st commands.Stream) (any, error) {
	if p.Image == "" {
		return nil, &commands.PayloadError{Err: fmt.Errorf("image required")}
	}
	if err := s.c.ImagePull(ctx, p.Image, p.Auth, st.Stdout()); err != nil {
		return nil, err
	}
	id, ok, err := s.c.ImageInspect(ctx, p.Image)
	if err != nil {
		return nil, err
	}
	if !ok {
		return nil, fmt.Errorf("image %s missing after pull", p.Image)
	}
	return PullResult{ImageID: id}, nil
}

func (s *Service) ensureImage(ctx context.Context, image, policy string, auth *Auth, st commands.Stream) error {
	switch policy {
	case "", "missing":
		_, ok, err := s.c.ImageInspect(ctx, image)
		if err != nil || ok {
			return err
		}
	case "never":
		return nil
	case "always":
	default:
		return &commands.PayloadError{Err: fmt.Errorf("invalid pull policy %q", policy)}
	}
	var w interface{ Write([]byte) (int, error) }
	if st != nil {
		w = st.Stdout()
	}
	return s.c.ImagePull(ctx, image, auth, w)
}

// ---- docker.run ----

type PortSpec struct {
	HostIP        string `json:"host_ip,omitempty"`
	HostPort      int    `json:"host_port,omitempty"`
	ContainerPort int    `json:"container_port"`
	Protocol      string `json:"protocol,omitempty"`
}

type VolumeSpec struct {
	Source   string `json:"source"`
	Target   string `json:"target"`
	ReadOnly bool   `json:"read_only,omitempty"`
}

type RunPayload struct {
	Name          string            `json:"name"`
	Image         string            `json:"image"`
	Pull          string            `json:"pull,omitempty"`
	Auth          *Auth             `json:"auth,omitempty"`
	Env           map[string]string `json:"env,omitempty"`
	Command       []string          `json:"command,omitempty"`
	Entrypoint    []string          `json:"entrypoint,omitempty"`
	User          string            `json:"user,omitempty"`
	Ports         []PortSpec        `json:"ports,omitempty"`
	Volumes       []VolumeSpec      `json:"volumes,omitempty"`
	Network       string            `json:"network,omitempty"`
	Labels        map[string]string `json:"labels,omitempty"`
	RestartPolicy string            `json:"restart_policy,omitempty"`
	MemoryBytes   int64             `json:"memory_bytes,omitempty"`
	CPUs          float64           `json:"cpus,omitempty"`
}

type RunResult struct {
	Changed     bool   `json:"changed"`
	ContainerID string `json:"container_id"`
}

var containerNameRe = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]+$`)

// Labels set on every managed container.
const (
	LabelManaged  = "kiln.managed"
	LabelSpecHash = "kiln.spec-hash"
	LabelSite     = "kiln.site"
	LabelColor    = "kiln.color"
)

// specHash hashes everything that defines the container (not pull policy or credentials).
func (p RunPayload) specHash() string {
	q := p
	q.Pull, q.Auth = "", nil
	b, _ := json.Marshal(q)
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

func (p RunPayload) createBody(hash string) CreateBody {
	b := CreateBody{Image: p.Image, Cmd: p.Command, Entrypoint: p.Entrypoint, User: p.User,
		Labels: map[string]string{LabelManaged: "true", LabelSpecHash: hash}}
	for k, v := range p.Labels {
		if k != LabelManaged && k != LabelSpecHash {
			b.Labels[k] = v
		}
	}
	keys := make([]string, 0, len(p.Env))
	for k := range p.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		b.Env = append(b.Env, k+"="+p.Env[k])
	}
	for _, pt := range p.Ports {
		proto := pt.Protocol
		if proto == "" {
			proto = "tcp"
		}
		key := strconv.Itoa(pt.ContainerPort) + "/" + proto
		if b.ExposedPorts == nil {
			b.ExposedPorts = map[string]struct{}{}
			b.HostConfig.PortBindings = map[string][]PortBinding{}
		}
		b.ExposedPorts[key] = struct{}{}
		if pt.HostPort > 0 {
			ip := pt.HostIP
			if ip == "" {
				ip = "127.0.0.1"
			}
			b.HostConfig.PortBindings[key] = append(b.HostConfig.PortBindings[key], PortBinding{HostIP: ip, HostPort: strconv.Itoa(pt.HostPort)})
		}
	}
	for _, v := range p.Volumes {
		bind := v.Source + ":" + v.Target
		if v.ReadOnly {
			bind += ":ro"
		}
		b.HostConfig.Binds = append(b.HostConfig.Binds, bind)
	}
	b.HostConfig.NetworkMode = p.Network
	rp := p.RestartPolicy
	if rp == "" {
		rp = "unless-stopped"
	}
	b.HostConfig.RestartPolicy = RestartPolicy{Name: rp}
	b.HostConfig.Memory = p.MemoryBytes
	b.HostConfig.NanoCPUs = int64(p.CPUs * 1e9)
	return b
}

func (s *Service) run(ctx context.Context, p RunPayload, st commands.Stream) (any, error) {
	if !containerNameRe.MatchString(p.Name) || p.Image == "" {
		return nil, &commands.PayloadError{Err: fmt.Errorf("name and image are required")}
	}
	id, changed, err := s.ensureContainer(ctx, p, st)
	return RunResult{Changed: changed, ContainerID: id}, err
}

// ensureContainer converges a named container to the payload spec.
func (s *Service) ensureContainer(ctx context.Context, p RunPayload, st commands.Stream) (string, bool, error) {
	hash := p.specHash()
	cur, exists, err := s.c.ContainerInspect(ctx, p.Name)
	if err != nil {
		return "", false, err
	}
	if exists && cur.Config.Labels[LabelSpecHash] == hash && p.Pull != "always" {
		if cur.State.Running {
			return cur.ID, false, nil
		}
		return cur.ID, true, s.c.ContainerStart(ctx, cur.ID)
	}
	if err := s.ensureImage(ctx, p.Image, p.Pull, p.Auth, st); err != nil {
		return "", false, err
	}
	if exists {
		if p.Pull == "always" && cur.Config.Labels[LabelSpecHash] == hash {
			// Same spec: only recreate if the pulled image is newer than the container's.
			if id, ok, _ := s.c.ImageInspect(ctx, p.Image); ok && id == cur.Image {
				if cur.State.Running {
					return cur.ID, false, nil
				}
				return cur.ID, true, s.c.ContainerStart(ctx, cur.ID)
			}
		}
		s.log.Info("recreating container", "name", p.Name)
		if _, err := s.c.ContainerStop(ctx, cur.ID, 10*time.Second); err != nil && !IsNotFound(err) {
			return "", false, err
		}
		if err := s.c.ContainerRemove(ctx, cur.ID); err != nil {
			return "", false, err
		}
	}
	id, err := s.c.ContainerCreate(ctx, p.Name, p.createBody(hash))
	if err != nil {
		return "", false, err
	}
	return id, true, s.c.ContainerStart(ctx, id)
}

// ---- docker.stop ----

type StopPayload struct {
	Name     string `json:"name"`
	TimeoutS *int   `json:"timeout_s,omitempty"`
	Remove   bool   `json:"remove,omitempty"`
}

type ChangedResult struct {
	Changed bool `json:"changed"`
}

func (s *Service) stop(ctx context.Context, p StopPayload, _ commands.Stream) (any, error) {
	cur, exists, err := s.c.ContainerInspect(ctx, p.Name)
	if err != nil || !exists {
		return ChangedResult{}, err
	}
	t := 10
	if p.TimeoutS != nil {
		t = *p.TimeoutS
	}
	changed := false
	if cur.State.Running {
		stopped, err := s.c.ContainerStop(ctx, cur.ID, time.Duration(t)*time.Second)
		if err != nil {
			return nil, err
		}
		changed = stopped
	}
	if p.Remove {
		if err := s.c.ContainerRemove(ctx, cur.ID); err != nil {
			return nil, err
		}
		changed = true
	}
	return ChangedResult{Changed: changed}, nil
}

// ---- docker.prune ----

type PrunePayload struct {
	Containers *bool  `json:"containers,omitempty"`
	Images     string `json:"images,omitempty"`
	Volumes    bool   `json:"volumes,omitempty"`
	Networks   *bool  `json:"networks,omitempty"`
	BuildCache bool   `json:"build_cache,omitempty"`
	Until      string `json:"until,omitempty"`
}

type PruneResult struct {
	SpaceReclaimedBytes uint64 `json:"space_reclaimed_bytes"`
}

var untilRe = regexp.MustCompile(`^[0-9]+[smh]$`)

func (s *Service) prune(ctx context.Context, p PrunePayload, st commands.Stream) (any, error) {
	if p.Until != "" && !untilRe.MatchString(p.Until) {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid until %q", p.Until)}
	}
	until := func() map[string][]string {
		if p.Until == "" {
			return map[string][]string{}
		}
		return map[string][]string{"until": {p.Until}}
	}
	type step struct {
		kind    string
		filters map[string][]string
	}
	var steps []step
	if p.Containers == nil || *p.Containers {
		steps = append(steps, step{"containers", until()})
	}
	switch p.Images {
	case "", "dangling":
		f := until()
		f["dangling"] = []string{"true"}
		steps = append(steps, step{"images", f})
	case "all":
		f := until()
		f["dangling"] = []string{"false"}
		steps = append(steps, step{"images", f})
	case "none":
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid images mode %q", p.Images)}
	}
	if p.Volumes {
		steps = append(steps, step{"volumes", nil}) // volumes prune does not support `until`
	}
	if p.Networks == nil || *p.Networks {
		steps = append(steps, step{"networks", until()})
	}
	if p.BuildCache {
		steps = append(steps, step{"build", until()})
	}
	var total uint64
	for _, stp := range steps {
		n, err := s.c.Prune(ctx, stp.kind, stp.filters)
		if err != nil {
			return nil, fmt.Errorf("prune %s: %w", stp.kind, err)
		}
		fmt.Fprintf(st.Stdout(), "pruned %s: %d bytes\n", stp.kind, n)
		total += n
	}
	return PruneResult{SpaceReclaimedBytes: total}, nil
}
