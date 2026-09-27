// Package telemetry wires the OTLP relay, Insights tee, host metrics and log shipping together and
// implements the telemetry.configure command.
package telemetry

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"path/filepath"
	"sync"
	"time"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/docker"
	"github.com/kiln/agent/internal/hostfs"
	"github.com/kiln/agent/internal/insights"
	"github.com/kiln/agent/internal/logs"
	"github.com/kiln/agent/internal/metrics"
	"github.com/kiln/agent/internal/obs"
	"github.com/kiln/agent/internal/otlp"
)

// Payload mirrors contracts/agent-protocol/commands/telemetry.configure.schema.json.
type Payload struct {
	Endpoint       string            `json:"endpoint,omitempty"`
	Headers        map[string]string `json:"headers,omitempty"`
	Resource       *Resource         `json:"resource,omitempty"`
	Sampling       *Sampling         `json:"sampling,omitempty"`
	Sites          []otlp.Site       `json:"sites,omitempty"`
	LogSources     []logs.Source     `json:"log_sources,omitempty"`
	DockerLogs     *DockerLogs       `json:"docker_logs,omitempty"`
	Metrics        *MetricsCfg       `json:"metrics,omitempty"`
	Insights       *Toggle           `json:"insights,omitempty"`
	BufferMaxBytes *int64            `json:"buffer_max_bytes,omitempty"`
}

// Resource attributes set on every signal.
type Resource struct {
	OrgID       string `json:"org_id,omitempty"`
	ServerID    string `json:"server_id,omitempty"`
	Environment string `json:"environment,omitempty"`
}

// Sampling configuration.
type Sampling struct {
	TracesRatio *float64 `json:"traces_ratio,omitempty"`
}

// DockerLogs configuration.
type DockerLogs struct {
	Enabled       *bool             `json:"enabled,omitempty"`
	LabelSelector map[string]string `json:"label_selector,omitempty"`
}

// MetricsCfg configures host metrics.
type MetricsCfg struct {
	Enabled   *bool `json:"enabled,omitempty"`
	IntervalS int   `json:"interval_s,omitempty"`
}

// Toggle is an {enabled} object.
type Toggle struct {
	Enabled *bool `json:"enabled,omitempty"`
}

// Result of telemetry.configure.
type Result struct {
	Changed bool `json:"changed"`
}

// Options configure the Service. UnixSocket, HTTPAddr and DockerSocket are literal paths/addresses;
// EtcDir and StateDir are host paths resolved through FS.
type Options struct {
	FS           hostfs.FS
	EtcDir       string
	StateDir     string
	UnixSocket   string
	HTTPAddr     string
	Endpoint     string // OTLP endpoint from enrollment; telemetry.configure may override it
	ServerID     string
	HostName     string
	DockerSocket string
	Insights     interface {
		PostInsights(ctx context.Context, ndjson []byte) error
	}
	Logger *slog.Logger
	// Optional tuning (tests).
	FlushInterval time.Duration
}

// Service owns all telemetry subsystems.
type Service struct {
	opts      Options
	log       *slog.Logger
	relay     *otlp.Relay
	tee       *insights.Tee
	sampler   *metrics.Sampler
	collector *metrics.Collector
	tailer    *logs.Tailer
	docker    *logs.Docker
	stats     *metrics.ContainerCollector

	mu        sync.Mutex
	cur       Payload
	listeners otlp.Listeners
}

// New builds the service (nothing runs until Start).
func New(opts Options) (*Service, error) {
	if opts.Logger == nil {
		opts.Logger = slog.Default()
	}
	if opts.EtcDir == "" || opts.StateDir == "" {
		return nil, errors.New("telemetry: EtcDir and StateDir required")
	}
	s := &Service{opts: opts, log: opts.Logger}
	if opts.Insights != nil {
		s.tee = insights.New(opts.Insights, nil, opts.Logger)
	}
	ro := otlp.Options{BufferDir: opts.FS.P(filepath.Join(opts.StateDir, "otlp-buffer")), Logger: opts.Logger, FlushInterval: opts.FlushInterval}
	if s.tee != nil {
		ro.Observer = s.tee
	}
	relay, err := otlp.NewRelay(ro)
	if err != nil {
		return nil, err
	}
	s.relay = relay
	s.sampler = metrics.NewSampler(opts.FS)
	s.collector = metrics.NewCollector(s.sampler, relay)
	s.tailer = logs.NewTailer(opts.FS, opts.StateDir, relay, opts.Logger)
	if opts.DockerSocket != "" {
		s.docker = logs.NewDocker(opts.DockerSocket, relay, opts.Logger)
		s.stats = metrics.NewContainerCollector(docker.NewClient(opts.DockerSocket), relay)
	}
	// Persisted configuration from a previous telemetry.configure.
	if b, err := opts.FS.ReadFile(s.configPath()); err == nil {
		var p Payload
		if err := json.Unmarshal(b, &p); err != nil {
			s.log.Warn("ignoring corrupt telemetry.json", "err", err)
		} else {
			s.cur = p
		}
	} else if !errors.Is(err, fs.ErrNotExist) {
		return nil, err
	}
	s.apply(s.cur)
	return s, nil
}

func (s *Service) configPath() string { return filepath.Join(s.opts.EtcDir, "telemetry.json") }

