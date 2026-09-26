// Package config holds agent configuration from flags and KILN_* environment variables.
package config

import (
	"flag"
	"os"
	"path/filepath"
	"strconv"
	"time"
)

// Config is the runtime configuration of kiln-agent.
type Config struct {
	PanelURL   string // https://panel.example — used for enrollment only
	Token      string // one-time enrollment token
	EtcDir     string // /etc/kiln: agent.key, agent.crt, ca.crt, agent.json, telemetry.json, certs/
	StateDir   string // /var/lib/kiln: proc/cron state, otlp disk buffer
	RunDir     string // /run/kiln: otlp.sock
	LogDir     string // /var/log/kiln: supervised program logs
	SitesRoot  string // /srv/kiln/sites
	HostRoot   string // "/" in production; tests re-root the host fs
	OTLPHTTP   string // 127.0.0.1:4318 ("" disables)
	OTLPSocket string // /run/kiln/otlp.sock ("" disables)
	Heartbeat  time.Duration
	PollWait   int // seconds for GET /commands?wait=
	CaddyAdmin string
	DockerSock string
	Insecure   bool // skip TLS verification for enrollment (sim/dev only)
}

// Default returns production defaults.
func Default() Config {
	return Config{
		EtcDir:     "/etc/kiln",
		StateDir:   "/var/lib/kiln",
		RunDir:     "/run/kiln",
		LogDir:     "/var/log/kiln",
		SitesRoot:  "/srv/kiln/sites",
		HostRoot:   "/",
		OTLPHTTP:   "127.0.0.1:4318",
		OTLPSocket: "/run/kiln/otlp.sock",
		Heartbeat:  15 * time.Second,
		PollWait:   30,
		CaddyAdmin: "http://127.0.0.1:2019",
		DockerSock: "/var/run/docker.sock",
	}
}

// Bind registers flags on fs; defaults come from env (KILN_*) overlaid on Default().
func (c *Config) Bind(fs *flag.FlagSet) {
	d := Default()
	s := func(p *string, name, env, def, usage string) {
		fs.StringVar(p, name, envOr(env, def), usage+" (env "+env+")")
	}
	s(&c.PanelURL, "panel", "KILN_PANEL_URL", "", "control-plane base URL for enrollment")
	s(&c.Token, "token", "KILN_TOKEN", "", "one-time enrollment token")
	s(&c.EtcDir, "etc-dir", "KILN_ETC_DIR", d.EtcDir, "config + credentials directory")
	s(&c.StateDir, "state-dir", "KILN_STATE_DIR", d.StateDir, "state directory")
	s(&c.RunDir, "run-dir", "KILN_RUN_DIR", d.RunDir, "runtime directory")
	s(&c.LogDir, "log-dir", "KILN_LOG_DIR", d.LogDir, "program log directory")
	s(&c.SitesRoot, "sites-root", "KILN_SITES_ROOT", d.SitesRoot, "sites root")
	s(&c.HostRoot, "host-root", "KILN_HOST_ROOT", d.HostRoot, "host filesystem root (testing)")
	s(&c.OTLPHTTP, "otlp-http", "KILN_OTLP_HTTP", d.OTLPHTTP, "OTLP/HTTP listen address (empty disables)")
	s(&c.OTLPSocket, "otlp-socket", "KILN_OTLP_SOCKET", d.OTLPSocket, "OTLP unix socket (empty disables)")
	s(&c.CaddyAdmin, "caddy-admin", "KILN_CADDY_ADMIN", d.CaddyAdmin, "Caddy admin API URL")
	s(&c.DockerSock, "docker-socket", "KILN_DOCKER_SOCKET", d.DockerSock, "Docker Engine socket")
	fs.DurationVar(&c.Heartbeat, "heartbeat", envDur("KILN_HEARTBEAT", d.Heartbeat), "heartbeat interval (env KILN_HEARTBEAT)")
	fs.IntVar(&c.PollWait, "poll-wait", envInt("KILN_POLL_WAIT", d.PollWait), "long-poll wait seconds (env KILN_POLL_WAIT)")
	fs.BoolVar(&c.Insecure, "insecure-enroll", os.Getenv("KILN_INSECURE_ENROLL") == "1", "skip TLS verify during enrollment (dev only)")
}

// Credential paths.
func (c Config) KeyPath() string   { return filepath.Join(c.EtcDir, "agent.key") }
func (c Config) CertPath() string  { return filepath.Join(c.EtcDir, "agent.crt") }
func (c Config) CAPath() string    { return filepath.Join(c.EtcDir, "ca.crt") }
func (c Config) StatePath() string { return filepath.Join(c.EtcDir, "agent.json") }

func envOr(k, def string) string {
	if v, ok := os.LookupEnv(k); ok {
		return v
	}
	return def
}

func envInt(k string, def int) int {
	if v, err := strconv.Atoi(os.Getenv(k)); err == nil {
		return v
	}
	return def
}

func envDur(k string, def time.Duration) time.Duration {
	if v, err := time.ParseDuration(os.Getenv(k)); err == nil {
		return v
	}
	return def
}
