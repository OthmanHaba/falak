// Package runtime implements runtime.* commands: PHP (ondrej PPA, or the distribution's archive where the PPA
// does not publish the release), php.ini overrides, Node.js from
// official tarballs, the static FrankenPHP binary (+ falak-edge.service) and PHP-FPM pools.
package runtime

import (
	"cmp"
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"regexp"
	goruntime "runtime"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/facts"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Download is the shared HTTPS download + sha256 verify helper.
var Download = system.Download

// Deps are the collaborators of runtime executors.
type Deps struct {
	Runner         runner.Runner
	FS             hostfs.FS
	Logger         *slog.Logger
	HTTP           *http.Client
	Arch           string // default runtime.GOARCH
	FrankenPHPBase string // default https://github.com/php/frankenphp/releases/download
	EdgeUser       string // user running falak-edge.service and owning FPM socket group; default "caddy"
	OndrejPPAURL   string // default https://ppa.launchpadcontent.net/ondrej/php/ubuntu (probed for the release)
}

// Runtime holds the executors.
type Runtime struct{ d Deps }

// New builds runtime executors.
func New(d Deps) *Runtime {
	if d.Logger == nil {
		d.Logger = slog.Default()
	}
	if d.HTTP == nil {
		d.HTTP = http.DefaultClient
	}
	if d.Arch == "" {
		d.Arch = goruntime.GOARCH
	}
	if d.FrankenPHPBase == "" {
		d.FrankenPHPBase = "https://github.com/php/frankenphp/releases/download"
	}
	if d.EdgeUser == "" {
		d.EdgeUser = "caddy"
	}
	if d.OndrejPPAURL == "" {
		d.OndrejPPAURL = "https://ppa.launchpadcontent.net/ondrej/php/ubuntu"
	}
	return &Runtime{d: d}
}

// Register adds runtime.* executors.
func (rt *Runtime) Register(reg *commands.Registry) {
	reg.Register("runtime.php.install", commands.Typed(rt.PHPInstall))
	reg.Register("runtime.php.configure", commands.Typed(rt.PHPConfigure))
	reg.Register("runtime.node.install", commands.Typed(rt.NodeInstall))
	reg.Register("runtime.bun.install", commands.Typed(rt.BunInstall))
	reg.Register("runtime.deno.install", commands.Typed(rt.DenoInstall))
	reg.Register("runtime.frankenphp.configure", commands.Typed(rt.FrankenPHPConfigure))
	reg.Register("runtime.fpm.pool", commands.Typed(rt.FPMPool))
}

func (rt *Runtime) run(ctx context.Context, st commands.Stream, name string, args ...string) error {
	_, err := runner.Check(ctx, rt.d.Runner, runner.Cmd{Name: name, Args: args, Stdout: st.Stdout(), Stderr: st.Stderr()})
	return err
}

// ---- runtime.php.install ----

// PHPInstallPayload is runtime.php.install.
type PHPInstallPayload struct {
	Version    string   `json:"version"`
	Extensions []string `json:"extensions"`
	FPM        *bool    `json:"fpm"`
	CLIDefault bool     `json:"cli_default"`
}

// PHPInstallResult is its result.
type PHPInstallResult struct {
	Changed    bool   `json:"changed"`
	Binary     string `json:"binary"`
	FPMService string `json:"fpm_service,omitempty"`
}

// Extensions shipped in php<v>-common or compiled in (no separate package).
var commonExt = map[string]bool{
	"calendar": true, "ctype": true, "exif": true, "ffi": true, "fileinfo": true, "ftp": true, "gettext": true,
	"iconv": true, "pdo": true, "phar": true, "posix": true, "shmop": true, "sockets": true, "sysvmsg": true,
	"sysvsem": true, "sysvshm": true, "tokenizer": true, "json": true, "openssl": true, "pcre": true,
	"session": true, "zlib": true, "filter": true, "hash": true, "sodium": true,
}

var extAlias = map[string]string{"pdo_mysql": "mysql", "mysqli": "mysql", "pdo_pgsql": "pgsql", "pdo_sqlite": "sqlite3", "gd2": "gd"}

// PHPPackages lists apt packages for a PHP version.
func PHPPackages(v string, exts []string, fpm bool) []string {
	pkgs := []string{"php" + v + "-cli", "php" + v + "-common"}
	if fpm {
		pkgs = append(pkgs, "php"+v+"-fpm")
	}
	seen := map[string]bool{}
	for _, p := range pkgs {
		seen[p] = true
	}
	for _, e := range exts {
		if commonExt[e] {
			continue
		}
		if a, ok := extAlias[e]; ok {
			e = a
		}
		p := "php" + v + "-" + e
		if !seen[p] {
			seen[p] = true
			pkgs = append(pkgs, p)
		}
	}
	return pkgs
}

// OndrejPPAPresent reports whether an active apt source (.list/.sources) already points at ppa:ondrej/php.
func OndrejPPAPresent(fs hostfs.FS) bool {
	ents, _ := os.ReadDir(fs.P(system.AptSourcesDir))
	for _, e := range ents {
		n := e.Name()
		if !strings.HasSuffix(n, ".list") && !strings.HasSuffix(n, ".sources") {
			continue // e.g. <file>.disabled-by-falak
		}
		if b, err := fs.ReadFile(system.AptSourcesDir + "/" + n); err == nil && strings.Contains(string(b), "ondrej/php") {
			return true
		}
	}
	return false
}

// EnsureOndrejPPA adds ppa:ondrej/php once. Returns whether it was added.
func EnsureOndrejPPA(ctx context.Context, r runner.Runner, fs hostfs.FS, st commands.Stream) (bool, error) {
	if OndrejPPAPresent(fs) {
		return false, nil
	}
	apt := system.AptFor(r, fs, st)
	if _, err := apt.Ensure(ctx, []string{"software-properties-common", "ca-certificates"}, true); err != nil {
		return false, err
	}
	_, err := runner.Check(ctx, r, runner.Cmd{Name: "add-apt-repository", Args: []string{"-y", "ppa:ondrej/php"}, Env: system.AptEnv, Stdout: st.Stdout(), Stderr: st.Stderr()})
	return err == nil, err
}

// ondrejServes reports whether ppa:ondrej/php publishes the release codename (GET dists/<codename>/Release is
// 200). It is false only for a definite answer from the PPA: a network error leaves the decision to apt.
func (rt *Runtime) ondrejServes(ctx context.Context, codename string) (bool, error) {
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimRight(rt.d.OndrejPPAURL, "/")+"/dists/"+codename+"/Release", nil)
	if err != nil {
		return false, err
	}
	resp, err := rt.d.HTTP.Do(req)
	if err != nil {
		return false, err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<20))
	return resp.StatusCode == http.StatusOK, nil
}

