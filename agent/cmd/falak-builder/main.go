// Command falak-builder is the Falak build worker (control-plane host or a `builder` server).
//
//	falak-builder run [--job FILE|-]     run one job (JSON), stream NDJSON events to stdout
//	falak-builder serve                  poll the control plane for jobs (FALAK_URL + FALAK_BUILDER_TOKEN)
//	falak-builder detect [DIR]           print the detected build plan (and generated Dockerfile with --dockerfile)
//	falak-builder version
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"

	"github.com/OthmanHaba/falak/agent/internal/builder"
	"github.com/OthmanHaba/falak/agent/internal/version"
)

func main() { os.Exit(run(os.Args[1:], os.Stdin, os.Stdout, os.Stderr)) }

func usage(w io.Writer) {
	fmt.Fprint(w, `usage: falak-builder <command> [flags]

commands:
  run      run one build job (JSON from --job FILE or stdin); NDJSON events on stdout
  serve    poll the control plane for build jobs
  detect   print the detected build plan for a directory
  version  print the version

run 'falak-builder <command> -h' for flags
`)
}

func run(args []string, stdin io.Reader, stdout, stderr io.Writer) int {
	if len(args) < 1 {
		usage(stderr)
		return 2
	}
	sub := args[0]
	fs := flag.NewFlagSet("falak-builder "+sub, flag.ContinueOnError)
	fs.SetOutput(stderr)
	defRoot := filepath.Join(os.TempDir(), "falak-builder")
	if d := os.Getenv("FALAK_BUILDER_DIR"); d != "" {
		defRoot = d
	}
	workDir := fs.String("work-dir", envOr("FALAK_BUILDER_WORK_DIR", filepath.Join(defRoot, "work")), "workspace root (env FALAK_BUILDER_WORK_DIR)")
	cacheDir := fs.String("cache-dir", envOr("FALAK_BUILDER_CACHE_DIR", filepath.Join(defRoot, "cache")), "persistent build cache (env FALAK_BUILDER_CACHE_DIR)")
	artifacts := fs.String("artifacts-dir", envOr("FALAK_BUILDER_ARTIFACTS_DIR", filepath.Join(defRoot, "artifacts")), "local artifact dir when a job has no upload URL (env FALAK_BUILDER_ARTIFACTS_DIR)")
	keep := fs.Bool("keep-workspace", false, "do not delete the workspace after the build (debugging)")
	logLevel := fs.String("log-level", envOr("FALAK_LOG_LEVEL", "info"), "debug|info|warn|error")
	var (
		jobFile    *string
		url, token *string
		name       *string
		wait       *int
		once       *bool
		hint       *string
		dockerfile *bool
	)
	switch sub {
	case "run":
		jobFile = fs.String("job", "-", "job JSON file ('-' = stdin)")
	case "serve":
		url = fs.String("url", os.Getenv("FALAK_URL"), "control-plane base URL (env FALAK_URL)")
		token = fs.String("token", os.Getenv("FALAK_BUILDER_TOKEN"), "builder token (env FALAK_BUILDER_TOKEN)")
		host, _ := os.Hostname()
		name = fs.String("name", envOr("FALAK_BUILDER_NAME", host), "builder name (env FALAK_BUILDER_NAME)")
		wait = fs.Int("wait", 30, "long-poll seconds")
		once = fs.Bool("once", false, "exit after one job")
	case "detect":
		hint = fs.String("runtime", "", "runtime hint (php|node|bun|deno|static)")
		dockerfile = fs.Bool("dockerfile", false, "print the generated Dockerfile instead of the plan")
	case "version", "--version", "-v":
		fmt.Fprintln(stdout, version.Version)
		return 0
	case "help", "-h", "--help":
		usage(stdout)
		return 0
	default:
		fmt.Fprintf(stderr, "falak-builder: unknown command %q\n", sub)
		usage(stderr)
		return 2
	}
	if err := fs.Parse(args[1:]); err != nil {
		if errors.Is(err, flag.ErrHelp) {
			return 0
		}
		return 2
	}
	var lvl slog.Level
	_ = lvl.UnmarshalText([]byte(*logLevel))
	log := slog.New(slog.NewJSONHandler(stderr, &slog.HandlerOptions{Level: lvl})).With("component", "falak-builder")
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	b := &builder.Builder{WorkDir: *workDir, CacheDir: *cacheDir, ArtifactsDir: *artifacts, KeepWorkspace: *keep, Log: log}

	switch sub {
	case "run":
		var r io.Reader = stdin
		if *jobFile != "-" {
			f, err := os.Open(*jobFile)
			if err != nil {
				fmt.Fprintln(stderr, "falak-builder:", err)
				return 1
			}
			defer f.Close()
			r = f
		}
		job, err := builder.DecodeJob(r)
		if err != nil && job.ID == "" {
			fmt.Fprintln(stderr, "falak-builder:", err)
			return 2
		}
		// A job with an id always produces a started…finished event frame, even when invalid.
		if _, err := b.Build(ctx, job, builder.NewNDJSONSink(stdout)); err != nil {
			log.Error("build failed", "build_id", job.ID, "err", err)
			return 1
		}
		return 0
	case "serve":
		if *url == "" || *token == "" {
			fmt.Fprintln(stderr, "falak-builder serve: --url and --token (or FALAK_URL, FALAK_BUILDER_TOKEN) are required")
			return 2
		}
		s := &builder.Server{URL: *url, Token: *token, Name: *name, Wait: *wait, Once: *once, Builder: b, Log: log}
		log.Info("serving", "url", *url, "name", *name, "version", version.Version)
		if err := s.Run(ctx); err != nil {
			log.Error("serve", "err", err)
			return 1
		}
		return 0
	case "detect":
		dir := "."
		if fs.NArg() > 0 {
			dir = fs.Arg(0)
		}
		plan, err := b.Plan(ctx, dir, *hint, nil, stderr)
		if err != nil {
			fmt.Fprintln(stderr, "falak-builder:", err)
			return 1
		}
		if *dockerfile {
			df, err := builder.GenerateDockerfile(plan)
			if err != nil {
				fmt.Fprintln(stderr, "falak-builder:", err)
				return 1
			}
			fmt.Fprint(stdout, df)
			return 0
		}
		enc := json.NewEncoder(stdout)
		enc.SetIndent("", "  ")
		_ = enc.Encode(plan)
		return 0
	}
	return 0
}

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
