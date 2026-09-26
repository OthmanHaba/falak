package builder

import (
	"context"
	"encoding/json"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

func stepLines(p Plan) []string {
	var out []string
	for _, s := range p.Steps {
		out = append(out, strings.Join(s.Cmd, " "))
	}
	return out
}

func TestDetectFixtures(t *testing.T) {
	cases := []struct {
		fixture, hint string
		want          Plan
	}{
		{"laravel", "", Plan{
			Provider: "laravel", Framework: "laravel", Runtime: "php", PHPVersion: "8.3", PackageManager: "npm",
			Entrypoint: "public/index.php", Excludes: []string{"/node_modules"}, Port: 8080, DetectedBy: "builtin",
			Steps: []Step{
				{Name: "composer install", Cmd: []string{"composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--prefer-dist", "--no-progress"}},
				{Name: "npm install", Cmd: []string{"npm", "ci", "--include=dev"}},
				{Name: "build assets", Cmd: []string{"npm", "run", "build"}, Env: []string{"NODE_ENV=production"}},
			},
		}},
		{"next", "", Plan{
			Provider: "node", Framework: "next", Runtime: "node", NodeVersion: "22", PackageManager: "pnpm",
			StartCommand: "pnpm run start", Port: 3000, DetectedBy: "builtin",
			Steps: []Step{
				{Name: "pnpm install", Cmd: []string{"pnpm", "install", "--frozen-lockfile"}},
				{Name: "build", Cmd: []string{"pnpm", "run", "build"}, Env: []string{"NODE_ENV=production"}},
				{Name: "prune dev dependencies", Cmd: []string{"pnpm", "prune", "--prod"}},
			},
		}},
		{"bun", "", Plan{
			Provider: "bun", Framework: "hono", Runtime: "bun", PackageManager: "bun",
			StartCommand: "bun index.ts", Entrypoint: "index.ts", Port: 3000, DetectedBy: "builtin",
			Steps: []Step{
				{Name: "bun install", Cmd: []string{"bun", "install", "--frozen-lockfile"}},
				{Name: "prune dev dependencies", Cmd: []string{"bun", "install", "--production"}, PreClean: []string{"node_modules"}},
			},
		}},
		{"static", "", Plan{Provider: "static", Runtime: "static", Port: 8080, DetectedBy: "builtin", Steps: []Step{}}},
		{"vite-static", "", Plan{
			Provider: "static", Framework: "vite", Runtime: "static", NodeVersion: "20", PackageManager: "yarn",
			OutputDir: "dist", Port: 8080, DetectedBy: "builtin",
			Steps: []Step{
				{Name: "yarn install", Cmd: []string{"yarn", "install", "--frozen-lockfile"}},
				{Name: "build", Cmd: []string{"yarn", "run", "build"}, Env: []string{"NODE_ENV=production"}},
			},
		}},
		{"deno", "", Plan{
			Provider: "deno", Runtime: "deno", StartCommand: "deno task start", Port: 8000, DetectedBy: "builtin",
			Steps: []Step{
				{Name: "deno install", Cmd: []string{"deno", "install"}},
				{Name: "build", Cmd: []string{"deno", "task", "build"}},
			},
		}},
	}
	for _, tc := range cases {
		t.Run(tc.fixture, func(t *testing.T) {
			got, err := Detect(filepath.Join(fixtures, tc.fixture), tc.hint)
			if err != nil {
				t.Fatal(err)
			}
			if !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("plan mismatch\n got: %+v\nwant: %+v", got, tc.want)
			}
		})
	}
}

func TestDetectUndetected(t *testing.T) {
	if _, err := Detect(t.TempDir(), ""); err != ErrUndetected {
		t.Fatalf("err = %v", err)
	}
}

func TestDetectBunHintForcesBunRuntime(t *testing.T) {
	p, err := Detect(filepath.Join(fixtures, "next"), "bun")
	if err != nil || p.Runtime != "bun" {
		t.Fatalf("plan=%+v err=%v", p, err)
	}
}

