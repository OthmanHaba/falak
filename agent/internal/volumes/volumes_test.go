package volumes

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/klauspost/compress/zstd"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/hostfs"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
)

const (
	id1 = "01j9z8y7x6w5v4t3s2r1q0p9na"
	id2 = "01j9z8y7x6w5v4t3s2r1q0p9nb"
)

type nopStream struct {
	mu  sync.Mutex
	out bytes.Buffer
}

type lw struct{ s *nopStream }

func (w lw) Write(p []byte) (int, error) {
	w.s.mu.Lock()
	defer w.s.mu.Unlock()
	return w.s.out.Write(p)
}

func (s *nopStream) Stdout() io.Writer { return lw{s} }
func (s *nopStream) Stderr() io.Writer { return lw{s} }
func (s *nopStream) Progress(float64)  {}
func (s *nopStream) Emit(_, _ string)  {}

// fakeDocker keeps named volumes under <fs>/docker/<name> and a scriptable container list.
type fakeDocker struct {
	mu         sync.Mutex
	fs         hostfs.FS
	volumes    map[string]docker.Volume
	containers func() []docker.ContainerSummary
	calls      []string
}

func newFakeDocker(fs hostfs.FS) *fakeDocker {
	return &fakeDocker{fs: fs, volumes: map[string]docker.Volume{}}
}

func (f *fakeDocker) record(s string) {
	f.mu.Lock()
	f.calls = append(f.calls, s)
	f.mu.Unlock()
}

func (f *fakeDocker) VolumeCreate(_ context.Context, name string, labels map[string]string) (docker.Volume, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	v := docker.Volume{Name: name, Driver: "local", Mountpoint: "/docker/" + name, Labels: labels}
	f.volumes[name] = v
	return v, f.fs.MkdirAll(v.Mountpoint, 0o755)
}

func (f *fakeDocker) VolumeInspect(_ context.Context, name string) (docker.Volume, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	v, ok := f.volumes[name]
	return v, ok, nil
}

func (f *fakeDocker) VolumeList(context.Context) ([]docker.Volume, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []docker.Volume
	for _, v := range f.volumes {
		out = append(out, v)
	}
	return out, nil
}

func (f *fakeDocker) VolumeRemove(_ context.Context, name string) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	_, ok := f.volumes[name]
	delete(f.volumes, name)
	return ok, nil
}

func (f *fakeDocker) ContainerList(context.Context, bool, []string) ([]docker.ContainerSummary, error) {
	if f.containers == nil {
		return nil, nil
	}
	return f.containers(), nil
}

func (f *fakeDocker) ContainerPause(_ context.Context, id string) error {
	f.record("pause " + id)
	return nil
}

func (f *fakeDocker) ContainerUnpause(_ context.Context, id string) error {
	f.record("unpause " + id)
	return nil
}

func (f *fakeDocker) ContainerStop(_ context.Context, id string, _ time.Duration) (bool, error) {
	f.record("stop " + id)
	return true, nil
}

func (f *fakeDocker) ContainerStart(_ context.Context, id string) error {
	f.record("start " + id)
	return nil
}

type env struct {
	svc     *Service
	fs      hostfs.FS
	run     *runnertest.Fake
	docker  *fakeDocker
	mounted map[string]bool
}

func newEnv(t *testing.T, mod func(*Deps)) *env {
	t.Helper()
	fs := hostfs.FS{Root: t.TempDir()}
	run := &runnertest.Fake{}
	// fallocate -l N <file>: create or grow the file like the real tool.
	run.OnFunc("fallocate", func(c runnertest.Call) (runner.Result, error) {
		n, _ := strconv.ParseInt(c.Args[1], 10, 64)
		f, err := os.OpenFile(c.Args[2], os.O_CREATE|os.O_WRONLY, 0o600)
		if err != nil {
			return runner.Result{}, err
		}
		defer f.Close()
		return runner.Result{}, f.Truncate(n)
	})
	e := &env{fs: fs, run: run, docker: newFakeDocker(fs), mounted: map[string]bool{}}
	d := Deps{Runner: run, FS: fs, Docker: e.docker, Poll: 10 * time.Millisecond,
		StatFS:  func(string) (Usage, error) { return Usage{Size: 1000, Available: 600, Used: 400}, nil },
		Mounted: func(p string) bool { return e.mounted[p] }}
	if mod != nil {
		mod(&d)
	}
	e.svc = New(d)
	return e
}

func sized(id string) Ref { return Ref{ID: id, Kind: KindSized} }

func TestUnitNameMatchesMountpoint(t *testing.T) {
	if got := unitName("/var/lib/falak/volumes/" + id1); got != "var-lib-falak-volumes-"+id1+".mount" {
		t.Fatal(got)
	}
	if got := unitName("/srv/my-data/.x"); got != `srv-my\x2ddata-\x2ex.mount` {
		t.Fatal(got)
	}
}