// phpSource makes sure apt has a source for PHP: ppa:ondrej/php when it publishes this release, otherwise the
// distribution's own archive (Ubuntu 26.04 "resolute" before the PPA builds for it: only PHP 8.5). Returns whether
// the PPA was added and a note for the error when PHP must come from the distribution.
func (rt *Runtime) phpSource(ctx context.Context, st commands.Stream) (added bool, note string, err error) {
	if OndrejPPAPresent(rt.d.FS) {
		return false, "", nil
	}
	osr := facts.OSRelease(rt.d.FS)
	if codename := osr["VERSION_CODENAME"]; codename != "" {
		ok, err := rt.ondrejServes(ctx, codename)
		if err == nil && !ok {
			note = fmt.Sprintf("ppa:ondrej/php has no packages for %s %s (%s)", osr["ID"], osr["VERSION_ID"], codename)
			fmt.Fprintf(st.Stdout(), "%s; installing PHP from the distribution's archive\n", note)
			return false, note, nil
		}
		if err != nil {
			fmt.Fprintf(st.Stderr(), "warning: could not check ppa:ondrej/php for %s (%v); adding it anyway\n", codename, err)
		}
	}
	added, err = EnsureOndrejPPA(ctx, rt.d.Runner, rt.d.FS, st)
	return added, "", err
}

