package builder

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// Step is one build command, run in the app root without a shell (unless Shell is set).
type Step struct {
	Name     string   `json:"name"`
	Cmd      []string `json:"cmd"`
	Env      []string `json:"env,omitempty"`
	PreClean []string `json:"pre_clean,omitempty"` // paths (relative to the app root) removed before running
}

// Plan is the detected build plan for an app root.
type Plan struct {
	Provider       string   `json:"provider"`            // laravel|php|node|bun|deno|static
	Framework      string   `json:"framework,omitempty"` // laravel, next, nuxt, vite, astro, ...
	Runtime        string   `json:"runtime"`             // php|node|bun|deno|static
	PHPVersion     string   `json:"php_version,omitempty"`
	NodeVersion    string   `json:"node_version,omitempty"`
	BunVersion     string   `json:"bun_version,omitempty"`
	DenoVersion    string   `json:"deno_version,omitempty"`
	PackageManager string   `json:"package_manager,omitempty"` // npm|pnpm|yarn|bun
	Steps          []Step   `json:"steps"`
	StartCommand   string   `json:"start_command,omitempty"`
	Entrypoint     string   `json:"entrypoint,omitempty"`
	OutputDir      string   `json:"output_dir,omitempty"` // packaged dir relative to the app root ("" = root)
	Excludes       []string `json:"excludes,omitempty"`
	Port           int      `json:"port,omitempty"` // container port for generated Dockerfiles
	DetectedBy     string   `json:"detected_by"`    // builtin | railpack
	Notes          []string `json:"notes,omitempty"`
}

// ErrUndetected is returned when no supported stack is found.
var ErrUndetected = errors.New("could not detect a supported stack (php, node, bun, deno, static)")

type packageJSON struct {
	Name            string            `json:"name"`
	Main            string            `json:"main"`
	Module          string            `json:"module"`
	PackageManager  string            `json:"packageManager"`
	Scripts         map[string]string `json:"scripts"`
	Dependencies    map[string]string `json:"dependencies"`
	DevDependencies map[string]string `json:"devDependencies"`
	Engines         map[string]string `json:"engines"`
}

func (p *packageJSON) has(dep string) bool {
	if p == nil {
		return false
	}
	_, a := p.Dependencies[dep]
	_, b := p.DevDependencies[dep]
	return a || b
}

func (p *packageJSON) hasPrefix(prefix string) bool {
	if p == nil {
		return false
	}
	for _, m := range []map[string]string{p.Dependencies, p.DevDependencies} {
		for k := range m {
			if strings.HasPrefix(k, prefix) {
				return true
			}
		}
	}
	return false
}

type composerJSON struct {
	Require map[string]string `json:"require"`
	Config  struct {
		Platform map[string]string `json:"platform"`
	} `json:"config"`
}

// Detect inspects dir with the built-in detector. hint (job.runtime) may steer ambiguous cases.
func Detect(dir, hint string) (Plan, error) {
	ex := func(name string) bool { _, err := os.Stat(filepath.Join(dir, name)); return err == nil }
	var pkg *packageJSON
	if b, err := os.ReadFile(filepath.Join(dir, "package.json")); err == nil {
		pkg = &packageJSON{}
		if err := json.Unmarshal(b, pkg); err != nil {
			return Plan{}, fmt.Errorf("package.json: %w", err)
		}
	}
	switch {
	case ex("composer.json"):
		return detectPHP(dir, pkg, ex)
	case ex("deno.json") || ex("deno.jsonc"):
		return detectDeno(dir, ex)
	case pkg != nil:
		return detectNode(dir, pkg, ex, hint)
	case ex("index.html"):
		return Plan{Provider: "static", Runtime: "static", DetectedBy: "builtin", Port: 8080, Steps: []Step{}}, nil
	case ex("public/index.html"):
		return Plan{Provider: "static", Runtime: "static", OutputDir: "public", DetectedBy: "builtin", Port: 8080, Steps: []Step{}}, nil
	}
	return Plan{}, ErrUndetected
}

