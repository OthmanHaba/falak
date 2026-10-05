package fngateway

import (
	"context"
	"log/slog"
	"path/filepath"

	"github.com/OthmanHaba/falak/agent/internal/docker"
)

// RunOptions of `falak-agent fn-gateway`.
type RunOptions struct {
	Listen       string
	AdminSocket  string
	StateDir     string
	DockerSocket string
	// AgentOTLPSocket is the agent's OTLP receiver (functions' telemetry is relayed there).
	AgentOTLPSocket string
	Version         string
	Logger          *slog.Logger
}

// Run restores the registered functions, adopts their containers and serves until ctx ends.
func Run(ctx context.Context, o RunOptions) error {
	if o.Listen == "" {
		o.Listen = DefaultListen
	}
	if o.AdminSocket == "" {
		o.AdminSocket = DefaultAdmin
	}
	if o.StateDir == "" {
		o.StateDir = DefaultStateDir
	}
	g := New(Options{
		Engine:          DockerEngine{C: docker.NewClient(o.DockerSocket)},
		StateFile:       filepath.Join(o.StateDir, "gateway.json"),
		Logger:          o.Logger,
		Version:         o.Version,
		AgentOTLPSocket: o.AgentOTLPSocket,
	})
	if err := g.Load(ctx); err != nil {
		return err
	}
	return g.Serve(ctx, o.Listen, o.AdminSocket)
}
