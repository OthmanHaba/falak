package cgroup

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

func TestRenderSlice(t *testing.T) {
	got := Render(Slice{Name: "site_shop", MemoryMaxBytes: 512 << 20, MemoryHighBytes: 460 << 20, MemoryLowBytes: 128 << 20, CPUQuotaPercent: 150, TasksMax: 512})
	want := `# Managed by Falak — do not edit
[Unit]
Description=Falak limits for site_shop
Before=slices.target

[Slice]
MemoryAccounting=yes
CPUAccounting=yes
TasksAccounting=yes
MemoryMax=536870912
MemoryHigh=482344960
MemoryLow=134217728
MemorySwapMax=0
CPUQuota=150%
TasksMax=512
`
	if got != want {
		t.Fatalf("got:\n%s\nwant:\n%s", got, want)
	}
	// Unset limits: no quota line, memory and tasks unlimited, swap allowed.
	got = Render(Slice{Name: "worker_01j9z8y7x6w5v4t3s2r1q0p9na"})
	if strings.Contains(got, "CPUQuota") || !strings.Contains(got, "MemoryMax=infinity\n") || !strings.Contains(got, "MemorySwapMax=infinity\n") || !strings.Contains(got, "TasksMax=infinity\n") {
		t.Fatal(got)
	}
}

func TestApplyWritesUnitsAndSetsPropertiesLive(t *testing.T) {
	root := t.TempDir()
	fr := &runnertest.Fake{}
	m := &Manager{Runner: fr, FS: hostfs.FS{Root: root}}
	ctx := context.Background()
	shop := Slice{Name: "site_shop", MemoryMaxBytes: 256 << 20, CPUQuotaPercent: 50}
	worker := Slice{Name: "worker_01j9z8y7x6w5v4t3s2r1q0p9na", TasksMax: 64}

	changed, err := m.Apply(ctx, []Slice{shop, worker})
	if err != nil || len(changed) != 2 {
		t.Fatalf("%v %v", changed, err)
	}
	if b, _ := os.ReadFile(filepath.Join(root, UnitDir, "falak-site_shop.slice")); !strings.Contains(string(b), "MemoryMax=268435456") {
		t.Fatalf("%s", b)
	}
	want := []string{
		"systemctl daemon-reload",
		"systemctl set-property --runtime falak-site_shop.slice MemoryMax=268435456 MemoryHigh=infinity MemoryLow=0 MemorySwapMax=0 CPUQuota=50% TasksMax=infinity",
		"systemctl set-property --runtime falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice MemoryMax=infinity MemoryHigh=infinity MemoryLow=0 MemorySwapMax=infinity CPUQuota= TasksMax=64",
	}
	if got := fr.Lines(); strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Fatalf("got:\n%s", strings.Join(got, "\n"))
	}

	// Unchanged: nothing runs. A changed limit is set live on that slice only.
	fr.Reset()
	if changed, _ := m.Apply(ctx, []Slice{shop, worker}); len(changed) != 0 || len(fr.Lines()) != 0 {
		t.Fatalf("%v %v", changed, fr.Lines())
	}
	shop.MemoryMaxBytes = 512 << 20
	if changed, _ := m.Apply(ctx, []Slice{shop, worker}); strings.Join(changed, ",") != "site_shop" || len(fr.Lines()) != 2 || !strings.Contains(fr.Lines()[1], "falak-site_shop.slice MemoryMax=536870912") {
		t.Fatalf("%v %v", changed, fr.Lines())
	}

	// Prune: managed units not kept are removed and their live limits cleared; foreign units stay.
	os.WriteFile(filepath.Join(root, UnitDir, "falak-custom.slice"), []byte("[Slice]\n"), 0o644)
	fr.Reset()
	removed, err := m.Prune(ctx, []Slice{shop})
	if err != nil || strings.Join(removed, ",") != worker.Name {
		t.Fatalf("%v %v", removed, err)
	}
	fs := hostfs.FS{Root: root}
	if fs.Exists(filepath.Join(UnitDir, Unit(worker.Name))) || !fs.Exists(filepath.Join(UnitDir, "falak-custom.slice")) {
		t.Fatal("prune removed the wrong units")
	}
	if l := fr.Lines(); len(l) != 2 || l[0] != "systemctl daemon-reload" || !strings.HasPrefix(l[1], "systemctl set-property --runtime falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice MemoryMax=infinity") {
		t.Fatalf("%v", l)
	}
}