var phpCLIPackage = regexp.MustCompile(`^php(\d+\.\d+)-cli\b`)

// availablePHP lists the PHP versions apt can install (php<v>-cli packages), lowest first.
func availablePHP(ctx context.Context, r runner.Runner) []string {
	res, err := r.Run(ctx, runner.Cmd{Name: "apt-cache", Args: []string{"search", "--names-only", `^php[0-9]+\.[0-9]+-cli$`}, Env: system.AptEnv})
	if err != nil {
		return nil
	}
	var out []string
	for _, line := range strings.Split(string(res.Stdout), "\n") {
		if m := phpCLIPackage.FindStringSubmatch(strings.TrimSpace(line)); m != nil && !slices.Contains(out, m[1]) {
			out = append(out, m[1])
		}
	}
	slices.SortFunc(out, func(a, b string) int {
		fa, _ := strconv.ParseFloat(a, 64)
		fb, _ := strconv.ParseFloat(b, 64)
		return cmp.Compare(fa, fb)
	})
	return out
}

func phpUnavailable(version, note string, available []string, osr map[string]string) error {
	release := strings.TrimSpace(osr["ID"] + " " + osr["VERSION_ID"])
	if release == "" {
		release = "this system"
	}
	msg := fmt.Sprintf("PHP %s is not available on %s", version, release)
	if note != "" {
		msg += " (" + note + ")"
	}
	if len(available) > 0 {
		return errors.New(msg + "; PHP versions available here: " + strings.Join(available, ", "))
	}
	return errors.New(msg + "; apt offers no PHP packages here")
}

// PHPInstall installs PHP from ppa:ondrej/php, or from the distribution's archive where the PPA does not publish
// the release. A version neither has fails with the versions this release offers.
func (rt *Runtime) PHPInstall(ctx context.Context, p PHPInstallPayload, st commands.Stream) (any, error) {
	if _, err := strconv.ParseFloat(p.Version, 64); err != nil {
		return nil, &commands.PayloadError{Err: fmt.Errorf("invalid php version %q", p.Version)}
	}
	fpm := p.FPM == nil || *p.FPM
	res := PHPInstallResult{Binary: "/usr/bin/php" + p.Version}
	apt := system.AptFor(rt.d.Runner, rt.d.FS, st)
	pkgs := PHPPackages(p.Version, p.Extensions, fpm)
	miss, err := apt.Missing(ctx, pkgs)
	if err != nil {
		return nil, err
	}
	if len(miss) > 0 {
		added, note, err := rt.phpSource(ctx, st)
		if err != nil {
			return nil, err
		}
		if !added { // add-apt-repository has just updated
			if err := apt.Update(ctx); err != nil {
				return nil, err
			}
		}
		cand, err := apt.Candidate(ctx, "php"+p.Version+"-cli")
		if err != nil {
			return nil, err
		}
		if cand == "" {
			return nil, phpUnavailable(p.Version, note, availablePHP(ctx, rt.d.Runner), facts.OSRelease(rt.d.FS))
		}
		if err := apt.InstallRaw(ctx, miss, "--no-install-recommends"); err != nil {
			return nil, err
		}
		res.Changed = added || len(miss) > 0
	}
	if fpm {
		svc := "php" + p.Version + "-fpm"
		res.FPMService = svc
		r, err := rt.d.Runner.Run(ctx, runner.Cmd{Name: "systemctl", Args: []string{"is-active", "--quiet", svc}})
		if err != nil {
			return nil, err
		}
		if r.ExitCode != 0 {
			if err := rt.run(ctx, st, "systemctl", "enable", "--now", svc); err != nil {
				return nil, err
			}
			res.Changed = true
		}
	}
	if p.CLIDefault {
		r, err := rt.d.Runner.Run(ctx, runner.Cmd{Name: "update-alternatives", Args: []string{"--query", "php"}})
		if err != nil {
			return nil, err
		}
		if !strings.Contains(string(r.Stdout), "Value: "+res.Binary+"\n") {
			if err := rt.run(ctx, st, "update-alternatives", "--set", "php", res.Binary); err != nil {
				return nil, err
			}
			res.Changed = true
		}
	}
	return res, nil
}