func detectPHP(dir string, pkg *packageJSON, ex func(string) bool) (Plan, error) {
	var c composerJSON
	b, err := os.ReadFile(filepath.Join(dir, "composer.json"))
	if err != nil {
		return Plan{}, err
	}
	if err := json.Unmarshal(b, &c); err != nil {
		return Plan{}, fmt.Errorf("composer.json: %w", err)
	}
	p := Plan{Provider: "php", Runtime: "php", DetectedBy: "builtin", Port: 8080, Entrypoint: "index.php"}
	if ex("public/index.php") {
		p.Entrypoint = "public/index.php"
	}
	if _, ok := c.Require["laravel/framework"]; ok && ex("artisan") {
		p.Provider, p.Framework = "laravel", "laravel"
	}
	p.PHPVersion = minorVersion(c.Config.Platform["php"])
	if p.PHPVersion == "" {
		p.PHPVersion = minorVersion(c.Require["php"])
	}
	p.Steps = append(p.Steps, Step{Name: "composer install", Cmd: []string{"composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--prefer-dist", "--no-progress"}})
	if pkg != nil && pkg.Scripts["build"] != "" {
		pm := packageManager(pkg, ex)
		p.PackageManager = pm
		p.NodeVersion = nodeVersion(dir, pkg)
		p.Steps = append(p.Steps, installStep(pm, ex), Step{Name: "build assets", Cmd: runScript(pm, "build"), Env: []string{"NODE_ENV=production"}})
	}
	// Assets are compiled into public/; node_modules is not needed at runtime for PHP.
	p.Excludes = []string{"/node_modules"}
	return p, nil
}

var frameworks = []struct{ dep, name string }{
	{"next", "next"}, {"nuxt", "nuxt"}, {"@remix-run/node", "remix"}, {"@sveltejs/kit", "sveltekit"},
	{"astro", "astro"}, {"@nestjs/core", "nestjs"}, {"hono", "hono"}, {"express", "express"}, {"fastify", "fastify"},
	{"elysia", "elysia"}, {"react-scripts", "cra"}, {"vite", "vite"},
}

// staticOutput lists frameworks that produce a static site by default, and their output dir.
var staticOutput = map[string]string{"vite": "dist", "cra": "build", "astro": "dist"}

func detectNode(dir string, pkg *packageJSON, ex func(string) bool, hint string) (Plan, error) {
	pm := packageManager(pkg, ex)
	p := Plan{Provider: "node", Runtime: "node", PackageManager: pm, DetectedBy: "builtin", Port: 3000}
	if pm == "bun" || hint == "bun" {
		p.Provider, p.Runtime = "bun", "bun"
		p.BunVersion = bunVersion(pkg)
	} else {
		p.NodeVersion = nodeVersion(dir, pkg)
	}
	for _, f := range frameworks {
		if pkg.has(f.dep) {
			p.Framework = f.name
			break
		}
	}
	if p.Framework == "astro" && pkg.hasPrefix("@astrojs/node") {
		p.Framework = "astro-ssr"
	}
	p.Steps = append(p.Steps, installStep(pm, ex))
	if pkg.Scripts["build"] != "" {
		p.Steps = append(p.Steps, Step{Name: "build", Cmd: runScript(pm, "build"), Env: []string{"NODE_ENV=production"}})
	}
	out, isStatic := staticOutput[p.Framework]
	if hint == "static" && !isStatic {
		out, isStatic = firstExisting(dir, "dist", "build", "out", "public"), true
	}
	if isStatic && pkg.Scripts["start"] == "" || hint == "static" {
		p.Provider, p.Runtime, p.OutputDir, p.Port = "static", "static", out, 8080
		p.NodeVersion, p.BunVersion = nodeVersion(dir, pkg), ""
		return p, nil
	}
	p.Steps = append(p.Steps, pruneStep(pm, ex)...)
	p.StartCommand, p.Entrypoint = startCommand(pkg, pm, p.Framework, ex)
	if p.StartCommand == "" {
		p.Notes = append(p.Notes, "no start command detected; set one on the site")
	}
	return p, nil
}

func detectDeno(dir string, ex func(string) bool) (Plan, error) {
	name := "deno.json"
	if !ex(name) {
		name = "deno.jsonc"
	}
	b, err := os.ReadFile(filepath.Join(dir, name))
	if err != nil {
		return Plan{}, err
	}
	var cfg struct {
		Tasks map[string]string `json:"tasks"`
	}
	if err := json.Unmarshal(stripJSONC(b), &cfg); err != nil {
		return Plan{}, fmt.Errorf("%s: %w", name, err)
	}
	p := Plan{Provider: "deno", Runtime: "deno", DetectedBy: "builtin", Port: 8000}
	if ex("deno.lock") {
		p.Steps = append(p.Steps, Step{Name: "deno install", Cmd: []string{"deno", "install", "--frozen"}})
	} else {
		p.Steps = append(p.Steps, Step{Name: "deno install", Cmd: []string{"deno", "install"}})
	}
	if cfg.Tasks["build"] != "" {
		p.Steps = append(p.Steps, Step{Name: "build", Cmd: []string{"deno", "task", "build"}})
	}
	switch {
	case cfg.Tasks["start"] != "":
		p.StartCommand = "deno task start"
	default:
		for _, f := range []string{"main.ts", "server.ts", "mod.ts", "main.js"} {
			if ex(f) {
				p.StartCommand, p.Entrypoint = "deno run -A "+f, f
				break
			}
		}
	}
	return p, nil
}

