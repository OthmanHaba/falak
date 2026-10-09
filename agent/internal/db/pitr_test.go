package db

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"filippo.io/age"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/runner/runnertest"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

// objectStore is a fake S3 bucket behind presigned URLs (PUT and GET /o/<key>).
type objectStore struct {
	mu      sync.Mutex
	objects map[string][]byte
	srv     *httptest.Server
	failPut func(key string) bool
}

func newObjectStore(t *testing.T) *objectStore {
	s := &objectStore{objects: map[string][]byte{}}
	s.srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		key := strings.TrimPrefix(r.URL.Path, "/o/")
		s.mu.Lock()
		defer s.mu.Unlock()
		switch r.Method {
		case http.MethodPut:
			if s.failPut != nil && s.failPut(key) {
				w.WriteHeader(http.StatusInternalServerError)
				return
			}
			b, _ := io.ReadAll(r.Body)
			s.objects[key] = b
		case http.MethodGet:
			b, ok := s.objects[key]
			if !ok {
				w.WriteHeader(http.StatusNotFound)
				return
			}
			w.Write(b)
		}
	}))
	t.Cleanup(s.srv.Close)
	return s
}

func (s *objectStore) url(key string) string { return s.srv.URL + "/o/" + key + "?X-Amz-Signature=sig" }

func (s *objectStore) get(key string) []byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.objects[key]
}

// fakeCP answers the shipper's requests like the control plane: a fresh data key per segment (or the customer's
// recipient), acknowledgments, gaps.
type fakeCP struct {
	mu        sync.Mutex
	store     *objectStore
	recipient string
	keys      map[string]backupcrypt.Encryption
	shipped   map[string]shippedSegment // id → acknowledged segment
	have      map[string]string         // name + sha256 → id, already shipped
	asked     []int                     // batch sizes of pitr.upload_urls
	gaps      []binlogGap
	down      bool
	// failShipped fails pitr.shipped; ackOnly acknowledges only some ids.
	failShipped bool
	ackOnly     func(id string) bool
	seq         int
}

func newFakeCP(store *objectStore) *fakeCP {
	return &fakeCP{store: store, keys: map[string]backupcrypt.Encryption{}, shipped: map[string]shippedSegment{}, have: map[string]string{}}
}

func (f *fakeCP) Request(_ context.Context, typ string, in, out any) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.down {
		return &transport.StatusError{Code: http.StatusBadGateway, Body: "control plane down"}
	}
	b, _ := json.Marshal(in)
	var reply any
	switch typ {
	case "pitr.upload_urls":
		var req struct {
			Segments []struct {
				Name   string `json:"name"`
				Bytes  int64  `json:"bytes"`
				SHA256 string `json:"sha256"`
			} `json:"segments"`
		}
		json.Unmarshal(b, &req)
		f.asked = append(f.asked, len(req.Segments))
		var slots []uploadSlot
		for _, s := range req.Segments {
			if id, ok := f.have[s.Name+s.SHA256]; ok {
				slots = append(slots, uploadSlot{Name: s.Name, ID: id, Shipped: true})
				continue
			}
			f.seq++
			id := fmt.Sprintf("01hzyseg%018d", f.seq)
			enc := backupcrypt.Encryption{Mode: "age", KeyID: id, Recipient: f.recipient}
			if f.recipient == "" {
				key := make([]byte, 32)
				rand.Read(key)
				enc = backupcrypt.Encryption{Mode: "cp", KeyID: id, Key: base64.StdEncoding.EncodeToString(key)}
			}
			f.keys[id] = enc
			slots = append(slots, uploadSlot{Name: s.Name, ID: id, URL: f.store.url(id), Encryption: enc})
		}
		reply = map[string]any{"segments": slots}
	case "pitr.shipped":
		if f.failShipped {
			return &transport.StatusError{Code: http.StatusServiceUnavailable}
		}
		var req struct {
			Segments []shippedSegment `json:"segments"`
		}
		json.Unmarshal(b, &req)
		acked := []string{}
		for _, s := range req.Segments {
			if f.ackOnly != nil && !f.ackOnly(s.ID) {
				continue
			}
			f.shipped[s.ID] = s
			f.have[s.Name+s.PlaintextSHA256] = s.ID
			acked = append(acked, s.ID)
		}
		reply = map[string]any{"acknowledged": acked}
	case "pitr.gap":
		var req struct {
			Gaps []binlogGap `json:"gaps"`
		}
		json.Unmarshal(b, &req)
		f.gaps = append(f.gaps, req.Gaps...)
		reply = map[string]any{}
	default:
		return fmt.Errorf("unknown request %s", typ)
	}
	if out != nil {
		rb, _ := json.Marshal(reply)
		return json.Unmarshal(rb, out)
	}
	return nil
}

