package builder

import (
	"bytes"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/runner/runnertest"
)

const fixtures = "testdata/fixtures"

func copyDir(t *testing.T, src, dst string) {
	t.Helper()
	err := filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(src, p)
		target := filepath.Join(dst, rel)
		if d.IsDir() {
			return os.MkdirAll(target, 0o755)
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		fi, _ := d.Info()
		return os.WriteFile(target, b, fi.Mode().Perm())
	})
	if err != nil {
		t.Fatal(err)
	}
}

// fakeGit makes `git init` materialize fixture (as if cloned) and `git log` report a fixed commit.
func fakeGit(t *testing.T, f *runnertest.Fake, fixture string) {
	t.Helper()
	f.OnFunc("git -C ", func(c runnertest.Call) (runner.Result, error) {
		dir, sub := c.Args[1], c.Args[2]
		switch sub {
		case "init":
			copyDir(t, fixture, dir)
			_ = os.MkdirAll(filepath.Join(dir, ".git"), 0o755)
			_ = os.WriteFile(filepath.Join(dir, ".git", "HEAD"), []byte("ref: refs/heads/main\n"), 0o644)
		case "log":
			return runner.Result{Stdout: []byte("0123456789abcdef0123456789abcdef01234567 1700000000\n")}, nil
		}
		return runner.Result{}, nil
	})
}

func noRailpack(name string) (string, error) {
	if name == "railpack" || name == "buildctl" {
		return "", errors.New("not found")
	}
	return "/usr/bin/" + name, nil
}

func eventSchema(t *testing.T) *jsonschema.Schema {
	t.Helper()
	c := jsonschema.NewCompiler()
	c.DefaultDraft(jsonschema.Draft2020)
	c.AssertFormat()
	b, err := os.ReadFile("../../../contracts/agent-protocol/event.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(b))
	if err != nil {
		t.Fatal(err)
	}
	if err := c.AddResource("event.schema.json", doc); err != nil {
		t.Fatal(err)
	}
	s, err := c.Compile("event.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	return s
}

// validateNDJSON checks every line against event.schema.json plus stream invariants.
func validateNDJSON(t *testing.T, ndjson []byte) []commands.Event {
	t.Helper()
	s := eventSchema(t)
	var evs []commands.Event
	lines := strings.Split(strings.TrimSpace(string(ndjson)), "\n")
	for i, line := range lines {
		v, err := jsonschema.UnmarshalJSON(strings.NewReader(line))
		if err != nil {
			t.Fatalf("line %d not JSON: %v: %s", i, err, line)
		}
		if err := s.Validate(v); err != nil {
			t.Fatalf("line %d violates event.schema.json: %v\n%s", i, err, line)
		}
		var ev commands.Event
		if err := json.Unmarshal([]byte(line), &ev); err != nil {
			t.Fatal(err)
		}
		if ev.Seq != int64(i) {
			t.Fatalf("line %d has seq %d", i, ev.Seq)
		}
		evs = append(evs, ev)
	}
	if evs[0].Kind != commands.KindStarted || evs[len(evs)-1].Kind != commands.KindFinished {
		t.Fatalf("stream must start with started and end with finished: %s … %s", evs[0].Kind, evs[len(evs)-1].Kind)
	}
	return evs
}
