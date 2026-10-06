package system

import (
	"context"
	"encoding/json"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestExecMasksEnvAndSiteEnvFileSecrets(t *testing.T) {
	f := (&runnertest.Fake{}).OnFunc("/bin/bash -c", func(c runnertest.Call) (runner.Result, error) {
		return runner.Result{Stdout: []byte("APP_KEY=from-env-file TOKEN=from-payload\n")}, nil
	})
	s := New(Deps{Runner: f, SiteSecrets: func(site string, keys []string) []string {
		if site == "shop" && len(keys) == 2 {
			return []string{"from-env-file"}
		}
		return nil
	}})
	reg := commands.NewRegistry()
	s.Register(reg)
	col := &commands.Collector{}
	d := commands.NewDispatcher(context.Background(), reg, col, nil)
	payload, _ := json.Marshal(ExecPayload{Script: "php artisan about", Site: "shop", Env: map[string]string{"TOKEN": "from-payload"}, Mask: []string{"APP_KEY", "TOKEN"}})
	d.Submit(commands.Envelope{ID: "e1", Type: "system.exec", TimeoutS: 10, Payload: payload})
	d.Wait()
	if out := col.Output("stdout"); out != "APP_KEY=•••• TOKEN=••••\n" || strings.Contains(out, "from-") {
		t.Fatalf("output %q", out)
	}
}
