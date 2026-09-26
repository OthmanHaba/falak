// Package agent wires every subsystem of kiln-agent together: enrollment, transport loops, the
// command registry with all executors, the supervisor, cron, terminals and the telemetry relay.
package agent

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/config"
	"github.com/kiln/agent/internal/cron"
	"github.com/kiln/agent/internal/db"
	"github.com/kiln/agent/internal/deploy"
	"github.com/kiln/agent/internal/docker"
	"github.com/kiln/agent/internal/edge"
	"github.com/kiln/agent/internal/enroll"
	"github.com/kiln/agent/internal/facts"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/netcfg"
	"github.com/kiln/agent/internal/provision"
	"github.com/kiln/agent/internal/pty"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runtime"
	"github.com/kiln/agent/internal/supervisor"
	"github.com/kiln/agent/internal/system"
	"github.com/kiln/agent/internal/telemetry"
	"github.com/kiln/agent/internal/transport"
	"github.com/kiln/agent/internal/version"
)

// InsightsPoster posts NDJSON to /agent/v1/insights.
type InsightsPoster interface {
	PostInsights(ctx context.Context, ndjson []byte) error
}

// Deps are the external dependencies of Build.
type Deps struct {
	Config    config.Config
	FS        hostfs.FS
	Runner    runner.Runner
	HTTP      *http.Client // outbound downloads (artifacts, runtimes, upgrades)
	Insights  InsightsPoster
	Telemetry *telemetry.Service
	Logger    *slog.Logger
	// RestartAgent restarts the service after system.upgrade_agent.
	RestartAgent func() error
}

// Components are the long-running subsystems plus the populated registry.
type Components struct {
	Registry   *commands.Registry
	Supervisor *supervisor.Supervisor
	Cron       *cron.Scheduler
	PTY        *pty.Manager
	Docker     *docker.Service
	Edge       *edge.Manager
	Deployer   *deploy.Deployer
}

// Build constructs every executor and registers the full v1 catalogue.
func Build(d Deps) *Components {
	cfg, log := d.Config, d.Logger
	if log == nil {
		log = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = &http.Client{Timeout: 30 * time.Minute}
	}
	reg := commands.NewRegistry()
	sink := d.Telemetry.Sink()

	sup := supervisor.New(supervisor.Options{StateDir: d.FS.P(cfg.StateDir), LogDir: d.FS.P(cfg.LogDir), Sink: sink, Logger: log.With("component", "supervisor")})
	var insights cron.InsightsPoster
	if d.Insights != nil {
		insights = d.Insights
	}
	tel := d.Telemetry
	sched := cron.New(cron.Options{
		StateDir: d.FS.P(cfg.StateDir), Runner: d.Runner, Insights: insights, Sink: sink, Logger: log.With("component", "cron"),
		SiteID: func(slug string) string {
			for _, s := range tel.Relay().Config().Sites {
				if s.Slug == slug {
					return s.SiteID
				}
			}
			return slug
		},
	})
	edgeClient := &edge.Client{Base: cfg.CaddyAdmin}
	edgeMgr := edge.New(edge.Options{Client: edgeClient, FS: d.FS, EtcDir: cfg.EtcDir, Logger: log.With("component", "edge")})
	dock := docker.New(docker.Options{Socket: cfg.DockerSock, Runner: d.Runner, FS: d.FS, Upstreams: edgeMgr, Logger: log.With("component", "docker")})
	dep := deploy.New(deploy.Options{FS: d.FS, Runner: d.Runner, HTTP: d.HTTP, SitesRoot: cfg.SitesRoot, Procs: sup, Workers: edgeClient, Logger: log.With("component", "deploy")})
	terms := pty.New(pty.Options{Logger: log.With("component", "pty")})

	system.New(system.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP, AgentVersion: version.Version, Restart: d.RestartAgent}).Register(reg)
	provision.New(provision.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	runtime.New(runtime.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	edgeMgr.Register(reg)
	dep.Register(reg)
	dock.Register(reg) // docker.* + deploy.container.swap
	sup.Register(reg)
	sched.Register(reg)
	db.New(db.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP, StateDir: cfg.StateDir}).Register(reg)
	netcfg.New(netcfg.Deps{Runner: d.Runner, FS: d.FS, Logger: log}).Register(reg)
	d.Telemetry.Register(reg)
	terms.Register(reg)

	return &Components{Registry: reg, Supervisor: sup, Cron: sched, PTY: terms, Docker: dock, Edge: edgeMgr, Deployer: dep}
}

// EnrollOnly enrolls (if needed) and returns.
func EnrollOnly(ctx context.Context, cfg config.Config, log *slog.Logger) error {
	_, err := ensureEnrolled(ctx, cfg, log)
	return err
}

func ensureEnrolled(ctx context.Context, cfg config.Config, log *slog.Logger) (*enroll.Identity, error) {
	paths := enroll.Paths{Dir: cfg.EtcDir}
	if !paths.Enrolled() {
		if cfg.PanelURL == "" || cfg.Token == "" {
			return nil, errors.New("agent is not enrolled: set KILN_PANEL_URL and KILN_TOKEN (or --panel/--token)")
		}
		if err := os.MkdirAll(cfg.EtcDir, 0o711); err != nil {
			return nil, err
		}
		f, err := facts.Collect(ctx, runner.Exec{}, hostfs.FS{Root: cfg.HostRoot}, version.Version)
		if err != nil {
			log.Warn("facts collection incomplete", "err", err)
		}
		host, _ := os.Hostname()
		hc := &http.Client{Timeout: 60 * time.Second}
		if cfg.Insecure {
			hc.Transport = &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}} //nolint:gosec // explicit dev-only flag
		}
		st, err := enroll.Enroll(ctx, enroll.Options{PanelURL: cfg.PanelURL, Token: cfg.Token, Facts: f, Hostname: host, Paths: paths, Client: hc})
		if err != nil {
			return nil, err
		}
		log.Info("enrolled", "agent_id", st.AgentID, "api", st.Endpoints.API)
	}
	return enroll.Load(paths)
}