// pitrHarness is a harness with PITR on for one instance and a shipper talking to a fake control plane.
type pitrHarness struct {
	*harness
	store *objectStore
	cp    *fakeCP
	ship  *Shipper
	spool string
}

func newPITRHarness(t *testing.T, engine string) *pitrHarness {
	h := newHarness(t)
	store := newObjectStore(t)
	h.db.d.HTTP = store.srv.Client()
	h.db.d.TotalBytes = func(string) (int64, error) { return 10 << 30, nil }
	cp := newFakeCP(store)
	s := spec()
	s.Engine, s.PITR = engine, &PITRSpec{Enabled: true}
	if err := h.db.writePITR(s); err != nil {
		t.Fatal(err)
	}
	ship := h.db.NewShipper(cp)
	ship.BatchFiles = 2
	spool := h.path(filepath.Join("/var/lib/falak/volumes", volID, "spool", spoolKind(engine)))
	os.MkdirAll(spool, 0o700)
	return &pitrHarness{harness: h, store: store, cp: cp, ship: ship, spool: spool}
}

func (p *pitrHarness) spoolFiles(t *testing.T, names ...string) {
	t.Helper()
	for i, n := range names {
		if err := os.WriteFile(filepath.Join(p.spool, n), []byte(strings.Repeat(n, 100+i)), 0o600); err != nil {
			t.Fatal(err)
		}
	}
}

func (p *pitrHarness) left() []string {
	entries, _ := os.ReadDir(p.spool)
	var out []string
	for _, e := range entries {
		out = append(out, e.Name())
	}
	return out
}

func TestShipperShipsInBatchesAndDeletesOnlyAcknowledgedFiles(t *testing.T) {
	p := newPITRHarness(t, "postgres")
	p.spoolFiles(t, "000000010000000000000001", "000000010000000000000002", "000000010000000000000003", ".tmp-falak-db", "000000010000000000000004", "000000010000000000000005")
	p.ship.RunOnce(context.Background())
	if fmt.Sprint(p.cp.asked) != "[2 2 1]" {
		t.Fatalf("batches %v", p.cp.asked)
	}
	// falak-db's dot-files are never shipped nor deleted.
	if got := p.left(); fmt.Sprint(got) != "[.tmp-falak-db]" {
		t.Fatalf("left in the spool: %v", got)
	}
	if len(p.cp.shipped) != 5 {
		t.Fatalf("shipped %d", len(p.cp.shipped))
	}
	// Every segment is its own FKB1 file under its own key, and opens to the spool file's content.
	keys := map[string]bool{}
	for id, seg := range p.cp.shipped {
		enc := p.cp.keys[id]
		if keys[enc.Key] {
			t.Fatal("two segments share a key")
		}
		keys[enc.Key] = true
		stored := p.store.get(id)
		if sum := sha256.Sum256(stored); hex.EncodeToString(sum[:]) != seg.SHA256 || int64(len(stored)) != seg.SizeBytes {
			t.Fatalf("%s: the reported sha256 / size are not the stored file's", seg.Name)
		}
		r, err := enc.Open(bytes.NewReader(stored))
		if err != nil {
			t.Fatal(err)
		}
		plain, err := io.ReadAll(r)
		if err != nil {
			t.Fatal(err)
		}
		if !strings.HasPrefix(string(plain), seg.Name) || seg.PlaintextBytes != int64(len(plain)) || seg.EndTime.IsZero() {
			t.Fatalf("%s: plaintext %q, %+v", seg.Name, plain[:10], seg)
		}
		if _, err := testEnc.Open(bytes.NewReader(stored)); err == nil {
			t.Fatal("a segment opened with another key")
		}
	}
	r := p.db.pitrReport(instID, p.db.pitrConfigs())
	if r == nil || r.Pending != 0 || r.LastShippedAt == nil || r.Error != "" {
		t.Fatalf("report %+v", r)
	}
}