func TestSizedVolumeLifecycle(t *testing.T) {
	e := newEnv(t, nil)
	ctx := context.Background()
	size := int64(64 << 20)
	unit := "var-lib-falak-volumes-" + id1 + ".mount"

	res, err := e.svc.Create(ctx, CreatePayload{Volume: sized(id1), SizeBytes: size}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	cr := res.(CreateResult)
	if !cr.Created || cr.SizeBytes != size || cr.Path != "/var/lib/falak/volumes/"+id1 {
		t.Fatalf("%+v", cr)
	}
	img := e.fs.P("/var/lib/falak/volumes/images/" + id1 + ".img")
	want := []string{
		"fallocate -l 67108864 " + img + ".partial",
		"mkfs.ext4 -F -q -m 0 -L falak-" + id1[16:] + " " + img + ".partial",
		"chattr +i " + e.fs.P("/var/lib/falak/volumes/"+id1),
		"systemctl daemon-reload",
		"systemctl enable --now " + unit,
	}
	if got := e.run.Lines(); strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Fatalf("commands:\n%s", strings.Join(got, "\n"))
	}
	b, err := e.fs.ReadFile("/etc/systemd/system/" + unit)
	if err != nil {
		t.Fatal(err)
	}
	for _, line := range []string{"What=/var/lib/falak/volumes/images/" + id1 + ".img", "Where=/var/lib/falak/volumes/" + id1, "Type=ext4", "Options=loop,nodev,nosuid", "WantedBy=local-fs.target"} {
		if !strings.Contains(string(b), line+"\n") {
			t.Errorf("unit lacks %q:\n%s", line, b)
		}
	}

	// Idempotent: nothing is formatted again.
	e.run.Reset()
	res, err = e.svc.Create(ctx, CreatePayload{Volume: sized(id1), SizeBytes: size}, &nopStream{})
	if err != nil || res.(CreateResult).Created {
		t.Fatalf("%+v %v", res, err)
	}
	if got := e.run.Lines(); len(got) != 2 || got[1] != "systemctl enable --now "+unit {
		t.Fatalf("second create ran %v", got)
	}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: sized(id2)}, &nopStream{}); !isPayload(err) {
		t.Fatalf("sized without size: %v", err)
	}

	// Resize: grow only.
	if _, err := e.svc.Resize(ctx, ResizePayload{Volume: sized(id1), SizeBytes: 32 << 20}, &nopStream{}); !isPayload(err) || !strings.Contains(err.Error(), "shrinking") {
		t.Fatalf("shrink: %v", err)
	}
	// The same size still grows the filesystem (a retried resize); offline, e2fsck's "errors corrected" is fine.
	e.run.Reset()
	e.run.On("e2fsck", runner.Result{ExitCode: 1})
	res, err = e.svc.Resize(ctx, ResizePayload{Volume: sized(id1), SizeBytes: size}, &nopStream{})
	if err != nil || res.(ResizeResult).Grown {
		t.Fatalf("same size: %+v %v", res, err)
	}
	if want := []string{"losetup -j " + img, "e2fsck -f -p " + img, "resize2fs " + img}; strings.Join(e.run.Lines(), "\n") != strings.Join(want, "\n") {
		t.Fatalf("same size commands: %v", e.run.Lines())
	}
	e.run.Reset()
	e.run.On("losetup -j", runner.Result{Stdout: []byte("/dev/loop7: [64769]:1234 (" + img + ")\n")})
	res, err = e.svc.Resize(ctx, ResizePayload{Volume: sized(id1), SizeBytes: 128 << 20}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if rr := res.(ResizeResult); !rr.Grown || rr.PreviousBytes != size || rr.SizeBytes != 128<<20 {
		t.Fatalf("%+v", rr)
	}
	want = []string{"fallocate -l 134217728 " + img, "losetup -j " + img, "losetup -c /dev/loop7", "resize2fs /dev/loop7"}
	if got := e.run.Lines(); strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Fatalf("resize commands:\n%s", strings.Join(got, "\n"))
	}
	if fi, _ := os.Stat(img); fi.Size() != 128<<20 {
		t.Fatalf("image is %d bytes", fi.Size())
	}

	// Delete: refused while a running container mounts it, unless the container goes away within wait_s.
	mountSource := "/var/lib/falak/volumes/" + id1
	var listed int
	var mu sync.Mutex
	e.docker.containers = func() []docker.ContainerSummary {
		mu.Lock()
		defer mu.Unlock()
		listed++
		if listed > 3 {
			return nil
		}
		return []docker.ContainerSummary{{ID: "c1", Names: []string{"/falak-shop-blue"}, Mounts: []docker.MountPoint{{Type: "bind", Source: mountSource + "/data", Destination: "/data"}}}}
	}
	if _, err := e.svc.Delete(ctx, DeletePayload{Volume: sized(id1)}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "in use by falak-shop-blue") {
		t.Fatalf("in use: %v", err)
	}
	e.run.Reset()
	res, err = e.svc.Delete(ctx, DeletePayload{Volume: sized(id1), WaitS: 5}, &nopStream{})
	if err != nil || !res.(DeleteResult).Deleted {
		t.Fatalf("%+v %v", res, err)
	}
	want = []string{"systemctl disable --now " + unit, "systemctl daemon-reload", "chattr -i " + e.fs.P(mountSource)}
	if got := e.run.Lines(); strings.Join(got, "\n") != strings.Join(want, "\n") {
		t.Fatalf("delete commands: %v", got)
	}
	if e.fs.Exists("/etc/systemd/system/"+unit) || e.fs.Exists("/var/lib/falak/volumes/images/"+id1+".img") || e.fs.Exists(mountSource) {
		t.Fatal("unit, image or mountpoint left behind")
	}
	res, err = e.svc.Delete(ctx, DeletePayload{Volume: sized(id1)}, &nopStream{})
	if err != nil || res.(DeleteResult).Existed {
		t.Fatalf("second delete: %+v %v", res, err)
	}
}

