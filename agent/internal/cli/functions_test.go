package cli

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/kiln/agent/internal/cli/api"
)

// fakeFunctions serves the /api/v1/functions endpoints, the deployment endpoints `--wait` follows, and the
// function's own URL (for invoke).
type fakeFunctions struct {
	t        *testing.T
	mu       sync.Mutex
	srv      *httptest.Server
	versions []api.FunctionVersion // newest last
	live     int
	deploys  []api.FunctionDeployRequest
	runPolls int
	invoked  *http.Request
	body     string
}

const fnID = "01JFN00000000000000000000A"

func newFakeFunctions(t *testing.T) *fakeFunctions {
	f := &fakeFunctions{t: t, live: 1, versions: []api.FunctionVersion{
		{ID: "01JV1000000000000000000001", Number: 1, Hash: strings.Repeat("a", 64), ShortHash: "aaaaaaa", Author: "Ada", Entrypoint: "index.ts",
			Files: map[string]string{"index.ts": "export default {}\n", "lib/util.ts": "export const x = 1\n"}},
	}}
	f.srv = httptest.NewServer(http.HandlerFunc(f.serve))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeFunctions) head() api.FunctionVersion { return f.versions[len(f.versions)-1] }

func (f *fakeFunctions) function() map[string]any {
	head := f.head()
	live := f.versions[f.live-1]
	live.Files = nil
	return map[string]any{
		"site":       map[string]string{"id": fnID, "name": "Hooks", "slug": "hooks"},
		"runtime":    map[string]string{"key": "bun", "label": "Bun", "language": "typescript"},
		"entrypoint": "index.ts",
		"url":        f.srv.URL + "/fn",
		"head":       head,
		"live":       live,
		"schedules":  []api.FunctionSchedule{{ID: "01JSCH", Key: "abcd1234", Name: "Nightly", Expression: "0 3 * * *", Enabled: true}},
	}
}