func TestShipperNeverDeletesUnacknowledgedFiles(t *testing.T) {
	p := newPITRHarness(t, "postgres")
	p.spoolFiles(t, "000000010000000000000001", "000000010000000000000002", "000000010000000000000003")
	ctx := context.Background()

	// Uploaded, but the acknowledgment fails: everything stays.
	p.cp.failShipped = true
	p.ship.RunOnce(ctx)
	if len(p.left()) != 3 {
		t.Fatalf("left %v", p.left())
	}
	st := p.ship.st(instID)
	if st.lastErr == "" || !st.retryAt.After(time.Now()) {
		t.Fatalf("no backoff after a failure: %+v", st)
	}
	// Backing off: nothing is asked meanwhile.
	asked := len(p.cp.asked)
	p.ship.RunOnce(ctx)
	if len(p.cp.asked) != asked {
		t.Fatal("asked again while backing off")
	}

	// Only some acknowledged: only those go.
	p.cp.failShipped = false
	p.cp.ackOnly = func(id string) bool { return strings.HasSuffix(id, "4") }
	st.retryAt = time.Time{}
	p.ship.RunOnce(ctx)
	if got := p.left(); len(got) != 2 {
		t.Fatalf("left %v", got)
	}

	// An upload that fails keeps its file (and the rest of the batch).
	p.cp.ackOnly = nil
	p.store.failPut = func(string) bool { return true }
	st.retryAt = time.Time{}
	p.ship.RunOnce(ctx)
	if got := p.left(); len(got) != 2 {
		t.Fatalf("left after failed uploads %v", got)
	}

	// The control plane already has a file (its acknowledgment was lost): it is deleted without a new upload.
	p.store.failPut = nil
	for id, seg := range map[string]string{"x1": "000000010000000000000001", "x2": "000000010000000000000002", "x3": "000000010000000000000003"} {
		if sum, err := testFileSHA256(filepath.Join(p.spool, seg)); err == nil {
			p.cp.have[seg+sum] = id
		}
	}
	before := len(p.cp.keys)
	st.retryAt = time.Time{}
	p.ship.RunOnce(ctx)
	if got := p.left(); len(got) != 0 || len(p.cp.keys) != before {
		t.Fatalf("left %v, new keys %d", got, len(p.cp.keys)-before)
	}
}

// With the control plane unreachable the spool only grows: nothing is deleted and the heartbeat shows the backlog.
func TestShipperKeepsSpoolingWhileTheControlPlaneIsUnreachable(t *testing.T) {
	p := newPITRHarness(t, "postgres")
	p.cp.down = true
	ctx := context.Background()
	for i := 1; i <= 4; i++ {
		p.spoolFiles(t, fmt.Sprintf("0000000100000000000000%02d", i))
		p.ship.st(instID).retryAt = time.Time{}
		p.ship.RunOnce(ctx)
	}
	if len(p.left()) != 4 {
		t.Fatalf("left %v", p.left())
	}
	r := p.db.pitrReport(instID, p.db.pitrConfigs())
	if r.Pending != 4 || r.SpoolBytes == 0 || r.OldestPendingAt == nil || r.VolumeBytes != 10<<30 || !strings.Contains(r.Error, "502") {
		t.Fatalf("report %+v", r)
	}
	p.cp.down = false
	p.ship.st(instID).retryAt = time.Time{}
	p.ship.RunOnce(ctx)
	if len(p.left()) != 0 {
		t.Fatalf("left %v", p.left())
	}
}

