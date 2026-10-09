package db

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/transport"
)

// Point-in-time recovery: shipping the spool (docs/BACKUPS.md, docs/DB_IMAGES.md "The spool").
//
// falak-db only ever adds files to an instance's spool (<volume>/spool/wal for postgres' archive_command,
// <volume>/spool/binlog for `falak-db binlog-rotate`). For every instance with PITR on, the shipper:
//
//  1. MySQL / MariaDB: runs `binlog-rotate` every Rotate (60 s), skipped while the binlog being written did not grow.
//     Exit 4 (a gap in the binlog chain) is reported once as pitr.gap; a `reset` gap stops spooling until the next base
//     backup, after which the rotation runs with `--restart`.
//  2. Lists the completed spool files (names not starting with "."), oldest first, and ships them in batches: the
//     control plane answers pitr.upload_urls with a presigned PUT URL and the encryption of each segment (cp: a fresh
//     data key it keeps wrapped on the segment's row; customer: the age recipient), the agent encrypts (FKB1), uploads
//     and reports them (pitr.shipped). A spool file is deleted only once the control plane acknowledged it.
//
// Nothing is deleted while the control plane is unreachable: the spool grows (it is on the instance's sized volume),
// failures back off with jitter, and the heartbeat reports the spool's size and oldest file.

// Requester is the control plane's request channel (*transport.Client: POST /agent/v1/requests/<type>).
type Requester interface {
	Request(ctx context.Context, typ string, in, out any) error
}

// PITRSpec is the instance spec's `pitr` (db.instance.create / db.instance.update).
type PITRSpec struct {
	Enabled bool `json:"enabled"`
}

// pitrConfig is what the agent keeps of it (/etc/falak/db/<id>/pitr.json): the shipper finds its instances there.
type pitrConfig struct {
	Enabled  bool   `json:"enabled"`
	Engine   string `json:"engine"`
	VolumeID string `json:"volume_id"`
}

// pitrState survives agent restarts (/etc/falak/db/<id>/pitr-state.json).
type pitrState struct {
	// Reported gaps (kind:from:to), each sent to the control plane once.
	Reported []string `json:"reported,omitempty"`
	// RestartAfterBase: a reset gap stopped spooling; the next base backup is followed by `binlog-rotate --restart`.
	RestartAfterBase bool `json:"restart_after_base,omitempty"`
}

// Spool subdirectories and the kinds of segments in them.
const (
	spoolWAL    = "wal"
	spoolBinlog = "binlog"
)

func spoolKind(engine string) string {
	if engine == "postgres" {
		return spoolWAL
	}
	return spoolBinlog
}

