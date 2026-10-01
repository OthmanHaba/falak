// Command kiln-agent is the Kiln server daemon.
//
//	kiln-agent run        enroll if needed (KILN_PANEL_URL + KILN_TOKEN), then serve
//	kiln-agent enroll     enroll only
//	kiln-agent install    install binary + systemd unit and start the service
//	kiln-agent fn-gateway serve functions (kiln-fn-gateway.service; installed by fn.release.apply)
//	kiln-agent fn-run     run a function's schedule once (its cron job)
//	kiln-agent version
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/kiln/agent/internal/agent"
	"github.com/kiln/agent/internal/config"
	"github.com/kiln/agent/internal/fngateway"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/version"
)

func usage() {
	fmt.Fprintf(os.Stderr, "usage: kiln-agent <run|enroll|install|fn-gateway|fn-run|version> [flags]\n")
	os.Exit(2)
}

func main() {
	if len(os.Args) < 2 {
		usage()
	}
	sub := os.Args[1]
	fs := flag.NewFlagSet(sub, flag.ExitOnError)
	cfg := config.Default()
	cfg.Bind(fs)
	noStart := fs.Bool("no-start", false, "install: enable but do not start the service")
	fnListen := fs.String("listen", fngateway.DefaultListen, "fn-gateway: proxy address (Caddy sends function traffic here)")
	fnAdmin := fs.String("admin", fngateway.DefaultAdmin, "fn-gateway: admin API unix socket")
	fnState := fs.String("state", fngateway.DefaultStateDir, "fn-gateway: functions directory (gateway.json, releases)")
	runSite := fs.String("site", "", "fn-run: function (site slug)")
	runSchedule := fs.String("schedule", "", "fn-run: schedule key")
	runName := fs.String("name", "", "fn-run: schedule name shown to the function")
	runCron := fs.String("cron", "", "fn-run: schedule expression shown to the function")
	runTimeout := fs.Int("timeout", 300, "fn-run: seconds before the run is stopped")
	logLevel := fs.String("log-level", envOr("KILN_LOG_LEVEL", "info"), "debug|info|warn|error (env KILN_LOG_LEVEL)")
	_ = fs.Parse(os.Args[2:])

	var lvl slog.Level
	_ = lvl.UnmarshalText([]byte(*logLevel))
	log := slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: lvl})).With("component", "kiln-agent")
	slog.SetDefault(log)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	var err error
	switch sub {
	case "run":
		err = agent.Run(ctx, cfg, log)
	case "enroll":
		err = agent.EnrollOnly(ctx, cfg, log)
	case "install":
		self, _ := os.Executable()
		err = agent.Install(ctx, agent.InstallOptions{Config: cfg, Source: self, NoStart: *noStart, FS: hostfs.FS{Root: cfg.HostRoot}, Runner: runner.Exec{}, Out: os.Stdout})
	case "fn-gateway":
		err = fngateway.Run(ctx, fngateway.RunOptions{Listen: *fnListen, AdminSocket: *fnAdmin, StateDir: *fnState,
			DockerSocket: cfg.DockerSock, AgentOTLPSocket: cfg.OTLPSocket, Version: version.Version, Logger: log.With("component", "fn-gateway")})
	case "fn-run":
		// A function schedule's cron job: runs it once through the gateway; output and exit code are the run's.
		code, runErr := fngateway.NewClient(*fnAdmin).Run(ctx, *runSite, fngateway.RunRequest{Schedule: *runSchedule, Name: *runName, Cron: *runCron, Trigger: "cron", TimeoutS: *runTimeout}, os.Stdout)
		if runErr != nil {
			fmt.Fprintln(os.Stderr, "kiln: "+runErr.Error())
			if errors.Is(ctx.Err(), context.Canceled) {
				os.Exit(124)
			}
			os.Exit(1)
		}
		os.Exit(code)
	case "version", "--version", "-v":
		fmt.Println(version.Version)
	default:
		usage()
	}
	if err != nil {
		log.Error("fatal", "err", err)
		os.Exit(1)
	}
}

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
