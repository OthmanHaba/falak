package inspect

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
)

// Docker is what the report says about Docker; nil when no Docker CLI, engine package or snap is found.
type Docker struct {
	// Engine package: docker-ce (Docker's repository), docker.io (Ubuntu), moby-engine, podman-docker; "" when the
	// engine is not a package (snap, a static binary).
	EnginePackage string  `json:"engine_package,omitempty"`
	ClientVersion string  `json:"client_version,omitempty"`
	ServerVersion string  `json:"server_version,omitempty"` // "" when the daemon does not answer
	ServerError   string  `json:"server_error,omitempty"`
	Compose       *Plugin `json:"compose"`
	Buildx        *Plugin `json:"buildx"`
	Snap          bool    `json:"snap"`
	// Rootless: a per-user (rootless) daemon is set up for some user; SystemDaemon: docker.service exists.
	Rootless     bool          `json:"rootless"`
	SystemDaemon bool          `json:"system_daemon"`
	Daemon       *DaemonConfig `json:"daemon"`
}

// Plugin is a Docker CLI plugin (compose, buildx) that answers `docker <plugin> version`.
type Plugin struct {
	Version string `json:"version"`
	Package string `json:"package,omitempty"` // the package that provides it; "" for a plugin file not owned by a package
	Path    string `json:"path,omitempty"`
}

// DaemonConfig holds the /etc/docker/daemon.json settings that matter to Falak.
type DaemonConfig struct {
	BIP                 string            `json:"bip,omitempty"`
	DefaultAddressPools []json.RawMessage `json:"default_address_pools,omitempty"`
	IPTables            *bool             `json:"iptables,omitempty"`
	UsernsRemap         string            `json:"userns_remap,omitempty"`
	Error               string            `json:"error,omitempty"`
}

// Plugin packages, by plugin, and the directories the CLI loads plugins from.
var (
	composePackages = []string{"docker-compose-plugin", "docker-compose-v2"}
	buildxPackages  = []string{"docker-buildx-plugin", "docker-buildx"}
	enginePackages  = []string{"docker-ce", "docker.io", "moby-engine", "podman-docker"}
	pluginDirs      = []string{"/usr/libexec/docker/cli-plugins", "/usr/lib/docker/cli-plugins", "/usr/local/libexec/docker/cli-plugins", "/usr/local/lib/docker/cli-plugins", "/root/.docker/cli-plugins"}
	dockerBinaries  = []string{"/usr/bin/docker", "/usr/local/bin/docker", "/snap/bin/docker"}
)

func (in *Inspector) docker(ctx context.Context, r *Report) error {
	d := &Docker{Snap: r.snap("docker") != nil, SystemDaemon: r.service("docker.service") != nil}
	for _, n := range enginePackages {
		if r.pkg(n) != nil {
			d.EnginePackage = n
			break
		}
	}
	// The first Docker CLI in root's PATH order; it is only run when root owns it (see safe).
	cli := ""
	for _, b := range dockerBinaries {
		if cli == "" && in.exists(b) {
			cli = b
		}
	}
	d.Rootless = in.rootless()
	if cli == "" && d.EnginePackage == "" && !d.Snap && !d.Rootless {
		return nil
	}
	r.Docker = d
	if b, err := in.d.FS.ReadFile("/etc/docker/daemon.json"); err == nil {
		d.Daemon = ParseDaemonJSON(b)
	}
	if cli == "" {
		return nil
	}
	if _, err := in.safe(cli); err != nil {
		d.ServerError = truncate(redact(err.Error()), 300)
		return err
	}
	if v, err := in.output(ctx, cli, "version", "--format", "{{.Client.Version}}"); err == nil {
		d.ClientVersion = strings.TrimSpace(v)
	}
	res, err := in.run(ctx, cli, "version", "--format", "{{.Server.Version}}")
	switch {
	case err != nil:
		d.ServerError = truncate(redact(err.Error()), 300)
	case res.ExitCode != 0:
		d.ServerError = truncate(redact(string(res.Stderr)), 300)
	default:
		d.ServerVersion = strings.TrimSpace(string(res.Stdout))
	}
	var errs []error
	var perr error
	d.Compose, perr = in.plugin(ctx, r, cli, "compose", composePackages, []string{"compose", "version", "--short"})
	errs = append(errs, perr)
	d.Buildx, perr = in.plugin(ctx, r, cli, "buildx", buildxPackages, []string{"buildx", "version"})
	errs = append(errs, perr)
	if d.ServerVersion != "" {
		errs = append(errs, in.containers(ctx, r, cli))
	}
	return errors.Join(errs...)
}

// plugin reports a CLI plugin that answers its version command, with the package that ships it. The CLI runs the first
// plugin file it finds; when any candidate file is not root-owned the command is not run and the plugin is reported
// without a version.
func (in *Inspector) plugin(ctx context.Context, r *Report, cli, name string, pkgs []string, args []string) (*Plugin, error) {
	p := &Plugin{}
	for _, n := range pkgs {
		if r.pkg(n) != nil {
			p.Package = n
			break
		}
	}
	for _, dir := range pluginDirs {
		f := dir + "/docker-" + name
		if !in.exists(f) {
			continue
		}
		if p.Path == "" {
			p.Path = f
		}
		if _, err := in.safe(f); err != nil {
			p.Path = f
			return p, err
		}
	}
	res, err := in.run(ctx, cli, args...)
	if err != nil || res.ExitCode != 0 {
		return nil, nil
	}
	p.Version = PluginVersion(string(res.Stdout))
	return p, nil
}

// PluginVersion extracts the version from `docker compose version --short` ("2.29.7") or `docker buildx version`
// ("github.com/docker/buildx v0.17.1 257815a").
func PluginVersion(out string) string {
	f := strings.Fields(strings.TrimSpace(out))
	switch {
	case len(f) == 0:
		return ""
	case len(f) >= 2 && strings.Contains(f[0], "/"):
		return strings.TrimPrefix(f[1], "v")
	}
	return strings.TrimPrefix(f[0], "v")
}

// rootless reports a rootless Docker set up for root or a user under /home (its systemd user unit).
func (in *Inspector) rootless() bool {
	homes := []string{"/root"}
	ents, _ := os.ReadDir(in.d.FS.P("/home"))
	for _, e := range ents {
		if e.IsDir() {
			homes = append(homes, filepath.Join("/home", e.Name()))
		}
	}
	for _, h := range homes {
		if in.exists(h + "/.config/systemd/user/docker.service") {
			return true
		}
	}
	return false
}

// ParseDaemonJSON reads the settings Falak cares about from /etc/docker/daemon.json.
func ParseDaemonJSON(b []byte) *DaemonConfig {
	var raw struct {
		BIP                 string            `json:"bip"`
		DefaultAddressPools []json.RawMessage `json:"default-address-pools"`
		IPTables            *bool             `json:"iptables"`
		UsernsRemap         string            `json:"userns-remap"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return &DaemonConfig{Error: truncate(err.Error(), 200)}
	}
	return &DaemonConfig{BIP: raw.BIP, DefaultAddressPools: raw.DefaultAddressPools, IPTables: raw.IPTables, UsernsRemap: raw.UsernsRemap}
}