func TestShipperEncryptsToTheCustomersRecipient(t *testing.T) {
	p := newPITRHarness(t, "postgres")
	id, _ := age.GenerateX25519Identity()
	p.cp.recipient = id.Recipient().String()
	p.spoolFiles(t, "000000010000000000000001")
	p.ship.RunOnce(context.Background())
	for segID, seg := range p.cp.shipped {
		enc := backupcrypt.Encryption{Mode: "age", KeyID: segID, Identity: id.String()}
		sum, err := enc.Verify(bytes.NewReader(p.store.get(segID)), seg.PlaintextSHA256)
		if err != nil || sum.Bytes != seg.PlaintextBytes {
			t.Fatalf("%v %+v", err, sum)
		}
	}
	if len(p.cp.shipped) != 1 {
		t.Fatalf("shipped %d", len(p.cp.shipped))
	}
}

func TestShipperRotatesBinlogsAndReportsGapsOnce(t *testing.T) {
	p := newPITRHarness(t, "mysql")
	ctx := context.Background()
	p.dock.containers[Container(instID)] = runningContainer()
	gaps := `{"current":"binlog.000003","spooled":[],"gaps":[{"kind":"reset","from":"binlog.000009","to":"binlog.000003","detail":"reset"}]}`
	rotations := 0
	p.run.OnFunc("docker exec falak-db-"+instID+" falak-db binlog-rotate", func(c runnertest.Call) (runner.Result, error) {
		rotations++
		if strings.Contains(c.Line, "--restart") {
			return runner.Result{Stdout: []byte(`{"current":"binlog.000004","spooled":[{"name":"binlog.000003"}],"gaps":[]}`)}, nil
		}
		if rotations <= 2 {
			return runner.Result{Stdout: []byte(gaps), ExitCode: 4}, nil
		}
		return runner.Result{Stdout: []byte(`{"current":"binlog.000004","spooled":[],"gaps":[]}`)}, nil
	})
	p.ship.RunOnce(ctx)
	p.ship.st(instID).lastRotate = time.Time{}
	p.ship.st(instID).retryAt = time.Time{}
	p.ship.RunOnce(ctx)
	if rotations != 2 || len(p.cp.gaps) != 1 || p.cp.gaps[0].Kind != "reset" {
		t.Fatalf("rotations %d gaps %+v", rotations, p.cp.gaps)
	}
	if !p.db.loadPITRState(instID).RestartAfterBase {
		t.Fatal("a reset does not wait for a base backup")
	}

	// The next base backup restarts spooling.
	p.run.On("docker exec falak-db-"+instID+" falak-db backup physical", runner.Result{Stdout: []byte("xbstream"),
		Stderr: []byte(`falak-db-result: {"kind":"physical","started_at":"2026-10-09T12:00:00Z","finished_at":"2026-10-09T12:00:05Z"}` + "\n")})
	res, err := p.db.PITRBase(ctx, PITRBasePayload{Instance: instID, Engine: "mysql", Encryption: testEnc,
		Destination: Location{Kind: "presigned_url", URL: p.store.url("base")}}, stream())
	if err != nil {
		t.Fatal(err)
	}
	r := res.(PITRBaseResult)
	if r.StartBinlog != "binlog.000004" || r.StartedAt != "2026-10-09T12:00:00Z" || r.SizeBytes == 0 || opened(t, p.store.get("base")) != "xbstream" {
		t.Fatalf("base %+v", r)
	}
	if !p.run.Ran("docker exec falak-db-" + instID + " falak-db binlog-rotate --restart") {
		t.Fatalf("no restart after the base: %v", p.run.Lines())
	}
	if st := p.db.loadPITRState(instID); st.RestartAfterBase || len(st.Reported) != 0 {
		t.Fatalf("state %+v", st)
	}
}

