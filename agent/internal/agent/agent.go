// Package agent wires every subsystem of falak-agent together: enrollment, transport loops, the
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

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/config"
	"github.com/OthmanHaba/falak/agent/internal/cron"
	"github.com/OthmanHaba/falak/agent/internal/db"
	"github.com/OthmanHaba/falak/agent/internal/deploy"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/edge"
	"github.com/OthmanHaba/falak/agent/internal/enroll"
	"github.com/OthmanHaba/falak/agent/internal/facts"
	"github.com/OthmanHaba/falak/agent/internal/fngateway"
	"github.com/OthmanHaba/falak/agent/internal/functions"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/netcfg"
	"github.com/OthmanHaba/falak/agent/internal/provision"
	"github.com/OthmanHaba/falak/agent/internal/pty"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runtime"
	"github.com/OthmanHaba/falak/agent/internal/supervisor"
	"github.com/OthmanHaba/falak/agent/internal/system"
	"github.com/OthmanHaba/falak/agent/internal/telemetry"
	"github.com/OthmanHaba/falak/agent/internal/transport"
	"github.com/OthmanHaba/falak/agent/internal/version"
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
	Functions  *functions.Functions
	DB         *db.DB
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
	// Secrets on servers live on the tmpfs only: sites' env files and containers' secret files.
	dock := docker.New(docker.Options{Socket: cfg.DockerSock, Runner: d.Runner, FS: d.FS, Upstreams: edgeMgr, SecretsDir: filepath.Join(cfg.RunDir, "secrets"),
		Logger: log.With("component", "docker")})
	dep := deploy.New(deploy.Options{FS: d.FS, Runner: d.Runner, HTTP: d.HTTP, SitesRoot: cfg.SitesRoot, EnvDir: filepath.Join(cfg.RunDir, "env"), Containers: dock,
		Procs: sup, Workers: edgeClient, Events: sink, Logger: log.With("component", "deploy")})
	terms := pty.New(pty.Options{Logger: log.With("component", "pty")})

	system.New(system.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP, AgentVersion: version.Version, Restart: d.RestartAgent,
		BinaryPath: installedBinary(d.FS), RunningSHA256: version.BinarySHA256}).Register(reg)
	provision.New(provision.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	runtime.New(runtime.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	edgeMgr.Register(reg)
	dep.Register(reg)
	dock.Register(reg) // docker.* + deploy.container.swap
	sup.Register(reg)
	sched.Register(reg)
	dbs := db.New(db.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP, StateDir: cfg.StateDir})
	dbs.Register(reg)
	netcfg.New(netcfg.Deps{Runner: d.Runner, FS: d.FS, Logger: log, HTTP: d.HTTP}).Register(reg)
	fns := functions.New(functions.Deps{FS: d.FS, Runner: d.Runner, Docker: docker.NewClient(cfg.DockerSock), Gateway: fngateway.NewClient(""),
		Logger: log.With("component", "functions"), Binary: BinaryPath, Version: version.Version})
	fns.Register(reg)
	d.Telemetry.Register(reg)
	terms.Register(reg)

	return &Components{Registry: reg, Supervisor: sup, Cron: sched, PTY: terms, Docker: dock, Edge: edgeMgr, Deployer: dep, Functions: fns, DB: dbs}
}

// ensureEnrolled enrolls only when there is no identity yet: `falak-agent run` never replaces one because
// FALAK_TOKEN is still in agent.env (see EnrollOnly). With restore (`run` only), an identity left in an unfinished
// replacement's backup comes back before any enrollment.
func ensureEnrolled(ctx context.Context, cfg config.Config, log *slog.Logger, restore bool) (*enroll.Identity, error) {
	paths := enroll.Paths{Dir: cfg.EtcDir}
	if !paths.Enrolled() && !(restore && restoreIncomplete(cfg, log)) {
		if cfg.PanelURL == "" || cfg.Token == "" {
			return nil, errors.New("agent is not enrolled: set FALAK_PANEL_URL and FALAK_TOKEN (or --panel/--token)")
		}
		if err := os.MkdirAll(cfg.EtcDir, 0o711); err != nil {
			return nil, err
		}
		if _, err := enrollInto(ctx, cfg, log, paths); err != nil {
			return nil, err
		}
	}
	return enroll.Load(paths)
}

// enrollInto enrolls with cfg's panel URL and token and writes the identity to paths.
func enrollInto(ctx context.Context, cfg config.Config, log *slog.Logger, paths enroll.Paths) (*enroll.State, error) {
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
	return st, nil
}