func TestForceDeleteUnmountsLazily(t *testing.T) {
	e := newEnv(t, nil)
	ctx := context.Background()
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: sized(id1), SizeBytes: 16 << 20}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	mp := e.fs.P("/var/lib/falak/volumes/" + id1)
	e.mounted[mp] = true
	e.docker.containers = func() []docker.ContainerSummary {
		return []docker.ContainerSummary{{ID: "c1", Names: []string{"/db"}, Mounts: []docker.MountPoint{{Source: "/var/lib/falak/volumes/" + id1}}}}
	}
	e.run.OnFunc("umount -l", func(runnertest.Call) (runner.Result, error) {
		e.mounted[mp] = false
		return runner.Result{}, nil
	})
	e.run.Reset()
	if _, err := e.svc.Delete(ctx, DeletePayload{Volume: sized(id1), Force: true}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	if got := e.run.Lines(); len(got) == 0 || got[0] != "umount -l "+mp {
		t.Fatalf("%v", got)
	}
}

func isPayload(err error) bool {
	var pe *commands.PayloadError
	return errors.As(err, &pe)
}

// store is an https server that keeps PUT bodies and serves them back.
type store struct {
	mu      sync.Mutex
	objects map[string][]byte
	srv     *httptest.Server
}

func newStore(t *testing.T) *store {
	s := &store{objects: map[string][]byte{}}
	s.srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		defer s.mu.Unlock()
		switch r.Method {
		case http.MethodPut:
			b, _ := io.ReadAll(r.Body)
			if r.ContentLength != int64(len(b)) {
				http.Error(w, "length", http.StatusBadRequest)
				return
			}
			s.objects[r.URL.Path] = b
		case http.MethodGet:
			b, ok := s.objects[r.URL.Path]
			if !ok {
				http.NotFound(w, r)
				return
			}
			_, _ = w.Write(b)
		}
	}))
	t.Cleanup(s.srv.Close)
	return s
}

func (s *store) url(key string) string { return s.srv.URL + "/" + key + "?X-Amz-Signature=secret" }

func write(t *testing.T, fs hostfs.FS, p, content string) {
	t.Helper()
	if err := fs.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(fs.P(p), []byte(content), 0o640); err != nil {
		t.Fatal(err)
	}
}

func TestArchiveRestoreRoundTrip(t *testing.T) {
	st := newStore(t)
	e := newEnv(t, func(d *Deps) {
		d.HTTP = st.srv.Client()
		d.StatFS = func(string) (Usage, error) { return Usage{Size: 1 << 30, Available: 1 << 30}, nil }
	})
	ctx := context.Background()
	src := Ref{ID: id1, Kind: KindDocker, Name: "shop_data"}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: src}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	write(t, e.fs, "/docker/shop_data/a.txt", "hello")
	write(t, e.fs, "/docker/shop_data/dir/b.bin", strings.Repeat("x", 100000))
	if err := e.fs.MkdirAll("/docker/shop_data/empty", 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("/etc/passwd", e.fs.P("/docker/shop_data/passwd")); err != nil {
		t.Fatal(err)
	}
	e.docker.containers = func() []docker.ContainerSummary {
		return []docker.ContainerSummary{{ID: "c1", Names: []string{"/shop-app-1"}, Mounts: []docker.MountPoint{{Type: "volume", Name: "shop_data"}}}}
	}

	res, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: src, Consistency: "pause", Destination: Location{Kind: "presigned_url", URL: st.url("snap.tar.zst")}}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	ar := res.(ArchiveResult)
	if ar.Files != 2 || ar.Location != st.srv.URL+"/snap.tar.zst" || strings.Join(ar.Containers, ",") != "shop-app-1" {
		t.Fatalf("%+v", ar)
	}
	if strings.Join(e.docker.calls, ",") != "pause c1,unpause c1" {
		t.Fatalf("docker calls %v", e.docker.calls)
	}
	sum := sha256.Sum256(st.objects["/snap.tar.zst"])
	if hex.EncodeToString(sum[:]) != ar.SHA256 || int64(len(st.objects["/snap.tar.zst"])) != ar.SizeBytes {
		t.Fatal("checksum / size of the upload differ from the result")
	}

	dst := sized(id2)
	mp := e.fs.P("/var/lib/falak/volumes/" + id2)
	e.mounted[mp] = true
	source := Location{Kind: "url", URL: st.url("snap.tar.zst")}

	// A wrong checksum writes nothing.
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: dst, SizeBytes: 16 << 20, Source: source, SHA256: strings.Repeat("0", 64)}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "checksum mismatch") {
		t.Fatalf("mismatch: %v", err)
	}
	if ok, _ := emptyDir(mp); !ok {
		t.Fatal("a failed checksum wrote files")
	}
	res, err = e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: dst, SizeBytes: 16 << 20, Source: source, SHA256: ar.SHA256, UncompressedBytes: ar.UncompressedBytes}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if rr := res.(RestoreResult); rr.Files != 2 || rr.Bytes != 100005 {
		t.Fatalf("%+v", rr)
	}
	if b, _ := os.ReadFile(filepath.Join(mp, "dir/b.bin")); len(b) != 100000 {
		t.Fatal("dir/b.bin not restored")
	}
	if fi, _ := os.Stat(filepath.Join(mp, "a.txt")); fi.Mode().Perm() != 0o640 {
		t.Fatalf("mode %v", fi.Mode())
	}
	if fi, _ := os.Stat(filepath.Join(mp, "empty")); fi == nil || !fi.IsDir() || fi.Mode().Perm() != 0o750 {
		t.Fatal("empty dir not restored with its mode")
	}
	if l, err := os.Readlink(filepath.Join(mp, "passwd")); err != nil || l != "/etc/passwd" {
		t.Fatalf("symlink %q %v", l, err)
	}

	// Restores go into new or empty volumes only.
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: dst, SizeBytes: 16 << 20, Source: source, SHA256: ar.SHA256}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "not empty") {
		t.Fatalf("non-empty target: %v", err)
	}
	// Free space is checked against the snapshot's size.
	e.svc.d.StatFS = func(string) (Usage, error) { return Usage{Available: 10}, nil }
	dst3 := Ref{ID: "01j9z8y7x6w5v4t3s2r1q0p9nc", Kind: KindDocker, Name: "restored"}
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: dst3, Source: source, SHA256: ar.SHA256, UncompressedBytes: ar.UncompressedBytes}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "free") {
		t.Fatalf("free space: %v", err)
	}
}