// Binlogs spooled together (one closed by xtrabackup, the next by the rotation) end when the server last wrote them.
func TestShipperDatesBinlogsByTheirOriginal(t *testing.T) {
	p := newPITRHarness(t, "mysql")
	data := p.path(filepath.Join("/var/lib/falak/volumes", volID, "data"))
	os.MkdirAll(data, 0o700)
	p.spoolFiles(t, "binlog.000004", "binlog.000005")
	closed := time.Now().Add(-time.Hour).Truncate(time.Second).UTC()
	os.WriteFile(filepath.Join(data, "binlog.000004"), []byte("x"), 0o600)
	os.Chtimes(filepath.Join(data, "binlog.000004"), closed, closed)
	p.ship.RunOnce(context.Background())
	ends := map[string]time.Time{}
	for _, s := range p.cp.shipped {
		ends[s.Name] = s.EndTime
	}
	if !ends["binlog.000004"].Equal(closed) || !ends["binlog.000005"].After(closed) {
		t.Fatalf("end times %v", ends)
	}
}

func testFileSHA256(path string) (string, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:]), nil
}

// The engine's user owns spool/<kind>: whatever it plants there (symlinks, hard links) must never make the agent read,
// upload or delete a file outside the spool.
func TestShipperRefusesSymlinksInTheSpool(t *testing.T) {
	outside := t.TempDir()
	secretFile := filepath.Join(outside, "shadow")
	os.WriteFile(secretFile, []byte("root:secret"), 0o600)

	// A symlinked file and a hard link next to a real segment: only the real one ships, the others stay untouched.
	p := newPITRHarness(t, "postgres")
	p.spoolFiles(t, "000000010000000000000001")
	os.Symlink(secretFile, filepath.Join(p.spool, "000000010000000000000002"))
	hard := filepath.Join(outside, "hard")
	os.WriteFile(hard, []byte("other"), 0o600)
	if err := os.Link(hard, filepath.Join(p.spool, "000000010000000000000003")); err != nil {
		t.Log("no hard links here:", err)
	}
	p.ship.RunOnce(context.Background())
	if len(p.cp.shipped) != 1 {
		t.Fatalf("shipped %d segments", len(p.cp.shipped))
	}
	for _, s := range p.cp.shipped {
		if s.Name != "000000010000000000000001" {
			t.Fatalf("shipped %s", s.Name)
		}
	}
	if b, err := os.ReadFile(secretFile); err != nil || string(b) != "root:secret" {
		t.Fatalf("the symlink's target was touched: %q %v", b, err)
	}
	if _, err := os.Stat(hard); err != nil {
		t.Fatalf("the hard link's file was touched: %v", err)
	}

	// spool/wal itself a symlink to a host directory: refused, nothing there is listed, shipped or deleted, even with
	// PITR off (the spool is emptied then).
	p2 := newPITRHarness(t, "postgres")
	os.RemoveAll(p2.spool)
	victim := filepath.Join(outside, "etc")
	os.MkdirAll(victim, 0o755)
	os.WriteFile(filepath.Join(victim, "000000010000000000000009"), []byte("host file"), 0o644)
	os.Symlink(victim, p2.spool)
	if _, err := p2.db.pending(p2.db.pitrConfigs()[instID]); err == nil || !strings.Contains(err.Error(), "symlink") {
		t.Fatalf("pending through a symlinked spool: %v", err)
	}
	p2.ship.RunOnce(context.Background())
	s := spec()
	p2.db.writePITR(s) // off: the spool is emptied
	p2.ship.RunOnce(context.Background())
	if _, err := os.Stat(filepath.Join(victim, "000000010000000000000009")); err != nil || len(p2.cp.shipped) != 0 {
		t.Fatalf("a host file was shipped or deleted: %v, %d shipped", err, len(p2.cp.shipped))
	}
}