func (f *fakeFunctions) json(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func (f *fakeFunctions) serve(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	p := r.URL.Path
	if strings.HasPrefix(p, "/fn") {
		f.invoked = r
		b, _ := io.ReadAll(r.Body)
		f.body = string(b)
		w.Header().Set("X-Function", "hooks")
		if strings.HasSuffix(p, "/boom") {
			w.WriteHeader(500)
			io.WriteString(w, "boom")
			return
		}
		io.WriteString(w, "hello "+r.Method)
		return
	}
	if r.Header.Get("Authorization") != "Bearer "+token {
		f.json(w, 401, map[string]string{"message": "Unauthenticated."})
		return
	}
	switch {
	case p == api.PathFunctions:
		f.json(w, 200, map[string]any{"data": []api.FunctionSummary{{ID: fnID, Name: "Hooks", Slug: "hooks", Runtime: "bun", Entrypoint: "index.ts",
			Live: &api.FunctionRef{Number: 1, ShortHash: "aaaaaaa"}, URL: "https://hooks.example.com"}}})
	case p == api.Path(api.PathFunction, "site", "hooks"):
		f.json(w, 200, map[string]any{"data": f.function()})
	case p == api.Path(api.PathFunctionDeploy, "site", "hooks"):
		var req api.FunctionDeployRequest
		_ = json.NewDecoder(r.Body).Decode(&req)
		f.deploys = append(f.deploys, req)
		head := f.head()
		if req.BaseVersionID != "" && req.BaseVersionID != head.ID && !req.Force {
			f.json(w, 409, map[string]any{"message": "stale", "head": head})
			return
		}
		n := len(f.versions) + 1
		v := api.FunctionVersion{ID: "01JV" + strings.Repeat("0", 21) + string(rune('0'+n)), Number: n, ShortHash: "bbbbbbb", Message: req.Message, Files: req.Files}
		f.versions = append(f.versions, v)
		v.Files = nil
		f.json(w, 201, map[string]any{"data": map[string]any{"version": v, "created": true, "deployment_id": "01JDEP"}})
	case p == api.Path(api.PathFunctionVersions, "site", "hooks"):
		out := []api.FunctionVersion{}
		for i := len(f.versions) - 1; i >= 0; i-- {
			v := f.versions[i]
			v.Files = nil
			out = append(out, v)
		}
		f.json(w, 200, map[string]any{"data": out})
	case p == api.Path(api.PathFunctionVersionDeploy, "site", "hooks", "number", "1"):
		f.live = 1
		f.json(w, 201, map[string]any{"data": map[string]string{"deployment_id": "01JDEP"}})
	case p == api.Path(api.PathFunctionScheduleRun, "site", "hooks", "schedule", "Nightly"):
		f.json(w, 202, map[string]any{"data": map[string]string{"run_id": "01JRUN"}})
	case p == api.Path(api.PathFunctionScheduleRun, "site", "hooks", "schedule", "broken"):
		f.json(w, 202, map[string]any{"data": map[string]string{"run_id": "01JRUNBAD"}})
	case p == api.Path(api.PathFunctionRun, "site", "hooks", "run", "01JRUN"):
		f.runPolls++
		out := map[int]string{1: "kiln: running Nightly (manual)\n", 2: "kiln: running Nightly (manual)\ncleaned 3 rows\n"}[min(f.runPolls, 2)]
		run := api.FunctionRun{Status: "running", Output: out}
		if f.runPolls >= 3 {
			code := 0
			run = api.FunctionRun{Status: "succeeded", Finished: true, ExitCode: &code, Output: out + "kiln: Nightly finished in 12ms\n"}
		}
		f.json(w, 200, map[string]any{"data": run})
	case p == api.Path(api.PathFunctionRun, "site", "hooks", "run", "01JRUNBAD"):
		code := 1
		f.json(w, 200, map[string]any{"data": api.FunctionRun{Status: "failed", Finished: true, ExitCode: &code, Output: "Error: nope\n", Error: "the run exited with code 1"}})
	case p == api.Path(api.PathDeployment, "deployment", "01JDEP"):
		f.json(w, 200, map[string]any{"data": api.Deployment{ID: "01JDEP", Status: "succeeded"}})
	case p == api.Path(api.PathDeploymentOutput, "deployment", "01JDEP"):
		f.json(w, 200, map[string]any{"data": []api.OutputLine{{Seq: 1, Data: "release is live (booted in 210ms)"}}})
	default:
		f.json(w, 404, map[string]string{"message": "Not Found"})
	}
}

func fnHarness(t *testing.T) (*harness, *fakeFunctions) {
	f := newFakeFunctions(t)
	h := newHarness(t, nil)
	h.env["KILN_URL"] = f.srv.URL
	h.env["KILN_TOKEN"] = token
	return h, f
}

func TestFnListAndVersions(t *testing.T) {
	h, _ := fnHarness(t)
	if code := h.run("fn"); code != 0 || !strings.Contains(h.out.String(), "hooks") || !strings.Contains(h.out.String(), "v1 (aaaaaaa)") {
		t.Fatalf("fn: %d %s %s", code, h.out.String(), h.err.String())
	}
	if code := h.run("fn", "versions", "hooks"); code != 0 || !strings.Contains(h.out.String(), "v1 (live)") || !strings.Contains(h.out.String(), "Ada") {
		t.Fatalf("versions: %d %s %s", code, h.out.String(), h.err.String())
	}
}

func TestFnPullEditDeployAndConflict(t *testing.T) {
	h, f := fnHarness(t)
	dir := filepath.Join(t.TempDir(), "hooks")
	if code := h.run("fn", "pull", "hooks", dir); code != 0 {
		t.Fatalf("pull: %d %s", code, h.err.String())
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "lib", "util.ts")); string(b) != "export const x = 1\n" {
		t.Fatalf("pulled lib/util.ts = %q", b)
	}
	meta, ok, _ := readFunctionMeta(dir)
	if !ok || meta.SiteID != fnID || meta.BaseVersionID != f.versions[0].ID || len(meta.Files) != 2 {
		t.Fatalf("meta %+v", meta)
	}

	// Edit, add a module in a new folder; dependencies, VCS, dot-files and .kilnignore'd files are not sent.
	os.WriteFile(filepath.Join(dir, "index.ts"), []byte("export default { fetch: () => new Response('v2') }\n"), 0o644)
	os.MkdirAll(filepath.Join(dir, "routes", "admin"), 0o755)
	os.WriteFile(filepath.Join(dir, "routes", "admin", "users.ts"), []byte("export const users = []\n"), 0o644)
	for _, junk := range []string{"node_modules/hono/index.js", ".git/HEAD", ".env", "notes.log", "dist/out.js"} {
		os.MkdirAll(filepath.Dir(filepath.Join(dir, junk)), 0o755)
		os.WriteFile(filepath.Join(dir, junk), []byte("x"), 0o644)
	}
	os.WriteFile(filepath.Join(dir, ".kilnignore"), []byte("# local only\n*.log\ndist/\n"), 0o644)
	outside := filepath.Join(t.TempDir(), "secret.ts")
	os.WriteFile(outside, []byte("secret"), 0o644)
	os.Symlink(outside, filepath.Join(dir, "linked.ts"))
	if code := h.run("fn", "deploy", "hooks", dir, "-m", "Say v2", "--wait"); code != 0 {
		t.Fatalf("deploy: %d %s %s", code, h.out.String(), h.err.String())
	}
	sent := f.deploys[0]
	if sent.BaseVersionID != f.versions[0].ID || sent.Message != "Say v2" || len(sent.Files) != 3 || !strings.Contains(sent.Files["index.ts"], "v2") ||
		sent.Files["routes/admin/users.ts"] == "" || sent.Files["lib/util.ts"] == "" {
		t.Fatalf("sent %+v", sent)
	}
	if !strings.Contains(h.err.String(), "skipping linked.ts (symlink)") {
		t.Fatalf("symlink not reported: %s", h.err.String())
	}
	if !strings.Contains(h.out.String(), "v2 (bbbbbbb) of hooks") || !strings.Contains(h.out.String(), "release is live") {
		t.Fatalf("out %s", h.out.String())
	}
	if meta, _, _ := readFunctionMeta(dir); meta.BaseVersionID != f.versions[1].ID {
		t.Fatalf("base not advanced: %+v", meta)
	}

	// A second checkout still on v1 conflicts (exit 4), then --force deploys.
	stale := filepath.Join(t.TempDir(), "stale")
	writeFunctionMeta(stale, functionMeta{SiteID: fnID, Slug: "hooks", BaseVersionID: f.versions[0].ID, Entrypoint: "index.ts", Files: []string{"index.ts"}})
	os.WriteFile(filepath.Join(stale, "index.ts"), []byte("export default {}\n// mine\n"), 0o644)
	if code := h.run("fn", "deploy", "hooks", stale); code != ExitConflict || !strings.Contains(h.err.String(), "v2 was deployed") {
		t.Fatalf("conflict: %d %s", code, h.err.String())
	}
	if code := h.run("fn", "deploy", "hooks", stale, "--force"); code != 0 || !f.deploys[2].Force {
		t.Fatalf("force: %d %s", code, h.err.String())
	}

	// A directory of another function is refused; a directory without the entrypoint too.
	other := t.TempDir()
	writeFunctionMeta(other, functionMeta{SiteID: "01JOTHER", Slug: "other"})
	if code := h.run("fn", "deploy", "hooks", other); code != ExitUsage {
		t.Fatalf("other function's dir: %d %s", code, h.err.String())
	}
	if code := h.run("fn", "deploy", "hooks", t.TempDir()); code != ExitError || !strings.Contains(h.err.String(), "index.ts not found") {
		t.Fatalf("empty dir: %d %s", code, h.err.String())
	}
}