func packageManager(pkg *packageJSON, ex func(string) bool) string {
	if pkg != nil && pkg.PackageManager != "" {
		name, _, _ := strings.Cut(pkg.PackageManager, "@")
		switch name {
		case "npm", "pnpm", "yarn", "bun":
			return name
		}
	}
	switch {
	case ex("bun.lock") || ex("bun.lockb"):
		return "bun"
	case ex("pnpm-lock.yaml"):
		return "pnpm"
	case ex("yarn.lock"):
		return "yarn"
	}
	return "npm"
}

func installStep(pm string, ex func(string) bool) Step {
	s := Step{Name: pm + " install"}
	switch pm {
	case "bun":
		s.Cmd = []string{"bun", "install"}
		if ex("bun.lock") || ex("bun.lockb") {
			s.Cmd = append(s.Cmd, "--frozen-lockfile")
		}
	case "pnpm":
		s.Cmd = []string{"pnpm", "install", "--frozen-lockfile"}
	case "yarn":
		if ex(".yarnrc.yml") {
			s.Cmd = []string{"yarn", "install", "--immutable"}
		} else {
			s.Cmd = []string{"yarn", "install", "--frozen-lockfile"}
		}
	default:
		if ex("package-lock.json") || ex("npm-shrinkwrap.json") {
			s.Cmd = []string{"npm", "ci", "--include=dev"}
		} else {
			s.Cmd = []string{"npm", "install", "--include=dev"}
		}
	}
	return s
}

func pruneStep(pm string, ex func(string) bool) []Step {
	switch pm {
	case "bun":
		return []Step{{Name: "prune dev dependencies", Cmd: []string{"bun", "install", "--production"}, PreClean: []string{"node_modules"}}}
	case "pnpm":
		return []Step{{Name: "prune dev dependencies", Cmd: []string{"pnpm", "prune", "--prod"}}}
	case "yarn":
		if ex(".yarnrc.yml") {
			return nil // berry: `workspaces focus --production` needs a plugin; keep all deps
		}
		return []Step{{Name: "prune dev dependencies", Cmd: []string{"yarn", "install", "--production", "--frozen-lockfile", "--ignore-scripts", "--prefer-offline"}}}
	default:
		return []Step{{Name: "prune dev dependencies", Cmd: []string{"npm", "prune", "--omit=dev"}}}
	}
}

func runScript(pm, script string) []string {
	if pm == "npm" {
		return []string{"npm", "run", script}
	}
	return []string{pm, "run", script}
}

func startCommand(pkg *packageJSON, pm, framework string, ex func(string) bool) (cmd, entry string) {
	bin := "node"
	if pm == "bun" {
		bin = "bun"
	}
	if pkg.Scripts["start"] != "" {
		return strings.Join(runScript(pm, "start"), " "), ""
	}
	switch framework {
	case "next":
		return "npx next start", ""
	case "nuxt":
		return bin + " .output/server/index.mjs", ".output/server/index.mjs"
	}
	for _, m := range []string{pkg.Main, pkg.Module} {
		if m != "" && (bin == "bun" || !isTS(m)) {
			return bin + " " + m, m
		}
	}
	cands := []string{"server.js", "index.js", "app.js", "main.js", "dist/index.js", "dist/main.js"}
	if bin == "bun" {
		cands = append([]string{"index.ts", "src/index.ts", "server.ts"}, cands...)
	}
	for _, f := range cands {
		if ex(f) {
			return bin + " " + f, f
		}
	}
	return "", ""
}

func isTS(f string) bool { return strings.HasSuffix(f, ".ts") || strings.HasSuffix(f, ".tsx") }

var verRe = regexp.MustCompile(`(\d+)(?:\.(\d+))?`)

// minorVersion extracts "8.3" from constraints like "^8.3", ">=8.3 <9", "~8.3.0", "8.3.*".
func minorVersion(constraint string) string {
	m := verRe.FindStringSubmatch(constraint)
	if m == nil {
		return ""
	}
	if m[2] == "" {
		return m[1]
	}
	return m[1] + "." + m[2]
}