// testEnc is the snapshots' key in tests (control-plane held).
var testEnc = backupcrypt.Encryption{Mode: "cp", KeyID: "01hzybackup000000000000001", Key: strings.Repeat("ab", 32)}

func emptyDir(p string) (bool, error) {
	es, err := os.ReadDir(p)
	return len(es) == 0, err
}

func tarZst(t *testing.T, entries ...*tar.Header) []byte {
	t.Helper()
	var buf bytes.Buffer
	zw, err := testEnc.Seal(&buf)
	if err != nil {
		t.Fatal(err)
	}
	tw := tar.NewWriter(zw)
	for _, h := range entries {
		if h.Typeflag == tar.TypeReg && h.Size == 0 {
			h.Size = 4
		}
		if h.Mode == 0 {
			h.Mode = 0o644
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if h.Typeflag == tar.TypeReg {
			_, _ = tw.Write([]byte("evil"))
		}
	}
	tw.Close()
	zw.Close()
	return buf.Bytes()
}

func TestRestoreRefusesEntriesLeavingTheVolume(t *testing.T) {
	st := newStore(t)
	e := newEnv(t, func(d *Deps) { d.HTTP = st.srv.Client() })
	outside := e.fs.P("/outside")
	if err := os.MkdirAll(outside, 0o755); err != nil {
		t.Fatal(err)
	}
	for name, archive := range map[string][]byte{
		"dotdot":   tarZst(t, &tar.Header{Name: "../outside/evil", Typeflag: tar.TypeReg}),
		"nested":   tarZst(t, &tar.Header{Name: "a/../../outside/evil", Typeflag: tar.TypeReg}),
		"absolute": tarZst(t, &tar.Header{Name: "/outside/evil", Typeflag: tar.TypeReg}),
		"symlink then write": tarZst(t,
			&tar.Header{Name: "link", Typeflag: tar.TypeSymlink, Linkname: outside},
			&tar.Header{Name: "link/evil", Typeflag: tar.TypeReg}),
		"symlink then overwrite": tarZst(t,
			&tar.Header{Name: "evil", Typeflag: tar.TypeSymlink, Linkname: filepath.Join(outside, "evil")},
			&tar.Header{Name: "evil", Typeflag: tar.TypeReg}),
		"hardlink out": tarZst(t, &tar.Header{Name: "h", Typeflag: tar.TypeLink, Linkname: "../outside/x"}),
	} {
		t.Run(name, func(t *testing.T) {
			st.objects["/"+name] = archive
			sum := sha256.Sum256(archive)
			vol := Ref{ID: id1, Kind: KindDocker, Name: strings.ReplaceAll(name, " ", "-")}
			_, err := e.svc.Restore(context.Background(), RestorePayload{Encryption: testEnc, Volume: vol, Source: Location{Kind: "url", URL: st.srv.URL + "/" + name}, SHA256: hex.EncodeToString(sum[:])}, &nopStream{})
			if err == nil {
				t.Fatal("restore accepted an escaping entry")
			}
			if ok, _ := emptyDir(outside); !ok {
				t.Fatal("something was written outside the volume")
			}
		})
	}
}

func TestClone(t *testing.T) {
	e := newEnv(t, nil)
	ctx := context.Background()
	src := Ref{ID: id1, Kind: KindDocker, Name: "src"}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: src}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	write(t, e.fs, "/docker/src/x/y.txt", "data")
	e.docker.containers = func() []docker.ContainerSummary {
		return []docker.ContainerSummary{{ID: "c9", Names: []string{"/db"}, Mounts: []docker.MountPoint{{Name: "src"}}}}
	}
	res, err := e.svc.Clone(ctx, ClonePayload{Source: src, Target: Ref{ID: id2, Kind: KindDocker, Name: "dst"}, Consistency: "stop"}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if rr := res.(RestoreResult); rr.Files != 1 || rr.Bytes != 4 || len(rr.Containers) != 1 {
		t.Fatalf("%+v", rr)
	}
	if b, _ := e.fs.ReadFile("/docker/dst/x/y.txt"); string(b) != "data" {
		t.Fatal("not cloned")
	}
	if strings.Join(e.docker.calls, ",") != "stop c9,start c9" {
		t.Fatalf("%v", e.docker.calls)
	}
	if _, err := e.svc.Clone(ctx, ClonePayload{Source: src, Target: Ref{ID: id2, Kind: KindDocker, Name: "dst"}}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "not empty") {
		t.Fatalf("non-empty target: %v", err)
	}
}

// bindEnv has a bind volume at /srv/data with files, a symlink to /etc, and a symlink to a directory outside.
func bindEnv(t *testing.T, st *store) (*env, Ref) {
	e := newEnv(t, func(d *Deps) {
		d.BindAllow = []string{"/srv"}
		if st != nil {
			d.HTTP = st.srv.Client()
		}
	})
	write(t, e.fs, "/srv/data/uploads/2026/a.jpg", "jpeg")
	write(t, e.fs, "/srv/data/uploads/b.txt", "text-b")
	write(t, e.fs, "/srv/data/README", "readme")
	write(t, e.fs, "/outside/secret", "secret")
	if err := os.Symlink("/etc", e.fs.P("/srv/data/etc")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("../../outside", e.fs.P("/srv/data/out")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("uploads", e.fs.P("/srv/data/inside")); err != nil {
		t.Fatal(err)
	}
	return e, Ref{ID: id1, Kind: KindBind, Path: "/srv/data"}
}

func TestBrowseIsConfined(t *testing.T) {
	e, vol := bindEnv(t, nil)
	ctx := context.Background()
	res, err := e.svc.Browse(ctx, BrowsePayload{Volume: vol}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	br := res.(BrowseResult)
	var got []string
	for _, en := range br.Entries {
		got = append(got, en.Name+":"+en.Type)
	}
	if strings.Join(got, ",") != "uploads:dir,README:file,etc:symlink,inside:symlink,out:symlink" || br.Total != 5 {
		t.Fatalf("%v", got)
	}
	res, err = e.svc.Browse(ctx, BrowsePayload{Volume: vol, Path: "uploads", Limit: 1}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if br := res.(BrowseResult); len(br.Entries) != 1 || br.Entries[0].Path != "uploads/2026" || !br.Truncated || br.Total != 2 {
		t.Fatalf("%+v", br)
	}
	for _, p := range []string{"etc", "out", "inside", "out/secret", "etc/passwd"} {
		if _, err := e.svc.Browse(ctx, BrowsePayload{Volume: vol, Path: p}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "symbolic link") {
			t.Errorf("%s: %v", p, err)
		}
	}
	for _, p := range []string{"../outside", "uploads/../../outside", "/etc", "uploads/./2026"} {
		if _, err := e.svc.Browse(ctx, BrowsePayload{Volume: vol, Path: p}, &nopStream{}); !isPayload(err) {
			t.Errorf("%s: %v", p, err)
		}
	}
	res, err = e.svc.Browse(ctx, BrowsePayload{Volume: vol, Search: "B.T"}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if br := res.(BrowseResult); len(br.Entries) != 1 || br.Entries[0].Path != "uploads/b.txt" {
		t.Fatalf("%+v", br)
	}
}

func TestDownload(t *testing.T) {
	st := newStore(t)
	e, vol := bindEnv(t, st)
	ctx := context.Background()
	dest := Location{Kind: "presigned_url", URL: st.url("dl")}
	res, err := e.svc.Download(ctx, DownloadPayload{Volume: vol, Path: "uploads/b.txt", Destination: dest, MaxBytes: 100}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if dr := res.(DownloadResult); dr.Format != "raw" || dr.Name != "b.txt" || string(st.objects["/dl"]) != "text-b" {
		t.Fatalf("%+v", dr)
	}
	res, err = e.svc.Download(ctx, DownloadPayload{Volume: vol, Path: "uploads", Destination: dest, MaxBytes: 100}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if dr := res.(DownloadResult); dr.Format != "tar.zst" || dr.Name != "uploads.tar.zst" || dr.Files != 2 {
		t.Fatalf("%+v", dr)
	}
	zr, _ := zstd.NewReader(bytes.NewReader(st.objects["/dl"]))
	tr := tar.NewReader(zr)
	var names []string
	for {
		h, err := tr.Next()
		if err != nil {
			break
		}
		names = append(names, h.Name)
	}
	if strings.Join(names, ",") != "2026/,2026/a.jpg,b.txt" {
		t.Fatalf("%v", names)
	}
	if _, err := e.svc.Download(ctx, DownloadPayload{Volume: vol, Path: "uploads", Destination: dest, MaxBytes: 5}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "limit") {
		t.Fatalf("cap: %v", err)
	}
	for _, p := range []string{"out/secret", "etc/passwd", "out", "inside/b.txt"} {
		if _, err := e.svc.Download(ctx, DownloadPayload{Volume: vol, Path: p, Destination: dest, MaxBytes: 100}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "symbolic link") {
			t.Errorf("%s: %v", p, err)
		}
	}
	if _, err := e.svc.Download(ctx, DownloadPayload{Volume: vol, Path: "../outside/secret", Destination: dest, MaxBytes: 100}, &nopStream{}); !isPayload(err) {
		t.Fatalf("dotdot: %v", err)
	}
}

func TestBindAndSharedPathConfinement(t *testing.T) {
	e := newEnv(t, func(d *Deps) { d.BindAllow = []string{"/srv/data"} })
	if err := e.fs.MkdirAll("/srv/data", 0o755); err != nil {
		t.Fatal(err)
	}
	if err := e.fs.MkdirAll("/outside", 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(e.fs.P("/outside"), e.fs.P("/srv/data/link")); err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	// The allowlisted directory itself is refused, like the control plane does; only what is below it is allowed.
	for _, p := range []string{"/srv/data", "/srv/other", "/srv/datax", "/srv/data/../x", "relative"} {
		if _, err := e.svc.Create(ctx, CreatePayload{Volume: Ref{ID: id1, Kind: KindBind, Path: p}}, &nopStream{}); !isPayload(err) {
			t.Errorf("bind %s accepted: %v", p, err)
		}
	}
	// A symlink on the way is never followed (nothing is created outside).
	for _, p := range []string{"/srv/data/link", "/srv/data/link/sub"} {
		if _, err := e.svc.Create(ctx, CreatePayload{Volume: Ref{ID: id1, Kind: KindBind, Path: p}}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "symbolic link") {
			t.Errorf("bind %s accepted: %v", p, err)
		}
	}
	if ok, _ := emptyDir(e.fs.P("/outside")); !ok {
		t.Fatal("created through a symlink")
	}
	res, err := e.svc.Create(ctx, CreatePayload{Volume: Ref{ID: id1, Kind: KindBind, Path: "/srv/data/media"}}, &nopStream{})
	if err != nil || !res.(CreateResult).Created || !e.fs.Exists("/srv/data/media") {
		t.Fatalf("%+v %v", res, err)
	}
	// Bind data is never deleted.
	res, err = e.svc.Delete(ctx, DeletePayload{Volume: Ref{ID: id1, Kind: KindBind, Path: "/srv/data/media"}}, &nopStream{})
	if err != nil || res.(DeleteResult).Deleted || !e.fs.Exists("/srv/data/media") {
		t.Fatalf("%+v %v", res, err)
	}
	// No allowlist: bind volumes are refused.
	none := newEnv(t, nil)
	if _, err := none.svc.Create(ctx, CreatePayload{Volume: Ref{ID: id1, Kind: KindBind, Path: "/srv/data"}}, &nopStream{}); !isPayload(err) {
		t.Fatalf("no allowlist: %v", err)
	}
	for p, ok := range map[string]bool{
		"/srv/falak/sites/shop/shared/storage":   true,
		"/srv/falak/sites/shop/shared":           false,
		"/srv/falak/sites/shop/current/storage":  false,
		"/srv/falak/sites/shop/shared/../../etc": false,
		"/etc/shared/x":                          false,
	} {
		_, err := e.svc.Create(ctx, CreatePayload{Volume: Ref{ID: id1, Kind: KindSharedPath, Path: p}}, &nopStream{})
		if (err == nil) != ok {
			t.Errorf("shared %s: %v", p, err)
		}
	}
}

func TestInventory(t *testing.T) {
	e := newEnv(t, nil)
	ctx := context.Background()
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: sized(id1), SizeBytes: 16 << 20}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	e.mounted[e.fs.P("/var/lib/falak/volumes/"+id1)] = true
	dv := Ref{ID: id2, Kind: KindDocker, Name: "pg"}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: dv}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	write(t, e.fs, "/docker/pg/base/1", strings.Repeat("a", 300))
	write(t, e.fs, "/docker/pg/base/2", strings.Repeat("a", 200))
	_ = os.Symlink("/", e.fs.P("/docker/pg/root")) // never followed
	e.docker.containers = func() []docker.ContainerSummary {
		return []docker.ContainerSummary{{ID: "c", Names: []string{"/shop-db-1"}, Mounts: []docker.MountPoint{{Name: "pg"}}}}
	}
	missing := Ref{ID: "01j9z8y7x6w5v4t3s2r1q0p9nc", Kind: KindDocker, Name: "gone"}
	res, err := e.svc.Inventory(ctx, InventoryPayload{Volumes: []Ref{sized(id1), dv, missing}}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	inv := res.(InventoryResult)
	s, d, m := inv.Volumes[0], inv.Volumes[1], inv.Volumes[2]
	if !s.Exists || *s.UsedBytes != 400 || *s.SizeBytes != 1000 || *s.AvailableBytes != 600 || !*s.Mounted {
		t.Fatalf("sized %+v", s)
	}
	if !d.Exists || *d.UsedBytes != 500 || strings.Join(d.Containers, ",") != "shop-db-1" {
		t.Fatalf("docker %+v", d)
	}
	if m.Exists || m.UsedBytes != nil {
		t.Fatalf("missing %+v", m)
	}
	if len(inv.Docker) != 1 || inv.Docker[0].Name != "pg" || inv.Docker[0].Containers[0] != "shop-db-1" {
		t.Fatalf("%+v", inv.Docker)
	}
	// An unmounted sized volume reports no usage (its mountpoint is the host's disk).
	e.mounted = map[string]bool{}
	res, _ = e.svc.Inventory(ctx, InventoryPayload{Volumes: []Ref{sized(id1)}}, &nopStream{})
	if u := res.(InventoryResult).Volumes[0]; u.UsedBytes != nil || u.Error != "not mounted" {
		t.Fatalf("%+v", u)
	}
	if _, err := e.svc.Browse(ctx, BrowsePayload{Volume: sized(id1)}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "not mounted") {
		t.Fatalf("browse unmounted: %v", err)
	}
}

func TestPayloadValidation(t *testing.T) {
	e := newEnv(t, nil)
	ctx := context.Background()
	for _, r := range []Ref{
		{ID: "01J9Z8Y7X6W5V4T3S2R1Q0P9NA", Kind: KindSized},
		{ID: id1, Kind: "tmpfs"},
		{ID: id1, Kind: KindDocker, Name: "-bad"},
		{ID: id1, Kind: KindDocker},
	} {
		if _, err := e.svc.Create(ctx, CreatePayload{Volume: r, SizeBytes: 16 << 20}, &nopStream{}); !isPayload(err) {
			t.Errorf("%+v: %v", r, err)
		}
	}
	if _, err := e.svc.Resize(ctx, ResizePayload{Volume: Ref{ID: id1, Kind: KindDocker, Name: "x"}, SizeBytes: 16 << 20}, &nopStream{}); !isPayload(err) {
		t.Errorf("resize docker: %v", err)
	}
	if _, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: sized(id1), Destination: Location{Kind: "presigned_url", URL: "http://x"}}, &nopStream{}); !isPayload(err) {
		t.Errorf("http destination: %v", err)
	}
}

func TestUploadErrorsNeverCarryTheSignature(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {}))
	c := srv.Client()
	srv.Close()
	e := newEnv(t, func(d *Deps) { d.HTTP = c })
	vol := Ref{ID: id1, Kind: KindDocker, Name: "v"}
	if _, err := e.svc.Create(context.Background(), CreatePayload{Volume: vol}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	_, err := e.svc.Archive(context.Background(), ArchivePayload{Encryption: testEnc, Volume: vol, Destination: Location{Kind: "presigned_url", URL: srv.URL + "/k?X-Amz-Signature=topsecret"}}, &nopStream{})
	if err == nil || strings.Contains(err.Error(), "topsecret") {
		t.Fatalf("%v", err)
	}
}

// sharedEnv has a classic site's shared path /srv/falak/sites/shop/shared/storage and a secret outside it.
func sharedEnv(t *testing.T, st *store) (*env, Ref) {
	e := newEnv(t, func(d *Deps) {
		if st != nil {
			d.HTTP = st.srv.Client()
		}
	})
	write(t, e.fs, "/srv/falak/sites/shop/shared/storage/app.log", "log")
	write(t, e.fs, "/outside/shadow", "secret")
	return e, Ref{ID: id1, Kind: KindSharedPath, Path: "/srv/falak/sites/shop/shared/storage"}
}

// swap replaces a directory with a symlink to /outside (what a site's user can do to its own shared directory).
func swap(t *testing.T, e *env, dir string) {
	t.Helper()
	if err := os.RemoveAll(e.fs.P(dir)); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(e.fs.P("/outside"), e.fs.P(dir)); err != nil {
		t.Fatal(err)
	}
}

func TestSharedPathSymlinkSwapsAreRefused(t *testing.T) {
	st := newStore(t)
	ctx := context.Background()
	dest := Location{Kind: "presigned_url", URL: st.url("snap")}
	for _, dir := range []string{"/srv/falak/sites/shop/shared/storage", "/srv/falak/sites/shop/shared"} {
		t.Run(dir, func(t *testing.T) {
			e, vol := sharedEnv(t, st)
			swap(t, e, dir)
			if _, err := e.svc.Browse(ctx, BrowsePayload{Volume: vol}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "symbolic link") {
				t.Fatalf("browse: %v", err)
			}
			if _, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: vol, Destination: dest}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "symbolic link") {
				t.Fatalf("archive: %v", err)
			}
			if _, err := e.svc.Download(ctx, DownloadPayload{Volume: vol, Destination: dest, MaxBytes: 100}, &nopStream{}); err == nil {
				t.Fatal("download followed the symlink")
			}
			if _, err := e.svc.Delete(ctx, DeletePayload{Volume: vol}, &nopStream{}); err == nil {
				t.Fatal("delete followed the symlink")
			}
			if b, err := e.fs.ReadFile("/outside/shadow"); err != nil || string(b) != "secret" {
				t.Fatal("the file outside was touched")
			}
			if _, ok := st.objects["/snap"]; ok {
				t.Fatal("something outside was uploaded")
			}
		})
	}
}

func TestSharedPathSwappedWhileOpening(t *testing.T) {
	e, vol := sharedEnv(t, nil)
	// The directory is swapped between its check and its opening.
	descendHook = func(comp string) {
		if comp == "storage" {
			swap(t, e, "/srv/falak/sites/shop/shared/storage")
		}
	}
	t.Cleanup(func() { descendHook = nil })
	if _, err := e.svc.Browse(context.Background(), BrowsePayload{Volume: vol}, &nopStream{}); err == nil {
		t.Fatal("browse opened a directory swapped for a symlink")
	}
}

func TestSharedPathDelete(t *testing.T) {
	e, vol := sharedEnv(t, nil)
	res, err := e.svc.Delete(context.Background(), DeletePayload{Volume: vol}, &nopStream{})
	if err != nil || !res.(DeleteResult).Deleted || e.fs.Exists("/srv/falak/sites/shop/shared/storage") || !e.fs.Exists("/srv/falak/sites/shop/shared") {
		t.Fatalf("%+v %v", res, err)
	}
}

func TestArchiveKeepsMovedServicesStopped(t *testing.T) {
	st := newStore(t)
	e := newEnv(t, func(d *Deps) { d.HTTP = st.srv.Client() })
	ctx := context.Background()
	src := Ref{ID: id1, Kind: KindDocker, Name: "data"}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: src}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	e.docker.containers = func() []docker.ContainerSummary {
		return []docker.ContainerSummary{{ID: "c1", Names: []string{"/api"}, Mounts: []docker.MountPoint{{Name: "data"}}}}
	}
	dest := Location{Kind: "presigned_url", URL: st.url("move")}
	if _, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: src, Consistency: "pause", KeepStopped: true, Destination: dest}, &nopStream{}); !isPayload(err) {
		t.Fatalf("keep_stopped without stop: %v", err)
	}
	res, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: src, Consistency: "stop", KeepStopped: true, Destination: dest}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(res.(ArchiveResult).Containers, ",") != "api" || strings.Join(e.docker.calls, ",") != "stop c1" {
		t.Fatalf("%+v %v", res, e.docker.calls)
	}
	// A failed upload starts them again: the source keeps serving.
	e.docker.calls = nil
	bad := Location{Kind: "presigned_url", URL: "https://127.0.0.1:1/x"}
	if _, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: src, Consistency: "stop", KeepStopped: true, Destination: bad}, &nopStream{}); err == nil {
		t.Fatal("upload to nowhere succeeded")
	}
	if strings.Join(e.docker.calls, ",") != "stop c1,start c1" {
		t.Fatalf("%v", e.docker.calls)
	}
}