// An idle server's binlog does not grow: no new file every minute.
func TestShipperSkipsTheRotationOfAnIdleBinlog(t *testing.T) {
	p := newPITRHarness(t, "mysql")
	p.dock.containers[Container(instID)] = runningContainer()
	data := p.path(filepath.Join("/var/lib/falak/volumes", volID, "data"))
	os.MkdirAll(data, 0o700)
	os.WriteFile(filepath.Join(data, "binlog.000004"), []byte("header"), 0o600)
	p.run.On("docker exec falak-db-"+instID+" falak-db binlog-rotate", runner.Result{Stdout: []byte(`{"current":"binlog.000004","spooled":[],"gaps":[]}`)})
	ctx := context.Background()
	p.ship.RunOnce(ctx)
	p.ship.st(instID).lastRotate = time.Time{}
	p.ship.RunOnce(ctx)
	if n := len(p.run.Lines()); n != 1 {
		t.Fatalf("rotations %v", p.run.Lines())
	}
	os.WriteFile(filepath.Join(data, "binlog.000004"), []byte("header+event"), 0o600)
	p.ship.st(instID).lastRotate = time.Time{}
	p.ship.RunOnce(ctx)
	if n := len(p.run.Lines()); n != 2 {
		t.Fatalf("rotations %v", p.run.Lines())
	}
}

// Without PITR nobody ships the spool: postgres archives into it anyway, so it is emptied (falak-db's files stay).
func TestShipperEmptiesTheSpoolOfInstancesWithoutPITR(t *testing.T) {
	p := newPITRHarness(t, "postgres")
	s := spec()
	if err := p.db.writePITR(s); err != nil {
		t.Fatal(err)
	}
	p.spoolFiles(t, "000000010000000000000001", ".binlog-last")
	p.ship.RunOnce(context.Background())
	if got := p.left(); fmt.Sprint(got) != "[.binlog-last]" || len(p.cp.asked) != 0 {
		t.Fatalf("left %v, asked %v", got, p.cp.asked)
	}
	if p.db.pitrReport(instID, p.db.pitrConfigs()) != nil {
		t.Fatal("a report without PITR")
	}
}

func runningContainer() *docker.Container {
	c := &docker.Container{ID: "c-run", Name: "/" + Container(instID)}
	c.State.Running, c.State.Status = true, "running"
	return c
}

// ---- restore ----

// sealWith encrypts content into the store under key and returns the object.
func sealWith(t *testing.T, store *objectStore, key string, content []byte) PITRObject {
	t.Helper()
	k := make([]byte, 32)
	rand.Read(k)
	enc := backupcrypt.Encryption{Mode: "cp", KeyID: key, Key: base64.StdEncoding.EncodeToString(k)}
	var b bytes.Buffer
	w, err := enc.Seal(&b)
	if err != nil {
		t.Fatal(err)
	}
	w.Write(content)
	w.Close()
	store.mu.Lock()
	store.objects[key] = b.Bytes()
	store.mu.Unlock()
	sum, plain := sha256.Sum256(b.Bytes()), sha256.Sum256(content)
	return PITRObject{URL: store.url(key), SHA256: hex.EncodeToString(sum[:]), PlaintextSHA256: hex.EncodeToString(plain[:]), SizeBytes: int64(b.Len()),
		PlaintextBytes: int64(len(content)), Encryption: enc}
}

