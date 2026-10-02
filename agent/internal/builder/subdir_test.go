package builder

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

// monorepo writes a repository with a static app under apps/web and a symlink pointing outside the checkout.
func monorepo(t *testing.T, f *runnertest.Fake) {
	t.Helper()
	outside := t.TempDir()
	_ = os.WriteFile(filepath.Join(outside, "secret.txt"), []byte("host file\n"), 0o644)
	f.OnFunc("git -C ", func(c runnertest.Call) (runner.Result, error) {
		dir, sub := c.Args[1], c.Args[2]
		switch sub {
		case "init":
			_ = os.MkdirAll(filepath.Join(dir, "apps", "web"), 0o755)
			_ = os.WriteFile(filepath.Join(dir, "apps", "web", "index.html"), []byte("<h1>web</h1>\n"), 0o644)
			_ = os.WriteFile(filepath.Join(dir, "README.md"), []byte("monorepo\n"), 0o644)
			_ = os.Symlink(outside, filepath.Join(dir, "escape"))
			_ = os.MkdirAll(filepath.Join(dir, ".git"), 0o755)
		case "log":
			return runner.Result{Stdout: []byte("0123456789abcdef0123456789abcdef01234567 1700000000\n")}, nil
		}
		return runner.Result{}, nil
	})
}

// A site's root directory (job subdir) is the app root: only that folder becomes the release.
func TestNativeBuildOfASubdirPackagesOnlyThatFolder(t *testing.T) {
	f := &runnertest.Fake{}
	monorepo(t, f)
	b := newBuilder(t, f)
	var out bytes.Buffer
	job := Job{ID: "01JBUILD0000000000000000SB", Mode: ModeNative, Repo: Repo{URL: "https://github.com/acme/mono.git", Ref: "main"}, Subdir: "apps/web", Runtime: "static", Native: &NativeSpec{}}
	res, err := b.Build(context.Background(), job, NewNDJSONSink(&out))
	if err != nil {
		t.Fatalf("%v\n%s", err, out.String())
	}
	data, err := os.ReadFile(res.Artifact.Path)
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, h := range tarEntries(t, data) {
		names = append(names, h.Name)
	}
	if !contains(names, "index.html") || contains(names, "README.md") || contains(names, "apps/web/index.html") {
		t.Fatalf("artifact entries %v", names)
	}
}

// A subdir that is a symlink to somewhere outside the checkout is refused before anything is built.
func TestSubdirSymlinkOutsideTheRepositoryIsRefused(t *testing.T) {
	f := &runnertest.Fake{}
	monorepo(t, f)
	b := newBuilder(t, f)
	var out bytes.Buffer
	job := Job{ID: "01JBUILD0000000000000000SC", Mode: ModeNative, Repo: Repo{URL: "https://github.com/acme/mono.git", Ref: "main"}, Subdir: "escape", Runtime: "static", Native: &NativeSpec{}}
	_, err := b.Build(context.Background(), job, NewNDJSONSink(&out))
	if err == nil || !strings.Contains(err.Error(), "resolves outside the repository") {
		t.Fatalf("err = %v", err)
	}
}