func TestStagingIsOnTheVolumeStoreAndChecksFreeSpace(t *testing.T) {
	st := newStore(t)
	var statted []string
	free := uint64(1 << 30)
	e := newEnv(t, func(d *Deps) {
		d.HTTP = st.srv.Client()
		d.StatFS = func(p string) (Usage, error) {
			statted = append(statted, p)
			return Usage{Size: 1 << 31, Available: free}, nil
		}
	})
	ctx := context.Background()
	src := Ref{ID: id1, Kind: KindDocker, Name: "data"}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: src}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
	write(t, e.fs, "/docker/data/big", strings.Repeat("x", 1000))
	dest := Location{Kind: "presigned_url", URL: st.url("snap")}
	res, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: src, Destination: dest}, &nopStream{})
	if err != nil {
		t.Fatal(err)
	}
	staging := e.fs.P("/var/lib/falak/volumes/.staging")
	if fi, err := os.Stat(staging); err != nil || fi.Mode().Perm() != 0o700 || len(statted) == 0 || statted[0] != staging {
		t.Fatalf("staging %v %v %v", fi, err, statted)
	}
	if left, _ := os.ReadDir(staging); len(left) != 0 {
		t.Fatalf("staging files left: %v", left)
	}
	free = 1000 // under the 1000 bytes + 10%
	if _, err := e.svc.Archive(ctx, ArchivePayload{Encryption: testEnc, Volume: src, Destination: dest}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "not enough free space") {
		t.Fatalf("free space: %v", err)
	}

	// Restores download at most the recorded archive size (+1 MiB).
	free = 1 << 30
	ar := res.(ArchiveResult)
	st.objects["/huge"] = append(append([]byte{}, st.objects["/snap"]...), make([]byte, 2<<20)...)
	sum := sha256.Sum256(st.objects["/huge"])
	_, err = e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: Ref{ID: id2, Kind: KindDocker, Name: "dst"}, Source: Location{Kind: "url", URL: st.url("huge")},
		SHA256: hex.EncodeToString(sum[:]), ArchiveBytes: ar.SizeBytes}, &nopStream{})
	if err == nil || !strings.Contains(err.Error(), "recorded") {
		t.Fatalf("oversized download: %v", err)
	}
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: Ref{ID: id2, Kind: KindDocker, Name: "dst"}, Source: Location{Kind: "url", URL: st.url("snap")},
		SHA256: ar.SHA256, ArchiveBytes: ar.SizeBytes, UncompressedBytes: ar.UncompressedBytes}, &nopStream{}); err != nil {
		t.Fatal(err)
	}
}