// majorVersion extracts "22" from "v22.3.0", ">=20", "lts/*" → "".
func majorVersion(s string) string {
	m := verRe.FindStringSubmatch(s)
	if m == nil {
		return ""
	}
	return m[1]
}

func nodeVersion(dir string, pkg *packageJSON) string {
	for _, f := range []string{".nvmrc", ".node-version"} {
		if b, err := os.ReadFile(filepath.Join(dir, f)); err == nil {
			if v := majorVersion(strings.TrimSpace(string(b))); v != "" {
				return v
			}
		}
	}
	if b, err := os.ReadFile(filepath.Join(dir, ".tool-versions")); err == nil {
		for _, line := range strings.Split(string(b), "\n") {
			if f := strings.Fields(line); len(f) == 2 && f[0] == "nodejs" {
				return majorVersion(f[1])
			}
		}
	}
	if pkg != nil {
		return majorVersion(pkg.Engines["node"])
	}
	return ""
}

func bunVersion(pkg *packageJSON) string {
	if name, v, ok := strings.Cut(pkg.PackageManager, "@"); ok && name == "bun" {
		return v
	}
	return minorVersion(pkg.Engines["bun"])
}

func firstExisting(dir string, names ...string) string {
	for _, n := range names {
		if st, err := os.Stat(filepath.Join(dir, n)); err == nil && st.IsDir() {
			return n
		}
	}
	return names[0]
}

// stripJSONC removes // and /* */ comments and trailing commas outside strings.
func stripJSONC(b []byte) []byte {
	var out bytes.Buffer
	inStr, esc := false, false
	for i := 0; i < len(b); i++ {
		c := b[i]
		if inStr {
			out.WriteByte(c)
			switch {
			case esc:
				esc = false
			case c == '\\':
				esc = true
			case c == '"':
				inStr = false
			}
			continue
		}
		switch {
		case c == '"':
			inStr = true
			out.WriteByte(c)
		case c == '/' && i+1 < len(b) && b[i+1] == '/':
			for i < len(b) && b[i] != '\n' {
				i++
			}
			out.WriteByte('\n')
		case c == '/' && i+1 < len(b) && b[i+1] == '*':
			i += 2
			for i+1 < len(b) && (b[i] != '*' || b[i+1] != '/') {
				i++
			}
			i++
		default:
			out.WriteByte(c)
		}
	}
	return regexp.MustCompile(`,(\s*[}\]])`).ReplaceAll(out.Bytes(), []byte("$1"))
}

// railpackInfo is the subset of `railpack info --format json` we use. Unknown/missing fields are tolerated.
type railpackInfo struct {
	DetectedProviders []string `json:"detectedProviders"`
	ResolvedPackages  map[string]struct {
		ResolvedVersion  string `json:"resolvedVersion"`
		RequestedVersion string `json:"requestedVersion"`
		Source           string `json:"source"` // e.g. "composer.json > require > php", "railpack default"
	} `json:"resolvedPackages"`
	Plan struct {
		Deploy struct {
			StartCommand string `json:"startCommand"`
		} `json:"deploy"`
	} `json:"plan"`
}

// mergeRailpack enriches a builtin plan with Railpack's detection (versions, start command).
func mergeRailpack(p *Plan, info railpackInfo) {
	p.DetectedBy = "railpack"
	// v returns Railpack's resolved version, unless it is only Railpack's default and the builtin
	// detector already found an explicit one (have != "").
	v := func(name, have string) string {
		if r, ok := info.ResolvedPackages[name]; ok {
			if have != "" && strings.Contains(r.Source, "default") {
				return ""
			}
			if r.ResolvedVersion != "" {
				return r.ResolvedVersion
			}
			return r.RequestedVersion
		}
		return ""
	}
	if s := v("php", p.PHPVersion); s != "" && p.Runtime == "php" {
		p.PHPVersion = minorVersion(s)
	}
	if s := v("node", p.NodeVersion); s != "" && p.PackageManager != "" && p.Runtime != "bun" {
		p.NodeVersion = majorVersion(s)
	}
	if s := v("bun", p.BunVersion); s != "" && p.Runtime == "bun" {
		p.BunVersion = s
	}
	if s := v("deno", p.DenoVersion); s != "" && p.Runtime == "deno" {
		p.DenoVersion = s
	}
	if s := info.Plan.Deploy.StartCommand; s != "" && p.Runtime != "php" && p.Runtime != "static" {
		p.StartCommand = s
	}
}
