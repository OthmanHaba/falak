package provision

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestMergeLiveRestore(t *testing.T) {
	out, changed, err := MergeLiveRestore([]byte(`{"log-driver": "journald", "default-address-pools": [{"base": "10.200.0.0/16", "size": 24}]}`))
	if err != nil || !changed {
		t.Fatalf("changed=%v err=%v", changed, err)
	}
	var doc map[string]any
	if err := json.Unmarshal(out, &doc); err != nil {
		t.Fatal(err)
	}
	if doc["live-restore"] != true || doc["log-driver"] != "journald" || doc["default-address-pools"] == nil {
		t.Errorf("merged: %s", out)
	}
	again, changed, err := MergeLiveRestore(out)
	if err != nil || changed || string(again) != string(out) {
		t.Errorf("second merge: changed=%v err=%v", changed, err)
	}
	// Explicitly off is turned on.
	if _, changed, _ := MergeLiveRestore([]byte(`{"live-restore": false}`)); !changed {
		t.Error("live-restore false kept")
	}
	if out, changed, err := MergeLiveRestore(nil); err != nil || !changed || string(out) != "{\n  \"live-restore\": true\n}\n" {
		t.Errorf("empty file: %q %v %v", out, changed, err)
	}
	for _, bad := range []string{`{"a":`, `[1]`, `nope`} {
		if _, _, err := MergeLiveRestore([]byte(bad)); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
}

func TestApplyDockerLiveRestoreReloadsOnlyWhenChanged(t *testing.T) {
	root := t.TempDir()
	fs := hostfs.FS{Root: root}
	os.MkdirAll(filepath.Join(root, "etc/docker"), 0o755)
	os.WriteFile(filepath.Join(root, DaemonConfigPath), []byte(`{"log-driver":"local"}`), 0o644)
	f := &runnertest.Fake{}
	p := New(Deps{Runner: f, FS: fs})
	plan := Plan{Docker: &DockerPlan{LiveRestore: true}}
	for i, want := range []bool{true, false} {
		f.Reset()
		res, err := p.Apply(context.Background(), plan, commands.NewTestStream("c", &commands.Collector{}))
		if err != nil {
			t.Fatal(err)
		}
		if res.(Result).Changed != want || f.Ran("systemctl reload docker") != want {
			t.Errorf("run %d: changed %v, reloaded %v", i, res.(Result).Changed, f.Ran("systemctl reload docker"))
		}
	}
	b, _ := os.ReadFile(filepath.Join(root, DaemonConfigPath))
	var doc map[string]any
	json.Unmarshal(b, &doc)
	if doc["live-restore"] != true || doc["log-driver"] != "local" {
		t.Errorf("daemon.json %s", b)
	}
}