func TestCreateNeverAdoptsAnotherDockerVolume(t *testing.T) {
	e := newEnv(t, nil)
	ctx := context.Background()
	if _, err := e.docker.VolumeCreate(ctx, "pgdata", map[string]string{"com.docker.compose.project": "other"}); err != nil {
		t.Fatal(err)
	}
	vol := Ref{ID: id1, Kind: KindDocker, Name: "pgdata"}
	if _, err := e.svc.Create(ctx, CreatePayload{Volume: vol}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "not this volume") {
		t.Fatalf("adopted: %v", err)
	}
	if _, err := e.svc.Restore(ctx, RestorePayload{Encryption: testEnc, Volume: vol, Source: Location{Kind: "url", URL: "https://x.example/s"}, SHA256: strings.Repeat("a", 64)}, &nopStream{}); err == nil || !strings.Contains(err.Error(), "not this volume") {
		t.Fatalf("restore adopted: %v", err)
	}
	if res, err := e.svc.Create(ctx, CreatePayload{Volume: vol, Adopt: true}, &nopStream{}); err != nil || res.(CreateResult).Created {
		t.Fatalf("adopt: %+v %v", res, err)
	}
	// A retried create of the same volume is fine: Falak labels what it creates with the volume's id.
	mine := Ref{ID: id2, Kind: KindDocker, Name: "mine"}
	for i, created := range []bool{true, false} {
		res, err := e.svc.Create(ctx, CreatePayload{Volume: mine}, &nopStream{})
		if err != nil || res.(CreateResult).Created != created {
			t.Fatalf("create %d: %+v %v", i, res, err)
		}
	}
	if v, _, _ := e.docker.VolumeInspect(ctx, "mine"); v.Labels[idLabel] != id2 {
		t.Fatalf("labels %v", v.Labels)
	}
}