func restorePayload(t *testing.T, store *objectStore, engine string) PITRRestorePayload {
	s := spec()
	s.ID, s.VolumeID, s.Engine, s.Network, s.Settings = instID2, "01hzyvol000000000000000002", engine, "", nil
	segs := []PITRSegment{
		{Name: "binlog.000004", PITRObject: sealWith(t, store, "01hzyseg000000000000000001", []byte("binlog four"))},
		{Name: "binlog.000005", PITRObject: sealWith(t, store, "01hzyseg000000000000000002", []byte("binlog five"))},
	}
	if engine == "postgres" {
		segs[0].Name, segs[1].Name = "000000010000000000000004", "000000010000000000000005"
	}
	return PITRRestorePayload{Restore: "01hzyrestore00000000000001", Instance: s, Password: secret, Base: sealWith(t, store, "01hzybase0000000000000001", []byte("BASE-BACKUP")),
		Segments: segs, TargetTime: "2026-10-09T12:30:00.5Z", Databases: []string{"shop"}}
}

func TestPITRRestoreBuildsANewReadOnlyInstance(t *testing.T) {
	for _, engine := range []string{"postgres", "mysql"} {
		t.Run(engine, func(t *testing.T) {
			h := newHarness(t)
			store := newObjectStore(t)
			h.db.d.HTTP = store.srv.Client()
			h.db.d.FreeBytes = func(string) (int64, error) { return 100 << 30, nil }
			p := restorePayload(t, store, engine)
			replay := h.path(filepath.Join("/var/lib/falak/volumes", p.Instance.VolumeID, "spool", "replay"))
			var base string
			var staged []string
			h.run.OnFunc("docker run --rm -i --name falak-db-"+instID2+"-pitr", func(c runnertest.Call) (runner.Result, error) {
				base = c.Stdin
				return runner.Result{}, nil
			})
			h.run.OnFunc("docker run --rm --name falak-db-"+instID2+"-pitr", func(c runnertest.Call) (runner.Result, error) {
				entries, _ := os.ReadDir(replay)
				for _, e := range entries {
					b, _ := os.ReadFile(filepath.Join(replay, e.Name()))
					staged = append(staged, e.Name()+"="+string(b))
				}
				return runner.Result{Stdout: []byte(`{"mode":"on-start"}`)}, nil
			})
			h.run.OnFunc("docker exec falak-db-"+instID2+" falak-db recover", func(c runnertest.Call) (runner.Result, error) {
				entries, _ := os.ReadDir(replay)
				for _, e := range entries {
					b, _ := os.ReadFile(filepath.Join(replay, e.Name()))
					staged = append(staged, e.Name()+"="+string(b))
				}
				return runner.Result{Stdout: []byte(`{"mode":"replayed"}`)}, nil
			})
			h.run.On("docker exec falak-db-"+instID2+" falak-db table-counts --database shop", runner.Result{Stdout: []byte(`{"tables":{"shop.orders":42}}`)})
			res, err := h.db.PITRRestore(context.Background(), p, stream())
			if err != nil {
				t.Fatal(err)
			}
			r := res.(PITRRestoreResult)
			if base != "BASE-BACKUP" || r.TableCounts["shop"]["shop.orders"] != 42 || r.Segments != 2 || r.RecoveredTo != "2026-10-09T12:30:00.5Z" {
				t.Fatalf("base %q result %+v", base, r)
			}
			wantStaged := "[binlog.000004=binlog four binlog.000005=binlog five]"
			if engine == "postgres" {
				wantStaged = "[000000010000000000000004=binlog four 000000010000000000000005=binlog five]"
			}
			if fmt.Sprint(staged) != wantStaged {
				t.Fatalf("staged %v", staged)
			}
			lines := strings.Join(h.run.Lines(), "\n")
			order := []string{"restore physical --in -", "readonly on", "table-counts"}
			if engine == "postgres" {
				order = []string{"restore physical --in -", "recover --wal-dir /var/lib/falak/db/spool/replay --target-time 2026-10-09T12:30:00.5Z", "readonly on"}
			} else {
				order = append([]string{"restore physical --in -", "recover --binlog-dir /var/lib/falak/db/spool/replay --target-time 2026-10-09T12:30:00.5Z"}, order[1:]...)
			}
			last := -1
			for _, o := range order {
				i := strings.Index(lines, o)
				if i < last {
					t.Fatalf("%q out of order in\n%s", o, lines)
				}
				last = i
			}
			if !h.dock.called("create falak-db-"+instID2) || strings.Contains(lines, secret) {
				t.Fatalf("calls %v", h.dock.calls)
			}
			// Restore files are gone; the new instance does not ship (PITR is off until decided).
			if _, err := os.Stat(replay); !errors.Is(err, os.ErrNotExist) {
				t.Fatal("replay directory left behind")
			}
			if cfg := h.db.pitrConfigs()[instID2]; cfg.Enabled {
				t.Fatal("the restored instance ships its spool")
			}
		})
	}
}