// Run is `falak-agent run`: enroll if needed, start every subsystem, serve until ctx is cancelled.
func Run(ctx context.Context, cfg config.Config, log *slog.Logger) error {
	id, err := ensureEnrolled(ctx, cfg, log, true)
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
	client.UserAgent = "falak-agent/" + version.Version
	client.Session = transport.NewSessionID()
	client.AgentID, client.Log = id.State.AgentID, log.With("component", "transport")
	// Checksum the running build now, before a system.upgrade_agent could replace the file on disk.
	_ = version.BinarySHA256()
	host, _ := os.Hostname()
	r := runner.Exec{}

	tel, err := telemetry.New(telemetry.Options{
		FS: fs, EtcDir: cfg.EtcDir, StateDir: cfg.StateDir, UnixSocket: cfg.OTLPSocket, HTTPAddr: cfg.OTLPHTTP,
		Endpoint: id.State.Endpoints.OTLP, ServerID: id.State.AgentID, HostName: host, DockerSocket: cfg.DockerSock,
		Insights: client, Logger: log.With("component", "telemetry"),
	})
	if err != nil {
		return err
	}
	comps := Build(Deps{
		Config: cfg, FS: fs, Runner: r, Insights: client, Telemetry: tel, Logger: log,
		RestartAgent: func() error {
			// --no-block queues the restart so the upgrade command's finished event is flushed first.
			_, err := runner.Check(context.Background(), r, runner.Cmd{Name: "systemctl", Args: []string{"--no-block", "restart", "falak-agent.service"}})
			return err
		},
	})

	// Not derived from ctx: on shutdown the subsystems keep running while commands drain (see gracefulStop).
	runCtx, cancel := context.WithCancel(context.WithoutCancel(ctx))
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
	// Completed commands survive restarts, so a finished non-idempotent step is never re-run.
	if err := disp.Persist(filepath.Join(fs.P(cfg.StateDir), "commands.json"), commands.DefaultJournalSize); err != nil {
		log.Warn("command journal", "err", err)
	}
	poller := &transport.Poller{Client: client, Submit: disp.Submit, Wait: cfg.PollWait, Log: log.With("component", "poller")}
	hb := &transport.Heartbeater{
		Client: client, Interval: cfg.Heartbeat, Running: disp.Running, Log: log.With("component", "heartbeat"),
		Summary: func() transport.Heartbeat {
			s := tel.Summary()
			return transport.Heartbeat{UptimeS: s.UptimeS, Load: s.Load, CPUPercent: s.CPUPercent, MemoryUsedBytes: s.MemUsedBytes, DiskUsedBytes: s.DiskUsedBytes,
				MissingSecrets: comps.Deployer.MissingSecrets(runCtx)}
		},
		Facts: func(ctx context.Context) (any, error) {
			f, err := facts.Collect(ctx, r, fs, version.Version)
			// Facts are re-collected every few minutes: a renamed host shows up in telemetry without a restart.
			tel.SetHostName(f.Hostname)
			return f, err
		},
	}
	renewer := &transport.Renewer{
		NeedsRenewal: id.NeedsRenewal,
		Renew:        func(ctx context.Context) error { return id.Renew(ctx, client.Renew) },
		Log:          log.With("component", "renew"),
	}
	pollCtx, stopPolling := context.WithCancel(runCtx)
	var polling, loops sync.WaitGroup
	polling.Add(1)
	go func() { defer polling.Done(); poller.Run(pollCtx) }()
	// RedisWatch: Redis / Valkey instances listen on docker0 / WireGuard addresses that may appear after they started.
	for _, fn := range []func(context.Context){hb.Run, renewer.Run, comps.DB.RedisWatch} {
		loops.Add(1)
		go func(f func(context.Context)) { defer loops.Done(); f(runCtx) }(fn)
	}
	log.Info("falak-agent running", "version", version.Version, "session", client.Session, "commands", len(comps.Registry.Types()))
	loops.Add(1)
	go func() {
		defer loops.Done()
		if err := comps.Functions.RefreshGateway(runCtx); err != nil {
			log.Warn("function gateway refresh failed", "err", err)
		}
	}()

	<-ctx.Done()
	log.Info("shutting down")
	gracefulStop(stopPolling, polling.Wait, disp.Wait, shutdownDrain, cancel)
	loops.Wait()
	waitTimeout(disp.Wait, 10*time.Second)
	comps.PTY.CloseAll()
	comps.Supervisor.Shutdown()
	comps.Cron.Wait()
	// Telemetry stops with runCtx (cancelled above) and writes its state on the way out (log offsets, OTLP buffer):
	// done before Run returns, never into a state directory that is being removed or reused.
	waitTimeout(tel.Wait, 15*time.Second)
	stopOutbox()
	outboxDone.Wait()
	return nil
}

// shutdownDrain is how long running commands may finish on shutdown before they are cancelled (the systemd unit
// allows 90s in total).
const shutdownDrain = 20 * time.Second

// gracefulStop shuts the command path down in order:
//
//  1. stop long-polling and wait until the poller has returned: from here on no command is accepted, so none is
//     received by a process that is about to exit (the control plane redelivers to the next session instead);
//  2. let running commands finish for up to drain, while heartbeats keep reporting them;
//  3. cancel everything else (still-running commands, heartbeats, telemetry, supervisor).
func gracefulStop(stopPolling, pollerStopped, commandsDone func(), drain time.Duration, cancelRest func()) {
	stopPolling()
	pollerStopped()
	waitTimeout(commandsDone, drain)
	cancelRest()
}

func waitTimeout(fn func(), d time.Duration) {
	done := make(chan struct{})
	go func() { fn(); close(done) }()
	select {
	case <-done:
	case <-time.After(d):
	}
}

// installedBinary is the path system.upgrade_agent replaces: the systemd unit's binary (BinaryPath) when the
// agent is installed, else the running executable ("" = os.Executable()).
func installedBinary(fs hostfs.FS) string {
	if fs.Exists(BinaryPath) {
		return fs.P(BinaryPath)
	}
	return ""
}