// segmentRe accepts the names falak-db spools (WAL segments, .history / .backup files, binlog.000042).
var segmentRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`)

// Shipper ships the spools of the instances with PITR on.
type Shipper struct {
	db *DB
	cp Requester
	// Interval is how often spools are looked at (default 10 s); Rotate how often binlogs are closed (default 60 s).
	Interval, Rotate time.Duration
	// BatchFiles and BatchBytes bound one pitr.upload_urls request (defaults 20 files, 256 MiB).
	BatchFiles int
	BatchBytes int64
	Now        func() time.Time

	mu    sync.Mutex
	state map[string]*shipState
}

// shipState is one instance's shipping state in memory.
type shipState struct {
	lastRotate  time.Time
	rotatedName string
	rotatedSize int64
	retryAt     time.Time
	backoff     transport.Backoff
	lastShipped time.Time
	lastErr     string
	// busy: a base backup runs (no rotation meanwhile: FLUSH BINARY LOGS would wait for its locks).
	busy bool
}

// NewShipper builds the shipper of db's instances; cp answers its requests.
func (db *DB) NewShipper(cp Requester) *Shipper {
	s := &Shipper{db: db, cp: cp, Interval: 10 * time.Second, Rotate: 60 * time.Second, BatchFiles: 20, BatchBytes: 256 << 20, Now: time.Now,
		state: map[string]*shipState{}}
	db.shipper = s
	return s
}

// Run ships until ctx ends.
func (s *Shipper) Run(ctx context.Context) {
	for {
		s.RunOnce(ctx)
		if err := sleep(ctx, s.Interval); err != nil {
			return
		}
	}
}

// RunOnce looks at every instance once.
func (s *Shipper) RunOnce(ctx context.Context) {
	for id, cfg := range s.db.pitrConfigs() {
		if ctx.Err() != nil {
			return
		}
		if !cfg.Enabled {
			s.db.clearSpool(cfg)
			continue
		}
		s.instance(ctx, id, cfg)
	}
}

func (s *Shipper) st(id string) *shipState {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.state[id]
	if st == nil {
		st = &shipState{backoff: transport.Backoff{Min: 5 * time.Second, Max: 5 * time.Minute}}
		s.state[id] = st
	}
	return st
}

// setBusy marks a base backup of the instance running; it returns the release.
func (s *Shipper) setBusy(id string) func() {
	st := s.st(id)
	s.mu.Lock()
	st.busy = true
	s.mu.Unlock()
	return func() {
		s.mu.Lock()
		st.busy = false
		s.mu.Unlock()
	}
}

func (s *Shipper) instance(ctx context.Context, id string, cfg pitrConfig) {
	st := s.st(id)
	now := s.Now()
	s.mu.Lock()
	busy, rotateDue, retryAt := st.busy, now.Sub(st.lastRotate) >= s.Rotate, st.retryAt
	s.mu.Unlock()
	if cfg.Engine != "postgres" && rotateDue && !busy {
		s.mu.Lock()
		st.lastRotate = now
		s.mu.Unlock()
		if err := s.rotate(ctx, id, cfg, false); err != nil {
			s.fail(st, fmt.Errorf("binlog-rotate: %w", err))
		}
	}
	if now.Before(retryAt) {
		return
	}
	// A few batches per pass: a backlog drains without starving the other instances.
	for range 10 {
		n, err := s.shipBatch(ctx, id, cfg)
		if err != nil {
			s.fail(st, err)
			return
		}
		s.mu.Lock()
		st.backoff.Reset()
		st.lastErr = ""
		s.mu.Unlock()
		if n == 0 {
			return
		}
	}
}

// fail backs the instance's shipping off (exponential, with jitter) and keeps the error for the heartbeat.
func (s *Shipper) fail(st *shipState, err error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st.lastErr = trimErr(err.Error())
	st.retryAt = s.Now().Add(st.backoff.Next())
	s.db.d.Logger.Warn("pitr shipping", "err", err)
}

func trimErr(s string) string {
	if len(s) > 500 {
		return s[:500]
	}
	return s
}

// rotate runs `falak-db binlog-rotate` unless the binlog being written did not grow since the last one (an idle
// server would get a new file every minute). force skips that check (before a base backup).
func (s *Shipper) rotate(ctx context.Context, id string, cfg pitrConfig, force bool) error {
	if c, ok, err := s.db.d.Docker.ContainerInspect(ctx, Container(id)); err != nil || !ok || !c.State.Running {
		return err
	}
	st := s.st(id)
	state := s.db.loadPITRState(id)
	if !force && !state.RestartAfterBase {
		s.mu.Lock()
		name, size := st.rotatedName, st.rotatedSize
		s.mu.Unlock()
		if name != "" {
			if fi, err := os.Stat(filepath.Join(s.db.d.FS.P(s.db.volumeDir(cfg.VolumeID)), "data", name)); err == nil && fi.Size() == size {
				return nil
			}
		}
	}
	res, err := s.db.binlogRotate(ctx, id, false)
	if res == nil {
		return err
	}
	s.mu.Lock()
	st.rotatedName = res.Current
	st.rotatedSize = -1
	if fi, serr := os.Stat(filepath.Join(s.db.d.FS.P(s.db.volumeDir(cfg.VolumeID)), "data", res.Current)); serr == nil && res.Current != "" {
		st.rotatedSize = fi.Size()
	}
	s.mu.Unlock()
	if len(res.Gaps) > 0 {
		return s.reportGaps(ctx, id, cfg, res.Gaps)
	}
	return err
}

// binlogRotateResult is `falak-db binlog-rotate`'s result (printed with exit 4 too).
type binlogRotateResult struct {
	Current string      `json:"current"`
	Spooled []any       `json:"spooled"`
	Gaps    []binlogGap `json:"gaps"`
}

type binlogGap struct {
	Kind   string `json:"kind"`
	From   string `json:"from,omitempty"`
	To     string `json:"to,omitempty"`
	Detail string `json:"detail"`
}

func (g binlogGap) key() string { return g.Kind + ":" + g.From + ":" + g.To }

// binlogRotate runs `falak-db binlog-rotate [--restart]` in the instance's container. The result comes back with a
// gap (exit 4) too; nil when falak-db printed none.
func (db *DB) binlogRotate(ctx context.Context, id string, restart bool) (*binlogRotateResult, error) {
	args := []string{"binlog-rotate"}
	if restart {
		args = append(args, "--restart")
	}
	out, _, err := db.exec(ctx, id, nil, nil, nil, args...)
	var res binlogRotateResult
	if jerr := json.Unmarshal([]byte(strings.TrimSpace(out)), &res); jerr != nil {
		if err == nil {
			err = fmt.Errorf("binlog-rotate: %w", jerr)
		}
		return nil, err
	}
	return &res, err
}

// reportGaps sends the gaps not reported yet (pitr.gap); a reset gap waits for the next base backup.
func (s *Shipper) reportGaps(ctx context.Context, id string, cfg pitrConfig, gaps []binlogGap) error {
	state := s.db.loadPITRState(id)
	var fresh []binlogGap
	for _, g := range gaps {
		if g.Kind == "reset" {
			state.RestartAfterBase = true
		}
		if !contains(state.Reported, g.key()) {
			fresh = append(fresh, g)
		}
	}
	if len(fresh) > 0 {
		if err := s.cp.Request(ctx, "pitr.gap", map[string]any{"instance": id, "kind": spoolKind(cfg.Engine), "gaps": fresh}, nil); err != nil {
			_ = s.db.savePITRState(id, state)
			return fmt.Errorf("reporting a gap in the binlog chain: %w", err)
		}
		for _, g := range fresh {
			state.Reported = append(state.Reported, g.key())
		}
		if len(state.Reported) > 50 {
			state.Reported = state.Reported[len(state.Reported)-50:]
		}
	}
	return s.db.savePITRState(id, state)
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// spoolFile is a completed file in an instance's spool.
type spoolFile struct {
	Name     string
	Path     string
	Bytes    int64
	Modified time.Time
}

// pending lists the completed spool files of a kind, oldest first (WAL and binlog names sort in their order).
func (db *DB) pending(cfg pitrConfig) ([]spoolFile, error) {
	dir := filepath.Join(db.d.FS.P(db.volumeDir(cfg.VolumeID)), "spool", spoolKind(cfg.Engine))
	entries, err := os.ReadDir(dir)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []spoolFile
	for _, e := range entries {
		// Dot-files are falak-db's own (temporary files, .binlog-last): never shipped, never deleted.
		if strings.HasPrefix(e.Name(), ".") || !e.Type().IsRegular() || !segmentRe.MatchString(e.Name()) {
			continue
		}
		fi, err := e.Info()
		if err != nil {
			continue
		}
		modified := fi.ModTime()
		// A binlog is spooled at the next rotation, maybe with the one after it (xtrabackup closes binlogs too): its
		// end is when the server last wrote it, which its original in the data directory still tells.
		if cfg.Engine != "postgres" {
			if orig, err := os.Stat(filepath.Join(db.d.FS.P(db.volumeDir(cfg.VolumeID)), "data", e.Name())); err == nil && orig.ModTime().Before(modified) {
				modified = orig.ModTime()
			}
		}
		out = append(out, spoolFile{Name: e.Name(), Path: filepath.Join(dir, e.Name()), Bytes: fi.Size(), Modified: modified.UTC()})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}

// uploadSlot is one segment of the pitr.upload_urls reply.
type uploadSlot struct {
	Name string `json:"name"`
	// ID names the segment (the FKB1 key id); Shipped: the control plane has this very file already (an earlier pass
	// uploaded it but its acknowledgment never reached the agent), so it is only deleted.
	ID         string                 `json:"id"`
	Shipped    bool                   `json:"shipped,omitempty"`
	URL        string                 `json:"url,omitempty"`
	Headers    map[string]string      `json:"headers,omitempty"`
	Encryption backupcrypt.Encryption `json:"encryption"`
}

// shippedSegment is one segment of pitr.shipped.
type shippedSegment struct {
	ID              string    `json:"id"`
	Name            string    `json:"name"`
	SizeBytes       int64     `json:"size_bytes"`
	SHA256          string    `json:"sha256"`
	PlaintextBytes  int64     `json:"plaintext_bytes"`
	PlaintextSHA256 string    `json:"plaintext_sha256"`
	EndTime         time.Time `json:"end_time"`
}

// shipBatch ships up to one batch of the instance's spool and returns how many files left it.
func (s *Shipper) shipBatch(ctx context.Context, id string, cfg pitrConfig) (int, error) {
	files, err := s.db.pending(cfg)
	if err != nil || len(files) == 0 {
		return 0, err
	}
	var batch []spoolFile
	var size int64
	for _, f := range files {
		if len(batch) > 0 && (len(batch) >= s.BatchFiles || size+f.Bytes > s.BatchBytes) {
			break
		}
		batch = append(batch, f)
		size += f.Bytes
	}
	type ask struct {
		Name   string `json:"name"`
		Bytes  int64  `json:"bytes"`
		SHA256 string `json:"sha256"`
	}
	asks := make([]ask, 0, len(batch))
	sums := map[string]string{}
	for _, f := range batch {
		sum, err := fileSHA256(f.Path)
		if err != nil {
			return 0, err
		}
		sums[f.Name] = sum
		asks = append(asks, ask{Name: f.Name, Bytes: f.Bytes, SHA256: sum})
	}
	kind := spoolKind(cfg.Engine)
	var reply struct {
		Segments []uploadSlot `json:"segments"`
	}
	if err := s.cp.Request(ctx, "pitr.upload_urls", map[string]any{"instance": id, "kind": kind, "segments": asks}, &reply); err != nil {
		return 0, fmt.Errorf("pitr.upload_urls: %w", err)
	}
	byName := map[string]spoolFile{}
	for _, f := range batch {
		byName[f.Name] = f
	}
	var shipped []shippedSegment
	var done []spoolFile // acknowledged: deleted below
	ids := map[string]spoolFile{}
	for _, slot := range reply.Segments {
		f, ok := byName[slot.Name]
		if !ok || slot.ID == "" {
			continue // never act on a name this batch did not ask for
		}
		delete(byName, slot.Name)
		if slot.Shipped {
			done = append(done, f)
			continue
		}
		seg, err := s.db.uploadSegment(ctx, f, slot, sums[f.Name])
		if err != nil {
			// What uploaded so far is still reported: a later pass retries the rest.
			_, _ = s.ack(ctx, id, kind, shipped, ids, done)
			return 0, fmt.Errorf("%s: %w", f.Name, err)
		}
		shipped = append(shipped, seg)
		ids[seg.ID] = f
	}
	removed, err := s.ack(ctx, id, kind, shipped, ids, done)
	if err != nil {
		return 0, err
	}
	switch {
	case len(shipped) == 0 && len(done) == 0:
		return 0, errors.New("pitr.upload_urls answered no segment of the batch")
	case removed < len(shipped)+len(done):
		// Kept for a later pass (backing off): uploading them again right away would not change the answer.
		return 0, fmt.Errorf("the control plane acknowledged %d of %d uploaded segments", removed-len(done), len(shipped))
	}
	return removed, nil
}

// ack reports the uploaded segments (pitr.shipped) and deletes the spool files the control plane acknowledged, plus
// those it already had, and returns how many went. A file is never deleted on any other grounds.
func (s *Shipper) ack(ctx context.Context, id, kind string, shipped []shippedSegment, ids map[string]spoolFile, done []spoolFile) (int, error) {
	if len(shipped) > 0 {
		var reply struct {
			Acknowledged []string `json:"acknowledged"`
		}
		if err := s.cp.Request(ctx, "pitr.shipped", map[string]any{"instance": id, "kind": kind, "segments": shipped}, &reply); err != nil {
			s.removeAcked(done)
			return len(done), fmt.Errorf("pitr.shipped: %w", err)
		}
		for _, segID := range reply.Acknowledged {
			if f, ok := ids[segID]; ok {
				done = append(done, f)
			}
		}
		st := s.st(id)
		s.mu.Lock()
		st.lastShipped = s.Now()
		s.mu.Unlock()
	}
	s.removeAcked(done)
	return len(done), nil
}

func (s *Shipper) removeAcked(files []spoolFile) {
	for _, f := range files {
		if err := os.Remove(f.Path); err != nil && !errors.Is(err, fs.ErrNotExist) {
			s.db.d.Logger.Warn("removing a shipped spool file", "file", f.Path, "err", err)
		}
	}
}

// uploadSegment encrypts one spool file (FKB1, with the segment's own key or the customer's recipient) into the
// staging directory and PUTs it to its presigned URL.
func (db *DB) uploadSegment(ctx context.Context, f spoolFile, slot uploadSlot, plainSum string) (shippedSegment, error) {
	if err := slot.Encryption.Check(true); err != nil {
		return shippedSegment{}, fmt.Errorf("encryption: %w", err)
	}
	if slot.Encryption.KeyID != slot.ID {
		return shippedSegment{}, fmt.Errorf("the segment's key id is %q, not its id %q", slot.Encryption.KeyID, slot.ID)
	}
	if err := checkDestination(Location{Kind: "presigned_url", URL: slot.URL}); err != nil {
		return shippedSegment{}, err
	}
	if err := os.MkdirAll(db.d.TempDir, 0o700); err != nil {
		return shippedSegment{}, err
	}
	tmp, err := os.CreateTemp(db.d.TempDir, "falak-pitr-*")
	if err != nil {
		return shippedSegment{}, err
	}
	defer os.Remove(tmp.Name())
	in, err := os.Open(f.Path)
	if err != nil {
		tmp.Close()
		return shippedSegment{}, err
	}
	defer in.Close()
	h := sha256.New()
	sealed, err := slot.Encryption.Seal(io.MultiWriter(tmp, h))
	if err != nil {
		tmp.Close()
		return shippedSegment{}, err
	}
	_, err = io.Copy(sealed, in)
	if cerr := sealed.Close(); err == nil {
		err = cerr
	}
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return shippedSegment{}, err
	}
	sum := sealed.Summary()
	if got := hex.EncodeToString(sum.SHA256[:]); got != plainSum {
		return shippedSegment{}, fmt.Errorf("the spool file changed while it was shipped (sha256 %s, was %s)", got, plainSum)
	}
	fi, err := os.Stat(tmp.Name())
	if err != nil {
		return shippedSegment{}, err
	}
	if err := db.put(ctx, tmp.Name(), fi.Size(), Location{Kind: "presigned_url", URL: slot.URL, Headers: slot.Headers}); err != nil {
		return shippedSegment{}, err
	}
	return shippedSegment{ID: slot.ID, Name: f.Name, SizeBytes: fi.Size(), SHA256: hex.EncodeToString(h.Sum(nil)), PlaintextBytes: sum.Bytes,
		PlaintextSHA256: plainSum, EndTime: f.Modified}, nil
}

func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

// ---- per-instance files ----

func (db *DB) pitrFile(id string) string { return filepath.Join(db.d.EtcDir, "db", id, "pitr.json") }
func (db *DB) pitrStateFile(id string) string {
	return filepath.Join(db.d.EtcDir, "db", id, "pitr-state.json")
}

// writePITR records whether the instance's spool is shipped (db.instance.create / db.instance.update).
func (db *DB) writePITR(s InstanceSpec) error {
	cfg := pitrConfig{Enabled: s.PITR != nil && s.PITR.Enabled, Engine: s.Engine, VolumeID: s.VolumeID}
	if isKeyValue(s.Engine) {
		cfg.Enabled = false
	}
	b, _ := json.Marshal(cfg)
	p := db.d.FS.P(db.pitrFile(s.ID))
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	if err := os.WriteFile(p+".tmp", b, 0o600); err != nil {
		return err
	}
	return os.Rename(p+".tmp", p)
}

// pitrConfigs reads every instance's pitr.json (SQL engines only).
func (db *DB) pitrConfigs() map[string]pitrConfig {
	out := map[string]pitrConfig{}
	dirs, _ := filepath.Glob(filepath.Join(db.d.FS.P(filepath.Join(db.d.EtcDir, "db")), "*", "pitr.json"))
	for _, p := range dirs {
		id := filepath.Base(filepath.Dir(p))
		if !idRe.MatchString(id) {
			continue
		}
		b, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		var cfg pitrConfig
		if json.Unmarshal(b, &cfg) != nil || !idRe.MatchString(cfg.VolumeID) || isKeyValue(cfg.Engine) || checkEngine(cfg.Engine) != nil {
			continue
		}
		out[id] = cfg
	}
	return out
}

func (db *DB) loadPITRState(id string) pitrState {
	var st pitrState
	if b, err := os.ReadFile(db.d.FS.P(db.pitrStateFile(id))); err == nil {
		_ = json.Unmarshal(b, &st)
	}
	return st
}

func (db *DB) savePITRState(id string, st pitrState) error {
	p := db.d.FS.P(db.pitrStateFile(id))
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	b, _ := json.Marshal(st)
	if err := os.WriteFile(p+".tmp", b, 0o600); err != nil {
		return err
	}
	return os.Rename(p+".tmp", p)
}

// clearSpool empties the spool of an instance without PITR: postgres archives every segment into it whatever happens,
// and nobody wants them. falak-db's dot-files stay.
func (db *DB) clearSpool(cfg pitrConfig) {
	for _, kind := range []string{spoolWAL, spoolBinlog} {
		files, _ := db.pending(pitrConfig{Engine: map[string]string{spoolWAL: "postgres", spoolBinlog: "mysql"}[kind], VolumeID: cfg.VolumeID})
		for _, f := range files {
			_ = os.Remove(f.Path)
		}
	}
}

// ---- heartbeat ----

// PITRReport is an instance's shipping state in the heartbeat (`databases[].pitr`).
type PITRReport struct {
	SpoolBytes  int64 `json:"spool_bytes"`
	VolumeBytes int64 `json:"volume_bytes,omitempty"`
	Pending     int   `json:"pending"`
	// OldestPendingAt is when the oldest unshipped segment was spooled: its age is the shipping lag.
	OldestPendingAt *time.Time `json:"oldest_pending_at,omitempty"`
	LastShippedAt   *time.Time `json:"last_shipped_at,omitempty"`
	Error           string     `json:"error,omitempty"`
}

// pitrReport is the heartbeat entry of an instance with PITR on (nil otherwise).
func (db *DB) pitrReport(id string, cfgs map[string]pitrConfig) *PITRReport {
	cfg, ok := cfgs[id]
	if !ok || !cfg.Enabled {
		return nil
	}
	r := &PITRReport{}
	vol := db.d.FS.P(db.volumeDir(cfg.VolumeID))
	_ = filepath.WalkDir(filepath.Join(vol, "spool"), func(p string, d fs.DirEntry, err error) error {
		if err == nil && d.Type().IsRegular() {
			if fi, err := d.Info(); err == nil {
				r.SpoolBytes += fi.Size()
			}
		}
		return nil
	})
	if total, err := db.d.TotalBytes(vol); err == nil {
		r.VolumeBytes = total
	}
	if files, err := db.pending(cfg); err == nil && len(files) > 0 {
		r.Pending = len(files)
		oldest := files[0].Modified
		for _, f := range files {
			if f.Modified.Before(oldest) {
				oldest = f.Modified
			}
		}
		r.OldestPendingAt = &oldest
	}
	if s := db.shipper; s != nil {
		st := s.st(id)
		s.mu.Lock()
		if !st.lastShipped.IsZero() {
			t := st.lastShipped.UTC()
			r.LastShippedAt = &t
		}
		r.Error = st.lastErr
		s.mu.Unlock()
	}
	return r
}

// totalBytes is the size of the filesystem holding path.
func totalBytes(path string) (int64, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, err
	}
	return int64(st.Blocks) * int64(st.Bsize), nil
}