func TestApplyRejectsBadSlices(t *testing.T) {
	m := &Manager{Runner: &runnertest.Fake{}, FS: hostfs.FS{Root: t.TempDir()}}
	for _, bad := range [][]Slice{
		{{Name: "site-shop"}},
		{{Name: "../x"}},
		{{Name: "a"}, {Name: "a"}},
		{{Name: "a", MemoryMaxBytes: 10, MemoryHighBytes: 20}},
		{{Name: "a", TasksMax: -1}},
	} {
		if _, err := m.Apply(context.Background(), bad); err == nil {
			t.Errorf("%+v accepted", bad)
		}
	}
}

func TestScopeCommand(t *testing.T) {
	got := ScopeCommand(ScopeSpec{Slice: "worker_01j9z8y7x6w5v4t3s2r1q0p9na", Unit: "falak-proc-shop-worker-0", UID: 1001, GID: 1001, AsUser: true,
		Command: []string{"php", "artisan", "queue:work"}, SystemdVersion: 255})
	want := "systemd-run --scope --quiet --collect --slice=falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice --unit=falak-proc-shop-worker-0.scope " +
		"--property=OOMPolicy=continue -- setpriv --reuid=1001 --regid=1001 --init-groups -- php artisan queue:work"
	if strings.Join(got, " ") != want {
		t.Fatalf("%s", strings.Join(got, " "))
	}
	// systemd 249/252 (Ubuntu 22.04, Debian 12): no OOMPolicy for scopes. Root programs need no setpriv.
	got = ScopeCommand(ScopeSpec{Slice: "site_shop", Unit: "falak-proc-shop-app-0", Command: []string{"node", "server.js"}, SystemdVersion: 252})
	if strings.Join(got, " ") != "systemd-run --scope --quiet --collect --slice=falak-site_shop.slice --unit=falak-proc-shop-app-0.scope -- node server.js" {
		t.Fatalf("%v", got)
	}
}

func TestSystemdVersion(t *testing.T) {
	fr := (&runnertest.Fake{}).On("systemctl --version", runner.Result{Stdout: []byte("systemd 255 (255.4-1ubuntu8)\n+PAM +AUDIT\n")})
	if v := SystemdVersion(context.Background(), fr); v != 255 {
		t.Fatal(v)
	}
	if v := SystemdVersion(context.Background(), (&runnertest.Fake{}).On("systemctl", runner.Result{ExitCode: 1})); v != 0 {
		t.Fatal(v)
	}
}

func TestWatchOOMReportsIncreases(t *testing.T) {
	root := t.TempDir()
	write := func(slice, events string) {
		d := filepath.Join(root, "falak-"+slice+".slice")
		os.MkdirAll(d, 0o755)
		os.WriteFile(filepath.Join(d, "memory.events"), []byte(events), 0o644)
	}
	write("site_shop", "low 0\nhigh 4\nmax 2\noom 1\noom_kill 1\noom_group_kill 0\n")
	write("worker_01j9z8y7x6w5v4t3s2r1q0p9na", "low 0\nhigh 0\nmax 0\noom 0\noom_kill 0\n")
	w := &WatchOOM{Root: root}
	var got []resources.Event
	add := func(e resources.Event) { got = append(got, e) }
	w.Once(add) // baseline
	if len(got) != 0 {
		t.Fatalf("%+v", got)
	}
	write("site_shop", "low 0\nhigh 9\nmax 5\noom 3\noom_kill 3\n")
	w.Once(add)
	if len(got) != 1 || got[0].Name != "site_shop" || got[0].Count != 2 || got[0].Kind != resources.KindOOMKill || got[0].Source != resources.SourceSlice {
		t.Fatalf("%+v", got)
	}
}