// Run is `kiln-agent run`: enroll if needed, start every subsystem, serve until ctx is cancelled.
func Run(ctx context.Context, cfg config.Config, log *slog.Logger) error {
	id, err := ensureEnrolled(ctx, cfg, log)
	if err != nil {
		return err
	}
	fs := hostfs.FS{Root: cfg.HostRoot}
	for _, dir := range []string{cfg.StateDir, cfg.LogDir, cfg.RunDir} {
		if err := fs.MkdirAll(dir, 0o755); err != nil {
			return err
		}
	}
	if cfg.OTLPSocket != "" {
		_ = os.MkdirAll(filepath.Dir(cfg.OTLPSocket), 0o755)
	}
	log = log.With("agent_id", id.State.AgentID)
	client := transport.New(id.State.Endpoints.API, id.TLSConfig())
	client.UserAgent = "kiln-agent/" + version.Version
	host, _ := os.Hostname()
	r := runner.Exec{}

	tel, err := telemetry.New(telemetry.Options{
		FS: fs, EtcDir: cfg.EtcDir, StateDir: cfg.StateDir, UnixSocket: cfg.OTLPSocket, HTTPAddr: cfg.OTLPHTTP,
		Endpoint: id.State.Endpoints.OTLP, ServerID: id.State.AgentID, HostName: host, DockerSocket: dockerSocketIfPresent(cfg.DockerSock),
		Insights: client, Logger: log.With("component", "telemetry"),
	})
	if err != nil {
		return err
	}
	comps := Build(Deps{
		Config: cfg, FS: fs, Runner: r, Insights: client, Telemetry: tel, Logger: log,
		RestartAgent: func() error {
			// --no-block queues the restart so the upgrade command's finished event is flushed first.
			_, err := runner.Check(context.Background(), r, runner.Cmd{Name: "systemctl", Args: []string{"--no-block", "restart", "kiln-agent.service"}})
			return err
		},
	})

	runCtx, cancel := context.WithCancel(ctx)
	defer cancel()
	if err := tel.Start(runCtx); err != nil {
		return fmt.Errorf("telemetry: %w", err)
	}
	if err := comps.Supervisor.Start(runCtx); err != nil {
		log.Error("supervisor restore failed", "err", err)
	}
	if err := comps.Cron.Start(runCtx); err != nil {
		log.Error("cron restore failed", "err", err)
	}

	outbox := transport.NewOutbox(client, log.With("component", "outbox"))
	outboxCtx, stopOutbox := context.WithCancel(context.Background())
	var outboxDone sync.WaitGroup
	outboxDone.Add(1)
	go func() { defer outboxDone.Done(); outbox.Run(outboxCtx) }()

	disp := commands.NewDispatcher(runCtx, comps.Registry, outbox, log.With("component", "dispatcher"))
	poller := &transport.Poller{Client: client, Submit: disp.Submit, Wait: cfg.PollWait, Log: log.With("component", "poller")}
	hb := &transport.Heartbeater{
		Client: client, Interval: cfg.Heartbeat, Running: disp.Running, Log: log.With("component", "heartbeat"),
		Summary: func() transport.Heartbeat {
			s := tel.Summary()
			return transport.Heartbeat{UptimeS: s.UptimeS, Load: s.Load, CPUPercent: s.CPUPercent, MemoryUsedBytes: s.MemUsedBytes, DiskUsedBytes: s.DiskUsedBytes}
		},
		Facts: func(ctx context.Context) (any, error) { return facts.Collect(ctx, r, fs, version.Version) },
	}
	renewer := &transport.Renewer{
		NeedsRenewal: id.NeedsRenewal,
		Renew:        func(ctx context.Context) error { return id.Renew(ctx, client.Renew) },
		Log:          log.With("component", "renew"),
	}
	var loops sync.WaitGroup
	for _, fn := range []func(context.Context){poller.Run, hb.Run, renewer.Run} {
		loops.Add(1)
		go func(f func(context.Context)) { defer loops.Done(); f(runCtx) }(fn)
	}
	log.Info("kiln-agent running", "version", version.Version, "commands", len(comps.Registry.Types()))

	<-ctx.Done()
	log.Info("shutting down")
	cancel()
	loops.Wait()
	waitTimeout(disp.Wait, 30*time.Second)
	comps.PTY.CloseAll()
	comps.Supervisor.Shutdown()
	comps.Cron.Wait()
	stopOutbox()
	outboxDone.Wait()
	return nil
}

func dockerSocketIfPresent(p string) string {
	if _, err := os.Stat(p); err == nil {
		return p
	}
	return ""
}

func waitTimeout(fn func(), d time.Duration) {
	done := make(chan struct{})
	go func() { fn(); close(done) }()
	select {
	case <-done:
	case <-time.After(d):
	}
}