// ---- runtime.php.configure ----

// PHPConfigurePayload is runtime.php.configure.
type PHPConfigurePayload struct {
	Version string         `json:"version"`
	SAPI    string         `json:"sapi"`
	INI     map[string]any `json:"ini"`
}

// FilesResult is {changed, files}.
type FilesResult struct {
	Changed bool     `json:"changed"`
	Files   []string `json:"files"`
}

// RenderINI renders ini directives deterministically.
func RenderINI(ini map[string]any) []byte {
	keys := make([]string, 0, len(ini))
	for k := range ini {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var b strings.Builder
	b.WriteString("; Managed by Falak — do not edit\n")
	for _, k := range keys {
		b.WriteString(k + " = " + iniValue(ini[k]) + "\n")
	}
	return []byte(b.String())
}

func iniValue(v any) string {
	switch t := v.(type) {
	case bool:
		if t {
			return "On"
		}
		return "Off"
	case float64:
		return strconv.FormatFloat(t, 'f', -1, 64)
	case int:
		return strconv.Itoa(t)
	case string:
		safe := t != ""
		for _, c := range t {
			if !(c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9' || strings.ContainsRune("_./:-,", c)) {
				safe = false
				break
			}
		}
		if safe {
			return t
		}
		return `"` + strings.ReplaceAll(t, `"`, `\"`) + `"`
	default:
		return fmt.Sprint(t)
	}
}

// PHPConfigure writes conf.d/99-falak.ini for the selected SAPIs.
func (rt *Runtime) PHPConfigure(ctx context.Context, p PHPConfigurePayload, st commands.Stream) (any, error) {
	sapis := []string{"fpm", "cli"}
	if p.SAPI != "" && p.SAPI != "all" {
		sapis = []string{p.SAPI}
	}
	content := RenderINI(p.INI)
	res := FilesResult{Files: []string{}}
	for _, sapi := range sapis {
		dir := "/etc/php/" + p.Version + "/" + sapi
		if !rt.d.FS.Exists(dir) {
			if p.SAPI == sapi {
				return nil, fmt.Errorf("php %s %s is not installed (%s missing)", p.Version, sapi, dir)
			}
			continue
		}
		file := dir + "/conf.d/99-falak.ini"
		old, oldErr := rt.d.FS.ReadFile(file)
		changed, err := rt.d.FS.WriteFile(file, content, 0o644)
		if err != nil {
			return nil, err
		}
		res.Files = append(res.Files, file)
		if !changed {
			continue
		}
		res.Changed = true
		if sapi == "fpm" {
			if err := rt.run(ctx, st, "php-fpm"+p.Version, "-t"); err != nil {
				restore(rt.d.FS, file, old, oldErr)
				return nil, fmt.Errorf("php-fpm config test failed, reverted: %w", err)
			}
			if err := rt.run(ctx, st, "systemctl", "reload", "php"+p.Version+"-fpm"); err != nil {
				return nil, err
			}
		}
	}
	return res, nil
}

func restore(fs hostfs.FS, file string, old []byte, oldErr error) {
	if oldErr != nil {
		fs.Remove(file)
		return
	}
	fs.WriteFile(file, old, 0o644)
}
