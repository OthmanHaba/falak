package provision

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestMergeLiveRestore(t *testing.T) {
	out, changed, _, err := MergeLiveRestore([]byte(`{"log-driver": "journald", "default-address-pools": [{"base": "10.200.0.0/16", "size": 24}]}`))
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
	again, changed, _, err := MergeLiveRestore(out)
	if err != nil || changed || string(again) != string(out) {
		t.Errorf("second merge: changed=%v err=%v", changed, err)
	}
	// Explicitly off is the administrator's choice: kept, with a warning.
	if out, changed, warning, _ := MergeLiveRestore([]byte(`{"live-restore": false}`)); changed || warning == "" || string(out) != `{"live-restore": false}` {
		t.Errorf("live-restore false overridden: %s %v %q", out, changed, warning)
	}
	if out, changed, _, err := MergeLiveRestore(nil); err != nil || !changed || string(out) != "{\n  \"live-restore\": true\n}\n" {
		t.Errorf("empty file: %q %v %v", out, changed, err)
	}
	for _, bad := range []string{`{"a":`, `[1]`, `nope`} {
		if _, _, _, err := MergeLiveRestore([]byte(bad)); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
}

func TestLiveRestoreOnDockerdsCommandLineLeavesDaemonJSON(t *testing.T) {
	root := t.TempDir()
	f := (&runnertest.Fake{}).On("systemctl show docker", runner.Result{Stdout: []byte("ExecStart={ path=/usr/bin/dockerd ; argv[]=/usr/bin/dockerd --live-restore -H fd:// }\n")})
	p := New(Deps{Runner: f, FS: hostfs.FS{Root: root}})
	res, err := p.Apply(context.Background(), Plan{Docker: &DockerPlan{LiveRestore: true}}, commands.NewTestStream("c", &commands.Collector{}))
	if err != nil || res.(Result).Changed || f.Ran("systemctl reload docker") {
		t.Fatalf("%+v %v %v", res, err, f.Lines())
	}
	if _, err := os.Stat(filepath.Join(root, DaemonConfigPath)); err == nil {
		t.Error("daemon.json written next to the --live-restore flag")
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