func TestVersionParsing(t *testing.T) {
	for in, want := range map[string]string{"^8.3": "8.3", ">=8.2 <9.0": "8.2", "~8.4.0": "8.4", "8.*": "8", "": ""} {
		if got := minorVersion(in); got != want {
			t.Errorf("minorVersion(%q) = %q want %q", in, got, want)
		}
	}
	for in, want := range map[string]string{"v22.11.0": "22", ">=20": "20", "lts/*": ""} {
		if got := majorVersion(in); got != want {
			t.Errorf("majorVersion(%q) = %q want %q", in, got, want)
		}
	}
}

func TestRailpackEnrichesPlan(t *testing.T) {
	f := &runnertest.Fake{}
	f.On("railpack info", runner.Result{Stdout: []byte(`{"detectedProviders":["php","node"],"resolvedPackages":{"php":{"requestedVersion":"8.3","resolvedVersion":"8.3.14"},"node":{"resolvedVersion":"22.11.0"}},"plan":{"deploy":{"startCommand":"frankenphp run"}}}`)})
	b := &Builder{Runner: f, LookPath: func(string) (string, error) { return "/usr/bin/railpack", nil }}
	p, err := b.Plan(context.Background(), filepath.Join(fixtures, "laravel"), "", nil, &strings.Builder{})
	if err != nil {
		t.Fatal(err)
	}
	if p.DetectedBy != "railpack" || p.PHPVersion != "8.3" || p.NodeVersion != "22" || p.StartCommand != "" {
		t.Fatalf("plan = %+v", p)
	}
	if !f.Ran("railpack info --format json ") {
		t.Fatalf("calls: %v", f.Lines())
	}
	// Explicitly disabled → never invoked.
	f.Reset()
	off := false
	if _, err := b.Plan(context.Background(), filepath.Join(fixtures, "laravel"), "", &off, &strings.Builder{}); err != nil || len(f.Lines()) != 0 {
		t.Fatalf("railpack ran while disabled: %v", f.Lines())
	}
}

func TestRailpackUnsupportedProviderForNative(t *testing.T) {
	f := &runnertest.Fake{}
	f.On("railpack info", runner.Result{Stdout: []byte(`{"detectedProviders":["python"]}`)})
	b := &Builder{Runner: f, LookPath: func(string) (string, error) { return "/usr/bin/railpack", nil }}
	_, err := b.Plan(context.Background(), t.TempDir(), "", nil, &strings.Builder{})
	if err == nil || !strings.Contains(err.Error(), "python") || !strings.Contains(err.Error(), "docker") {
		t.Fatalf("err = %v", err)
	}
}

func TestStripJSONC(t *testing.T) {
	got := string(stripJSONC([]byte("{\n // c\n \"a\": \"x//y\", /* b */ \"b\": [1,2,],\n}")))
	if strings.Contains(got, "//") && !strings.Contains(got, "x//y") || strings.Contains(got, ",]") {
		t.Fatalf("got %q", got)
	}
}

func TestRailpackDefaultDoesNotOverrideExplicitVersion(t *testing.T) {
	p := Plan{Runtime: "node", PackageManager: "pnpm", NodeVersion: "22"}
	var info railpackInfo
	_ = json.Unmarshal([]byte(`{"resolvedPackages":{"node":{"requestedVersion":"20","resolvedVersion":"20.1.0","source":"railpack default"}}}`), &info)
	mergeRailpack(&p, info)
	if p.NodeVersion != "22" {
		t.Fatalf("node = %s", p.NodeVersion)
	}
}

// TestRailpackRealCLI runs against the installed railpack when present (skipped otherwise).
func TestRailpackRealCLI(t *testing.T) {
	if _, err := exec.LookPath("railpack"); err != nil {
		t.Skip("railpack not installed")
	}
	b := &Builder{}
	p, err := b.Plan(context.Background(), filepath.Join(fixtures, "bun"), "", nil, &strings.Builder{})
	if err != nil {
		t.Fatal(err)
	}
	if p.DetectedBy != "railpack" || p.Runtime != "bun" || p.StartCommand == "" || p.BunVersion == "" {
		t.Fatalf("plan = %+v", p)
	}
}