func TestFnRollbackAndRun(t *testing.T) {
	h, f := fnHarness(t)
	f.versions = append(f.versions, api.FunctionVersion{ID: "01JV2", Number: 2})
	f.live = 2
	if code := h.run("fn", "rollback", "hooks", "v1", "--wait"); code != 0 || f.live != 1 || !strings.Contains(h.out.String(), "Rollback 01JDEP succeeded") {
		t.Fatalf("rollback: %d %s %s", code, h.out.String(), h.err.String())
	}
	if code := h.run("fn", "rollback", "hooks", "latest"); code != ExitUsage {
		t.Fatalf("bad version: %d", code)
	}

	if code := h.run("fn", "run", "hooks", "Nightly"); code != 0 {
		t.Fatalf("run: %d %s", code, h.err.String())
	}
	if out := h.out.String(); out != "kiln: running Nightly (manual)\ncleaned 3 rows\nkiln: Nightly finished in 12ms\n" {
		t.Fatalf("streamed output %q", out)
	}
	if code := h.run("fn", "run", "hooks", "broken"); code != 1 || !strings.Contains(h.out.String(), "Error: nope") || !strings.Contains(h.err.String(), "exited with code 1") {
		t.Fatalf("failed run: %d %s %s", code, h.out.String(), h.err.String())
	}
}

