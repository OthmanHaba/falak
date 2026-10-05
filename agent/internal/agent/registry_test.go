package agent

import (
	"io"
	"log/slog"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
	"github.com/OthmanHaba/falak/agent/internal/telemetry"
)

// buildTestRegistry builds the production registry against a temp host root and a fake runner.
func buildTestRegistry(t *testing.T) (*commands.Registry, func()) {
	t.Helper()
	root := t.TempDir()
	cfg := config.Default()
	cfg.HostRoot = root
	fs := hostfs.FS{Root: root}
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	tel, err := telemetry.New(telemetry.Options{FS: fs, EtcDir: cfg.EtcDir, StateDir: cfg.StateDir, Logger: log})
	if err != nil {
		t.Fatal(err)
	}
	c := Build(Deps{Config: cfg, FS: fs, Runner: &runnertest.Fake{}, Telemetry: tel, Logger: log})
	return c.Registry, func() { c.PTY.CloseAll(); c.Supervisor.Shutdown() }
}
