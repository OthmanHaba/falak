package supervisor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/cgroup"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/resources"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

type fakeSlices struct {
	mu    sync.Mutex
	calls []string
}

func (f *fakeSlices) Apply(_ context.Context, desired []cgroup.Slice) ([]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var names []string
	for _, s := range desired {
		names = append(names, s.Name)
	}
	f.calls = append(f.calls, "apply "+strings.Join(names, ","))
	return names, nil
}

func (f *fakeSlices) Prune(_ context.Context, keep []cgroup.Slice) ([]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.calls = append(f.calls, "prune")
	return []string{"old"}, nil
}

func TestApplyWithSlicesOrdersSlicesAroundPrograms(t *testing.T) {
	s, _, _ := newSup(t)
	fs := &fakeSlices{}
	s.opts.Slices = fs
	ctx := context.Background()
	res, err := s.ApplyWithSlices(ctx, []Program{{Name: "web", Command: sh("exec sleep 30")}}, []cgroup.Slice{{Name: "site_shop", MemoryMaxBytes: 64 << 20}})
	if err != nil || strings.Join(fs.calls, "|") != "apply site_shop|prune" || strings.Join(res.Slices, ",") != "old,site_shop" || !res.Changed {
		t.Fatalf("%+v %v %v", res, err, fs.calls)
	}
	// A program naming an undeclared slice, or a bad slice, is a payload error; nothing is touched.
	fs.calls = nil
	for _, tc := range []struct {
		progs  []Program
		slices []cgroup.Slice
	}{
		{[]Program{{Name: "web", Command: sh("true"), Slice: "site_other"}}, []cgroup.Slice{{Name: "site_shop"}}},
		{nil, []cgroup.Slice{{Name: "site-shop"}}},
	} {
		if _, err := s.ApplyWithSlices(ctx, tc.progs, tc.slices); !commands.IsPayloadError(err) {
			t.Fatalf("%v", err)
		}
	}
	if len(fs.calls) != 0 {
		t.Fatal(fs.calls)
	}
	// No slice support on the host: refused only when slices are asked for.
	s.opts.Slices = nil
	if _, err := s.ApplyWithSlices(ctx, nil, []cgroup.Slice{{Name: "site_shop"}}); err == nil {
		t.Fatal("slices accepted without a manager")
	}
	if _, err := s.ApplyWithSlices(ctx, nil, nil); err != nil {
		t.Fatal(err)
	}
}

func TestLaunchInSliceWrapsInScope(t *testing.T) {
	fr := (&runnertest.Fake{}).On("systemctl --version", runner.Result{Stdout: []byte("systemd 249 (249.11-0ubuntu3)\n")})
	s := New(Options{Systemd: fr})
	cred := &syscall.Credential{Uid: 1001, Gid: 1002, Groups: []uint32{1002, 33}}

	argv, c := s.launch(Program{Name: "shop.worker", Command: []string{"php", "artisan", "queue:work"}}, 0, cred)
	if strings.Join(argv, " ") != "php artisan queue:work" || c != cred || len(fr.Lines()) != 0 {
		t.Fatalf("%v %v", argv, fr.Lines())
	}
	argv, c = s.launch(Program{Name: "shop.worker", Command: []string{"php", "artisan", "queue:work"}, Slice: "worker_01j9z8y7x6w5v4t3s2r1q0p9na"}, 2, cred)
	want := "systemd-run --scope --quiet --collect --slice=falak-worker_01j9z8y7x6w5v4t3s2r1q0p9na.slice --unit=falak-proc-shop.worker-2.scope -- " +
		"setpriv --reuid=1001 --regid=1002 --init-groups -- php artisan queue:work"
	if strings.Join(argv, " ") != want || c != nil {
		t.Fatalf("%s", strings.Join(argv, " "))
	}
	if l := fr.Lines(); len(l) != 2 || l[0] != "systemctl stop --quiet falak-proc-shop.worker-2.scope" || l[1] != "systemctl --version" {
		t.Fatalf("%v", l)
	}
	if !cgroup.ScopeUnitRe.MatchString("falak-proc-shop.worker-2") {
		t.Fatal("scope name")
	}
}

func TestMaxRestartsGivesUp(t *testing.T) {
	s, _, _ := newSup(t)
	_, err := s.Apply(context.Background(), []Program{{Name: "crash", Command: sh("exit 3"), MaxRestarts: 2, StartSeconds: ip(5),
		Backoff: &Backoff{InitialMS: 10, MaxMS: 10}}})
	if err != nil {
		t.Fatal(err)
	}
	eventually(t, 5*time.Second, func() bool { st := s.Status(nil); return len(st) == 1 && st[0].State == StateFatal }, "fatal after max restarts")
	if st := s.Status(nil)[0]; st.Restarts != 2 || *st.LastExitCode != 3 {
		t.Fatalf("%+v", st)
	}
}

func TestLogRotationKeepsFiles(t *testing.T) {
	dir := t.TempDir()
	r, err := openRot(filepath.Join(dir, "x.log"), 4, 2)
	if err != nil {
		t.Fatal(err)
	}
	for _, l := range []string{"aaa\n", "bbb\n", "ccc\n", "ddd\n"} {
		r.Write([]byte(l))
	}
	r.Close()
	read := func(n string) string { b, _ := os.ReadFile(filepath.Join(dir, n)); return string(b) }
	if read("x.log") != "ddd\n" || read("x.log.1") != "ccc\n" || read("x.log.2") != "bbb\n" || read("x.log.3") != "" {
		t.Fatalf("%q %q %q", read("x.log"), read("x.log.1"), read("x.log.2"))
	}
}

func TestRestartsAreReported(t *testing.T) {
	s, _, _ := newSup(t)
	_, err := s.Apply(context.Background(), []Program{{Name: "shop.flaky", Site: "shop", Command: sh("exit 1"), Numprocs: 2, Backoff: &Backoff{InitialMS: 10, MaxMS: 10}}})
	if err != nil {
		t.Fatal(err)
	}
	var counts resources.Counter
	var got []resources.Event
	s.restartsOnce(&counts, func(e resources.Event) { got = append(got, e) })
	eventually(t, 5*time.Second, func() bool {
		got = nil
		s.restartsOnce(&counts, func(e resources.Event) { got = append(got, e) })
		return len(got) == 1 && got[0].Count >= 2
	}, "restarts of both instances reported")
	if got[0].Kind != resources.KindRestart || got[0].Source != resources.SourceProgram || got[0].Name != "shop.flaky" || got[0].Site != "shop" {
		t.Fatalf("%+v", got)
	}
}