func TestFnInvoke(t *testing.T) {
	h, f := fnHarness(t)
	if code := h.run("fn", "invoke", "hooks", "hello", "-d", `{"a":1}`, "-H", "X-Kiln-Key: k1"); code != 0 {
		t.Fatalf("invoke: %d %s", code, h.err.String())
	}
	if f.invoked.Method != "POST" || f.invoked.URL.Path != "/fn/hello" || f.invoked.Header.Get("X-Kiln-Key") != "k1" || f.invoked.Header.Get("Content-Type") != "application/json" || f.body != `{"a":1}` {
		t.Fatalf("request %s %s %v %q", f.invoked.Method, f.invoked.URL.Path, f.invoked.Header, f.body)
	}
	if out := h.out.String(); !strings.Contains(out, "200 OK") || !strings.Contains(out, "X-Function: hooks") || !strings.HasSuffix(out, "hello POST\n") {
		t.Fatalf("out %q", out)
	}
	if code := h.run("fn", "invoke", "hooks", "/boom"); code != ExitError || !strings.Contains(h.out.String(), "500") {
		t.Fatalf("500: %d %s", code, h.out.String())
	}
	if code := h.run("fn", "invoke", "hooks", "-H", "nocolon"); code != ExitUsage {
		t.Fatalf("bad header: %d", code)
	}
	body := filepath.Join(t.TempDir(), "body.txt")
	os.WriteFile(body, []byte("from a file"), 0o644)
	if code := h.run("fn", "invoke", "hooks", "-X", "put", "-d", "@"+body); code != 0 || f.invoked.Method != "PUT" || f.body != "from a file" {
		t.Fatalf("@file: %d %s %q", code, f.invoked.Method, f.body)
	}
}

func TestFnSafeJoin(t *testing.T) {
	for _, p := range []string{"../x", "/etc/passwd", "a/../../x"} {
		if _, err := safeJoin("/tmp/d", p); err == nil {
			t.Errorf("%q accepted", p)
		}
	}
	if got, err := safeJoin("/tmp/d", "lib/a.ts"); err != nil || got != "/tmp/d/lib/a.ts" {
		t.Errorf("lib/a.ts: %q %v", got, err)
	}
}

func TestFnSafeJoinRefusesSymlinkedFolders(t *testing.T) {
	dir, outside := t.TempDir(), t.TempDir()
	if err := os.Symlink(outside, filepath.Join(dir, "lib")); err != nil {
		t.Skip(err)
	}
	if _, err := safeJoin(dir, "lib/util.ts"); err == nil {
		t.Fatal("wrote through a symlinked folder")
	}
	if p, err := safeJoin(dir, "src/util.ts"); err != nil || p != filepath.Join(dir, "src", "util.ts") {
		t.Fatalf("%q %v", p, err)
	}
}
