package fngateway

import (
	"context"
	"log/slog"
	"path/filepath"

	"github.com/kiln/agent/internal/docker"
)

// RunOptions of `kiln-agent fn-gateway`.
type RunOptions struct {
	Listen       string
	AdminSocket  string
	StateDir     string
	DockerSocket string
	Version      string
	Logger       *slog.Logger
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
		Engine:    DockerEngine{C: docker.NewClient(o.DockerSocket)},
		StateFile: filepath.Join(o.StateDir, "gateway.json"),
		Logger:    o.Logger,
		Version:   o.Version,
	})
	if err := g.Load(ctx); err != nil {
		return err
	}
	return g.Serve(ctx, o.Listen, o.AdminSocket)
}