// Start launches the receiver and background loops; returns an error when a listener fails.
func (s *Service) Start(ctx context.Context) error {
	ls, err := s.relay.Serve(ctx, s.opts.UnixSocket, s.opts.HTTPAddr)
	if err != nil {
		return err
	}
	s.mu.Lock()
	s.listeners = ls
	s.mu.Unlock()
	go s.relay.Run(ctx)
	go s.collector.Run(ctx)
	go s.tailer.Run(ctx)
	if s.docker != nil {
		go s.docker.Run(ctx)
	}
	if s.stats != nil {
		go s.stats.Run(ctx)
	}
	if s.tee != nil {
		go s.tee.Run(ctx)
	}
	return nil
}

// Listeners reports the bound receiver addresses (after Start).
func (s *Service) Listeners() otlp.Listeners {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.listeners
}

// Sink returns the obs.Sink used by supervisor/cron to emit logs and spans.
func (s *Service) Sink() obs.Sink { return s.relay }

// Relay exposes the relay (stats / tests).
func (s *Service) Relay() *otlp.Relay { return s.relay }

// Summary returns the heartbeat metrics summary.
func (s *Service) Summary() metrics.Summary { return s.sampler.Summary() }

// SetEndpoint updates the enrollment endpoint (used when telemetry.configure has none).
func (s *Service) SetEndpoint(ep string) {
	s.mu.Lock()
	s.opts.Endpoint = ep
	p := s.cur
	s.mu.Unlock()
	s.apply(p)
}

// Register adds telemetry.configure to the registry.
func (s *Service) Register(reg *commands.Registry) {
	reg.Register("telemetry.configure", commands.Typed(s.configure))
}

func (s *Service) configure(ctx context.Context, p Payload, _ commands.Stream) (any, error) {
	if err := validate(p); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	b, err := json.MarshalIndent(p, "", "  ")
	if err != nil {
		return nil, err
	}
	b = append(b, '\n')
	cur, _ := s.opts.FS.ReadFile(s.configPath())
	changed := !bytes.Equal(cur, b)
	if changed {
		if err := s.opts.FS.MkdirAll(s.opts.EtcDir, 0o755); err != nil {
			return nil, err
		}
	}
	// 0600: headers may carry exporter credentials. WriteFile is a no-op when unchanged.
	if _, err := s.opts.FS.WriteFile(s.configPath(), b, 0o600); err != nil {
		return nil, err
	}
	s.mu.Lock()
	s.cur = p
	s.mu.Unlock()
	s.apply(p)
	return Result{Changed: changed}, nil
}

func validate(p Payload) error {
	if p.Sampling != nil && p.Sampling.TracesRatio != nil && (*p.Sampling.TracesRatio < 0 || *p.Sampling.TracesRatio > 1) {
		return fmt.Errorf("sampling.traces_ratio must be within [0,1]")
	}
	if p.Metrics != nil && p.Metrics.IntervalS != 0 && p.Metrics.IntervalS < 5 {
		return fmt.Errorf("metrics.interval_s must be ≥ 5")
	}
	for _, src := range p.LogSources {
		if !filepath.IsAbs(src.Path) {
			return fmt.Errorf("log source path %q must be absolute", src.Path)
		}
		if _, err := filepath.Match(src.Path, ""); err != nil {
			return fmt.Errorf("log source path %q: %w", src.Path, err)
		}
		if src.Format != "" && src.Format != "plain" && src.Format != "json" {
			return fmt.Errorf("log source format %q", src.Format)
		}
	}
	for _, site := range p.Sites {
		if site.Slug == "" || site.SiteID == "" {
			return fmt.Errorf("sites[] require slug and site_id")
		}
	}
	return nil
}

func boolOr(b *bool, def bool) bool {
	if b == nil {
		return def
	}
	return *b
}

// apply hot-reloads every subsystem from p (defaults per the schema).
func (s *Service) apply(p Payload) {
	s.mu.Lock()
	endpoint := s.opts.Endpoint
	s.mu.Unlock()
	cfg := otlp.Config{Endpoint: endpoint, Headers: p.Headers, ServerID: s.opts.ServerID, HostName: s.opts.HostName,
		Environment: "production", Sites: p.Sites, TracesRatio: 1, BufferMax: otlp.DefaultBufferMax}
	if p.Endpoint != "" {
		cfg.Endpoint = p.Endpoint
	}
	if r := p.Resource; r != nil {
		cfg.OrgID = r.OrgID
		if r.ServerID != "" {
			cfg.ServerID = r.ServerID
		}
		if r.Environment != "" {
			cfg.Environment = r.Environment
		}
	}
	if p.Sampling != nil && p.Sampling.TracesRatio != nil {
		cfg.TracesRatio = *p.Sampling.TracesRatio
	}
	if p.BufferMaxBytes != nil && *p.BufferMaxBytes > 0 {
		cfg.BufferMax = *p.BufferMaxBytes
	}
	s.relay.Configure(cfg)

	interval := 15 * time.Second
	enabled := true
	if p.Metrics != nil {
		enabled = boolOr(p.Metrics.Enabled, true)
		if p.Metrics.IntervalS > 0 {
			interval = time.Duration(p.Metrics.IntervalS) * time.Second
		}
	}
	s.collector.Configure(enabled, interval)
	if s.stats != nil {
		s.stats.Configure(enabled, interval)
	}
	s.tailer.SetSources(p.LogSources)
	if s.docker != nil {
		on, labels := true, map[string]string(nil)
		if p.DockerLogs != nil {
			on = boolOr(p.DockerLogs.Enabled, true)
			labels = p.DockerLogs.LabelSelector
		}
		s.docker.Configure(on, labels)
	}
	if s.tee != nil {
		s.tee.SetEnabled(p.Insights == nil || boolOr(p.Insights.Enabled, true))
	}
}