func TestPITRRestoreVerifiesEverythingBeforeTheEngineSeesIt(t *testing.T) {
	h := newHarness(t)
	store := newObjectStore(t)
	h.db.d.HTTP = store.srv.Client()
	h.db.d.FreeBytes = func(string) (int64, error) { return 100 << 30, nil }
	ctx := context.Background()

	// A segment whose stored content is not the recorded one.
	p := restorePayload(t, store, "postgres")
	p.Segments[1].SHA256 = strings.Repeat("0", 64)
	if _, err := h.db.PITRRestore(ctx, p, stream()); err == nil || !strings.Contains(err.Error(), "000000010000000000000005") {
		t.Fatalf("err %v", err)
	}
	if h.run.Ran("docker run") {
		t.Fatal("the engine ran with a bad segment")
	}
	// A segment under the wrong key (another segment's file).
	p = restorePayload(t, store, "postgres")
	p.Segments[0].Encryption = p.Segments[1].Encryption
	if _, err := h.db.PITRRestore(ctx, p, stream()); err == nil || h.run.Ran("docker run") {
		t.Fatalf("err %v", err)
	}
	// A base whose content is not the recorded one.
	p = restorePayload(t, store, "postgres")
	p.Base.PlaintextSHA256 = strings.Repeat("0", 64)
	if _, err := h.db.PITRRestore(ctx, p, stream()); err == nil || h.run.Ran("docker run") {
		t.Fatalf("err %v", err)
	}
	// Not enough room on the new volume.
	h.db.d.FreeBytes = func(string) (int64, error) { return 1 << 20, nil }
	p = restorePayload(t, store, "postgres")
	if _, err := h.db.PITRRestore(ctx, p, stream()); err == nil || !strings.Contains(err.Error(), "free") {
		t.Fatalf("err %v", err)
	}
	// Never onto an existing instance.
	h.db.d.FreeBytes = func(string) (int64, error) { return 100 << 30, nil }
	h.dock.containers[Container(instID2)] = runningContainer()
	if _, err := h.db.PITRRestore(ctx, restorePayload(t, store, "postgres"), stream()); err == nil || !strings.Contains(err.Error(), "new instance") {
		t.Fatalf("err %v", err)
	}
}

func TestPITRPromoteMakesTheCopyWritableAndStopsTheReplacedOne(t *testing.T) {
	h := newHarness(t)
	h.dock.containers[Container(instID)] = runningContainer()
	if _, err := h.db.PITRPromote(context.Background(), PITRPromotePayload{Instance: instID2, Engine: "mysql", Stop: instID}, stream()); err != nil {
		t.Fatal(err)
	}
	if !h.run.Ran("docker exec falak-db-"+instID2+" falak-db readonly off") || !h.dock.called("stop falak-db-"+instID) {
		t.Fatalf("lines %v calls %v", h.run.Lines(), h.dock.calls)
	}
	if _, err := h.db.PITRPromote(context.Background(), PITRPromotePayload{Instance: instID, Engine: "mysql", Stop: instID}, stream()); err == nil {
		t.Fatal("an instance replaced itself")
	}
}
