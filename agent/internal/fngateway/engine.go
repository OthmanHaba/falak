package fngateway

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net"
	"strconv"
	"strings"
	"time"

	"github.com/kiln/agent/internal/docker"
)

// Engine is what the gateway needs from Docker (a fake in tests).
type Engine interface {
	Create(ctx context.Context, name string, body docker.CreateBody) (string, error)
	Start(ctx context.Context, id string) error
	Stop(ctx context.Context, id string, timeout time.Duration) error
	Remove(ctx context.Context, id string) error
	// List returns every container (any state) carrying all the labels ("k=v").
	List(ctx context.Context, labels []string) ([]docker.ContainerSummary, error)
	// State reports whether the container runs, its exit code, and the address its runtime listens on.
	State(ctx context.Context, id string) (ContainerState, error)
	// Logs returns the last lines of the container's output.
	Logs(ctx context.Context, id string, tail int) string
	EnsureNetwork(ctx context.Context) error
	// RunOnce creates, starts and waits for a one-shot container, streaming its output to w, and removes it. When
	// ctx ends first the container is stopped.
	RunOnce(ctx context.Context, name string, body docker.CreateBody, w io.Writer) (int, error)
}

// ContainerState of one instance.
type ContainerState struct {
	Exists   bool
	Running  bool
	ExitCode int
	Addr     string // host:port of the runtime ("" when not running)
}

// DockerEngine is the Engine over the Engine API.
type DockerEngine struct{ C *docker.Client }

func (e DockerEngine) Create(ctx context.Context, name string, body docker.CreateBody) (string, error) {
	return e.C.ContainerCreate(ctx, name, body)
}

func (e DockerEngine) Start(ctx context.Context, id string) error { return e.C.ContainerStart(ctx, id) }

func (e DockerEngine) Stop(ctx context.Context, id string, timeout time.Duration) error {
	_, err := e.C.ContainerStop(ctx, id, timeout)
	if docker.IsNotFound(err) {
		return nil
	}
	return err
}

func (e DockerEngine) Remove(ctx context.Context, id string) error {
	return e.C.ContainerRemove(ctx, id)
}

func (e DockerEngine) List(ctx context.Context, labels []string) ([]docker.ContainerSummary, error) {
	return e.C.ContainerList(ctx, true, labels)
}

func (e DockerEngine) State(ctx context.Context, id string) (ContainerState, error) {
	ct, ok, err := e.C.ContainerInspect(ctx, id)
	if err != nil || !ok {
		return ContainerState{}, err
	}
	st := ContainerState{Exists: true, Running: ct.State.Running, ExitCode: ct.State.ExitCode}
	if n, ok := ct.NetworkSettings.Networks[Network]; ok && n.IPAddress != "" && st.Running {
		st.Addr = net.JoinHostPort(n.IPAddress, strconv.Itoa(ContainerPort))
	}
	return st, nil
}

func (e DockerEngine) Logs(ctx context.Context, id string, tail int) string {
	var b bytes.Buffer
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	_ = e.C.ContainerLogs(ctx, id, false, tail, &b)
	return strings.TrimSpace(b.String())
}

func (e DockerEngine) EnsureNetwork(ctx context.Context) error {
	ok, err := e.C.NetworkExists(ctx, Network)
	if err != nil || ok {
		return err
	}
	return e.C.NetworkCreate(ctx, Network, map[string]string{LabelManaged: "true"})
}

func (e DockerEngine) RunOnce(ctx context.Context, name string, body docker.CreateBody, w io.Writer) (int, error) {
	id, err := e.C.ContainerCreate(ctx, name, body)
	if err != nil {
		return -1, err
	}
	defer func() { _ = e.C.ContainerRemove(context.Background(), id) }()
	if err := e.C.ContainerStart(ctx, id); err != nil {
		return -1, err
	}
	// The logs outlive ctx: what the runtime prints while it is being stopped (timeout) still reaches w.
	lctx, lcancel := context.WithCancel(context.Background())
	defer lcancel()
	logs := make(chan struct{})
	go func() {
		defer close(logs)
		_ = e.C.ContainerLogs(lctx, id, true, 0, w)
	}()
	code, err := e.C.ContainerWait(ctx, id)
	if ctx.Err() != nil {
		_, _ = e.C.ContainerStop(context.Background(), id, 5*time.Second)
	}
	select {
	case <-logs:
	case <-time.After(2 * time.Second):
	}
	return code, err
}

// isNameConflict reports Docker's 409 "container name already in use".
// isGone reports that the container no longer exists (removed outside the gateway, e.g. `docker container prune`).
func isGone(err error) bool {
	var ae *docker.APIError
	return errors.As(err, &ae) && ae.Status == 404
}

func isNameConflict(err error) bool {
	var ae *docker.APIError
	return errors.As(err, &ae) && ae.Status == 409
}
