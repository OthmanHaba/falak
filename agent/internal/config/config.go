// Package config holds agent configuration from flags and FALAK_* environment variables.
package config

import (
	"flag"
	"os"
	"path/filepath"
	"strconv"
	"time"
)

// Config is the runtime configuration of falak-agent.
type Config struct {
	PanelURL   string // https://panel.example — used for enrollment only
	Token      string // one-time enrollment token
	EtcDir     string // /etc/falak: agent.key, agent.crt, ca.crt, agent.json, telemetry.json, certs/
	StateDir   string // /var/lib/falak: proc/cron state, otlp disk buffer
	RunDir     string // /run/falak: otlp.sock
	LogDir     string // /var/log/falak: supervised program logs
	SitesRoot  string // /srv/falak/sites
	HostRoot   string // "/" in production; tests re-root the host fs
	OTLPHTTP   string // 127.0.0.1:4318 ("" disables)
	OTLPSocket string // /run/falak/otlp.sock ("" disables)
	Heartbeat  time.Duration
	PollWait   int // seconds for GET /commands?wait=
	CaddyAdmin string
	DockerSock string
	Insecure   bool // skip TLS verification for enrollment (sim/dev only)
	// VolumeBindAllow lists the host directories `bind` volumes may use (colon-separated in FALAK_VOLUME_BIND_ALLOW);
	// empty refuses every bind volume.
	VolumeBindAllow string
}

// BindAllow splits VolumeBindAllow into absolute paths.
func (c Config) BindAllow() []string {
	var out []string
	for _, p := range filepath.SplitList(c.VolumeBindAllow) {
		if filepath.IsAbs(p) {
			out = append(out, filepath.Clean(p))
		}
	}
	return out
}

// Default returns production defaults.
func Default() Config {
	return Config{
		EtcDir:     "/etc/falak",
		StateDir:   "/var/lib/falak",
		RunDir:     "/run/falak",
		LogDir:     "/var/log/falak",
		SitesRoot:  "/srv/falak/sites",
		HostRoot:   "/",
		OTLPHTTP:   "127.0.0.1:4318",
		OTLPSocket: "/run/falak/otlp.sock",
		Heartbeat:  15 * time.Second,
		PollWait:   30,
		CaddyAdmin: "unix:///run/falak-edge/admin.sock",
		DockerSock: "/var/run/docker.sock",
	}
}

// Bind registers flags on fs; defaults come from env (FALAK_*) overlaid on Default().
func (c *Config) Bind(fs *flag.FlagSet) {
	d := Default()
	s := func(p *string, name, env, def, usage string) {
		fs.StringVar(p, name, envOr(env, def), usage+" (env "+env+")")
	}
	s(&c.PanelURL, "panel", "FALAK_PANEL_URL", "", "control-plane base URL for enrollment")
	s(&c.Token, "token", "FALAK_TOKEN", "", "one-time enrollment token")
	s(&c.EtcDir, "etc-dir", "FALAK_ETC_DIR", d.EtcDir, "config + credentials directory")
	s(&c.StateDir, "state-dir", "FALAK_STATE_DIR", d.StateDir, "state directory")
	s(&c.RunDir, "run-dir", "FALAK_RUN_DIR", d.RunDir, "runtime directory")
	s(&c.LogDir, "log-dir", "FALAK_LOG_DIR", d.LogDir, "program log directory")
	s(&c.SitesRoot, "sites-root", "FALAK_SITES_ROOT", d.SitesRoot, "sites root")
	s(&c.HostRoot, "host-root", "FALAK_HOST_ROOT", d.HostRoot, "host filesystem root (testing)")
	s(&c.OTLPHTTP, "otlp-http", "FALAK_OTLP_HTTP", d.OTLPHTTP, "OTLP/HTTP listen address (empty disables)")
	s(&c.OTLPSocket, "otlp-socket", "FALAK_OTLP_SOCKET", d.OTLPSocket, "OTLP unix socket (empty disables)")
	s(&c.CaddyAdmin, "caddy-admin", "FALAK_CADDY_ADMIN", d.CaddyAdmin, "Caddy admin API URL")
	s(&c.DockerSock, "docker-socket", "FALAK_DOCKER_SOCKET", d.DockerSock, "Docker Engine socket")
	s(&c.VolumeBindAllow, "volume-bind-allow", "FALAK_VOLUME_BIND_ALLOW", "", "host directories bind volumes may use, colon-separated (empty refuses bind volumes)")
	fs.DurationVar(&c.Heartbeat, "heartbeat", envDur("FALAK_HEARTBEAT", d.Heartbeat), "heartbeat interval (env FALAK_HEARTBEAT)")
	fs.IntVar(&c.PollWait, "poll-wait", envInt("FALAK_POLL_WAIT", d.PollWait), "long-poll wait seconds (env FALAK_POLL_WAIT)")
	fs.BoolVar(&c.Insecure, "insecure-enroll", os.Getenv("FALAK_INSECURE_ENROLL") == "1", "skip TLS verify during enrollment (dev only)")
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
