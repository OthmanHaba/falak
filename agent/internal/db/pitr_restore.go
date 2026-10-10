package db

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/backupcrypt"
	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/docker"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// ---- db.pitr.base ----

// PITRBasePayload is db.pitr.base: a physical base backup of the instance (`falak-db backup physical`), encrypted
// (FKB1) and PUT to the presigned URL like db.backup.
type PITRBasePayload struct {
	Instance string `json:"instance"`
	Engine   string `json:"engine"`
	// VolumeID is the instance's volume: the base turns shipping on itself (pitr.json), so the WAL it needs is never
	// emptied from the spool by an update that has not arrived yet.
	VolumeID    string                 `json:"volume_id"`
	Encryption  backupcrypt.Encryption `json:"encryption"`
	Destination Location               `json:"destination"`
}

// Secrets are the payload's secret values (masked in output).
func (p PITRBasePayload) Secrets() []string { return p.Encryption.Secrets() }

// PITRBaseResult is its result: the stored file, and where in the log the base starts.
type PITRBaseResult struct {
	BackupResult
	// StartedAt and FinishedAt are falak-db's (the server's clock, like the spooled segments' times).
	StartedAt  string `json:"started_at"`
	FinishedAt string `json:"finished_at"`
	// Postgres: the first and last WAL segment the base needs (they are in the spool when the backup returns).
	StartWAL string `json:"start_wal,omitempty"`
	StopWAL  string `json:"stop_wal,omitempty"`
	// MySQL / MariaDB: the binlog being written when the base started (closed just before it, so every binlog from it
	// on reaches the spool).
	StartBinlog string `json:"start_binlog,omitempty"`
}

// PITRBase takes a base backup. MySQL / MariaDB close the binlog being written first, and a binlog chain that was
// reset (a gap of kind reset) is spooled again from here on (`binlog-rotate --restart`).
func (db *DB) PITRBase(ctx context.Context, p PITRBasePayload, st commands.Stream) (any, error) {
	start := time.Now()
	if err := checkID("instance", p.Instance); err != nil {
		return nil, err
	}
	if err := checkEngine(p.Engine); err != nil {
		return nil, err
	}
	if isKeyValue(p.Engine) {
		return nil, payloadErr("point-in-time recovery is for postgres, mysql and mariadb")
	}
	if err := checkDestination(p.Destination); err != nil {
		return nil, err
	}
	if err := p.Encryption.Check(true); err != nil {
		return nil, &commands.PayloadError{Err: err}
	}
	if err := checkID("volume_id", p.VolumeID); err != nil {
		return nil, err
	}
	// Under the instance's lock, which the shipper's emptying of a PITR-less spool takes too.
	defer db.lock(p.Instance)()
	if err := db.writePITRConfig(p.Instance, pitrConfig{Enabled: true, Engine: p.Engine, VolumeID: p.VolumeID}); err != nil {
		return nil, fmt.Errorf("pitr: %w", err)
	}
	if db.shipper != nil {
		defer db.shipper.setBusy(p.Instance)()
	}
	var startBinlog string
	if p.Engine != "postgres" {
		rot, err := db.binlogRotate(ctx, p.Instance, false)
		if rot == nil || rot.Current == "" {
			return nil, fmt.Errorf("closing the binlog before the base backup: %w", err)
		}
		startBinlog = rot.Current
	}
	var phys struct {
		StartedAt  string `json:"started_at"`
		FinishedAt string `json:"finished_at"`
		StartWAL   string `json:"start_wal"`
		StopWAL    string `json:"stop_wal"`
	}
	res, err := db.ship(ctx, BackupPayload{Instance: p.Instance, Engine: p.Engine, Database: "base", Encryption: p.Encryption, Destination: p.Destination}, start, st,
		func(out io.Writer) error {
			_, stderr, err := db.exec(ctx, p.Instance, nil, out, nil, "backup", "physical", "--out", "-")
			if err != nil {
				return fmt.Errorf("base backup: %w", err)
			}
			return streamResult(stderr, &phys)
		})
	if err != nil {
		return nil, err
	}
	if p.Engine == "postgres" && phys.StartWAL == "" {
		return nil, errors.New("base backup: falak-db reported no start_wal")
	}
	state := db.loadPITRState(p.Instance)
	if p.Engine != "postgres" && state.RestartAfterBase {
		// The base covers the reset: spooling starts again from the binlogs on the server now.
		if _, err := db.binlogRotate(ctx, p.Instance, true); err != nil {
			return nil, fmt.Errorf("binlog-rotate --restart after the base backup: %w", err)
		}
		fmt.Fprintf(st.Stdout(), "binlog spooling restarted after the reset\n")
	}
	state.RestartAfterBase, state.Reported = false, nil
	if err := db.savePITRState(p.Instance, state); err != nil {
		return nil, err
	}
	return PITRBaseResult{BackupResult: res, StartedAt: phys.StartedAt, FinishedAt: phys.FinishedAt, StartWAL: phys.StartWAL, StopWAL: phys.StopWAL,
		StartBinlog: startBinlog}, nil
}

// ---- db.pitr.restore ----

// PITRObject is a stored FKB1 file to fetch: the base or a segment.
type PITRObject struct {
	URL     string            `json:"url"`
	Headers map[string]string `json:"headers,omitempty"`
	// SHA256 is the stored file's (checked after the download); PlaintextSHA256 the content's (authenticated in the
	// file's trailer).
	SHA256          string                 `json:"sha256"`
	PlaintextSHA256 string                 `json:"plaintext_sha256"`
	SizeBytes       int64                  `json:"size_bytes"`
	PlaintextBytes  int64                  `json:"plaintext_bytes,omitempty"`
	Encryption      backupcrypt.Encryption `json:"encryption"`
}

// PITRSegment is a WAL segment or binlog to replay.
type PITRSegment struct {
	Name string `json:"name"`
	PITRObject
}

// PITRRestorePayload is db.pitr.restore: a NEW instance (its own volume, never the running one) made from the newest
// base before target_time and the log segments after it, replayed to target_time and left read-only for inspection.
type PITRRestorePayload struct {
	Restore  string       `json:"restore"`
	Instance InstanceSpec `json:"instance"`
	// Password is the source's superuser / root password: the restored data keeps the source's accounts.
	Password string `json:"password"`
	// Identity opens customer-held files (their encryption names only mode age and the key id).
	Identity string        `json:"identity,omitempty"`
	Base     PITRObject    `json:"base"`
	Segments []PITRSegment `json:"segments"`
	// TargetTime is where to stop; empty: the latest point (everything given is replayed).
	TargetTime string `json:"target_time,omitempty"`
	// Databases are counted (rows per table) once the copy runs.
	Databases []string `json:"databases,omitempty"`
	// Inspection is the copy's read-only account (SELECT on Databases): what people look at it with.
	Inspection UserSpec `json:"inspection"`
}

// Secrets are the payload's secret values (masked in output).
func (p PITRRestorePayload) Secrets() []string {
	out := []string{p.Password, p.Identity, p.Base.Encryption.Key, p.Inspection.Password}
	if p.Instance.TLS != nil {
		out = append(out, p.Instance.TLS.PrivateKey)
	}
	for _, s := range p.Segments {
		out = append(out, s.Encryption.Key)
	}
	return out
}

// PITRRestoreResult is its result.
type PITRRestoreResult struct {
	ContainerID string `json:"container_id"`
	ImageDigest string `json:"image_digest,omitempty"`
	Health      string `json:"health"`
	RecoveredTo string `json:"recovered_to"`
	// RecoveredToEnd: replayed to the end of the log given (the latest point, or postgres found no commit after the
	// target in it: everything there happened before the target).
	RecoveredToEnd bool `json:"recovered_to_end,omitempty"`
	Segments       int  `json:"segments"`
	// DownloadedBytes are the stored files fetched (base and segments).
	DownloadedBytes int64 `json:"downloaded_bytes"`
	// TableCounts are the rows of every table of each database, as restored.
	TableCounts map[string]map[string]int64 `json:"table_counts"`
	Warnings    []string                    `json:"warnings,omitempty"`
	DurationMS  int64                       `json:"duration_ms"`
}

// The restore's working directories on the new volume: the downloads (encrypted, removed as they are used) and the
// replayed segments, under the spool (`falak-db recover` only reads /replay or the spool).
const (
	restoreStagingDir = "restore-staging"
	replayDir         = "replay"
)

// withIdentity gives a customer-held object the restore's identity.
func withIdentity(e backupcrypt.Encryption, identity string) backupcrypt.Encryption {
	if e.Mode == "age" && e.Identity == "" {
		e.Identity = identity
	}
	return e
}

func (p *PITRRestorePayload) validate() (time.Time, error) {
	if err := checkID("restore", p.Restore); err != nil {
		return time.Time{}, err
	}
	if err := p.Instance.validate(); err != nil {
		return time.Time{}, err
	}
	if isKeyValue(p.Instance.Engine) {
		return time.Time{}, payloadErr("point-in-time recovery is for postgres, mysql and mariadb")
	}
	if p.Instance.PITR != nil && p.Instance.PITR.Enabled {
		return time.Time{}, payloadErr("a restored instance starts without PITR")
	}
	if p.Password == "" {
		return time.Time{}, payloadErr("password is required")
	}
	var target time.Time
	if p.TargetTime != "" {
		t, err := time.Parse(time.RFC3339Nano, p.TargetTime)
		if err != nil {
			return time.Time{}, payloadErr("target_time must be RFC 3339")
		}
		target = t
	}
	if err := checkIdent("inspection.username", p.Inspection.Username); err != nil {
		return time.Time{}, err
	}
	if p.Inspection.Password == "" {
		return time.Time{}, payloadErr("inspection.password is required")
	}
	for _, d := range p.Databases {
		if err := checkIdent("database", d); err != nil {
			return time.Time{}, err
		}
	}
	objects := []*PITRObject{&p.Base}
	seen := map[string]bool{}
	for i := range p.Segments {
		s := &p.Segments[i]
		if !segmentRe.MatchString(s.Name) || seen[s.Name] {
			return time.Time{}, payloadErr("invalid or repeated segment name %q", s.Name)
		}
		seen[s.Name] = true
		objects = append(objects, &s.PITRObject)
	}
	for _, o := range objects {
		if !strings.HasPrefix(o.URL, "https://") {
			return time.Time{}, payloadErr("object urls must be https")
		}
		if !sha256Re.MatchString(o.SHA256) || !sha256Re.MatchString(o.PlaintextSHA256) {
			return time.Time{}, payloadErr("every object needs its sha256 and plaintext_sha256")
		}
		if o.SizeBytes < 0 || o.PlaintextBytes < 0 {
			return time.Time{}, payloadErr("sizes must be positive")
		}
		o.Encryption = withIdentity(o.Encryption, p.Identity)
		if err := o.Encryption.Check(false); err != nil {
			return time.Time{}, &commands.PayloadError{Err: err}
		}
	}
	return target.UTC(), nil
}

// PITRRestore makes the new instance: the base is downloaded onto its volume, checked (stored SHA-256) and fully
// authenticated before `falak-db restore physical` sees a byte; every segment is downloaded, checked and decrypted
// into the replay directory, appearing under its name only once its trailer and SHA-256 verified. Then:
//
//   - postgres: `falak-db recover --wal-dir … --target-time T` (a one-off container), and the instance starts: it
//     replays to T and opens on a new timeline;
//   - mysql / mariadb: the instance starts on the prepared base, and `falak-db recover --binlog-dir … --target-time
//     T` replays the binlogs into it.
//
// It ends read-only (`falak-db readonly on`), and the rows of every table are counted for the decision.
func (db *DB) PITRRestore(ctx context.Context, p PITRRestorePayload, st commands.Stream) (any, error) {
	start := time.Now()
	target, err := p.validate()
	if err != nil {
		return nil, err
	}
	s := p.Instance
	name := Container(s.ID)
	if _, exists, err := db.d.Docker.ContainerInspect(ctx, name); err != nil || exists {
		if err == nil {
			err = fmt.Errorf("%s exists already: a restore always makes a new instance", name)
		}
		return nil, err
	}
	if err := db.awaitVolume(ctx, s.VolumeID, st); err != nil {
		return nil, err
	}
	vol := db.d.FS.P(db.volumeDir(s.VolumeID))
	data := filepath.Join(vol, "data")
	if entries, err := os.ReadDir(data); err == nil && len(entries) > 0 {
		return nil, fmt.Errorf("the new volume %s is not empty", s.VolumeID)
	}
	staging, replay := filepath.Join(vol, restoreStagingDir), filepath.Join(vol, "spool", replayDir)
	if err := ensureRootSpool(filepath.Join(vol, "spool")); err != nil {
		return nil, err
	}
	for _, d := range []string{data, staging, replay} {
		if err := os.MkdirAll(d, 0o700); err != nil {
			return nil, err
		}
	}
	defer os.RemoveAll(staging)
	defer os.RemoveAll(replay)
	if err := db.restoreRoom(vol, p); err != nil {
		return nil, err
	}
	if _, err := db.ensureImage(ctx, s, nil, st); err != nil {
		return nil, err
	}
	res := PITRRestoreResult{TableCounts: map[string]map[string]int64{}, Segments: len(p.Segments)}

	// 1. The segments, decrypted into the replay directory: a bad one fails the restore before anything ran.
	for _, seg := range p.Segments {
		n, err := db.stageSegment(ctx, seg, staging, replay)
		if err != nil {
			return nil, fmt.Errorf("segment %s: %w", seg.Name, err)
		}
		res.DownloadedBytes += n
	}
	fmt.Fprintf(st.Stdout(), "%d segments verified and staged\n", len(p.Segments))
	// 2. The base, into the empty data directory (a one-off container: falak-db refuses a running server).
	n, err := db.restoreBase(ctx, s, p.Base, staging, st)
	if err != nil {
		return nil, err
	}
	res.DownloadedBytes += n
	inContainer := spoolTarget + "/" + replayDir
	latest := target.IsZero()
	when := target.Format(time.RFC3339Nano)
	recoverArgs := func(dirFlag string, toTarget bool) []string {
		args := []string{"recover", dirFlag, inContainer}
		if toTarget {
			args = append(args, "--target-time", when)
		}
		return args
	}
	// 3. Replay to T (or to the end of the log: the latest point).
	first := s
	if s.Engine != "postgres" {
		// MySQL / MariaDB replay into the running server: it starts writable (no events), read-only comes after.
		first.Settings = settingsWithout(s.Settings, "read_only")
	} else if err := db.oneOff(ctx, s, nil, st.Stdout(), true, recoverArgs("--wal-dir", !latest)...); err != nil {
		return nil, err
	}
	applied, err := db.apply(ctx, InstancePayload{Instance: first, Password: p.Password}, st)
	if err != nil && s.Engine == "postgres" && !latest && strings.Contains(err.Error(), "recovery ended before configured recovery target was reached") {
		// No commit after T in the shipped WAL: everything it holds happened before T, so its end is T's state.
		fmt.Fprintf(st.Stdout(), "no transaction after %s in the shipped WAL: recovering to its end\n", when)
		if rerr := db.removeContainer(ctx, Container(s.ID)); rerr != nil {
			return nil, rerr
		}
		if err := db.oneOff(ctx, s, nil, st.Stdout(), true, recoverArgs("--wal-dir", false)...); err != nil {
			return nil, err
		}
		res.RecoveredToEnd = true
		applied, err = db.apply(ctx, InstancePayload{Instance: first, Password: p.Password}, st)
	}
	if err != nil {
		return nil, err
	}
	ir := applied.(InstanceResult)
	res.ContainerID, res.ImageDigest, res.Health = ir.ContainerID, ir.ImageDigest, ir.Health
	if s.Engine != "postgres" {
		if _, _, err := db.exec(ctx, s.ID, nil, nil, nil, recoverArgs("--binlog-dir", !latest)...); err != nil {
			return nil, fmt.Errorf("replaying the binlogs: %w", err)
		}
	}
	// 4. Read-only. postgres: once recovery promoted (falak-db's sessions ignore the read-only config), every database
	// too; mysql / mariadb: the config below (a restart), after the inspection account is made.
	if s.Engine == "postgres" {
		if err := db.readOnlyWhenReady(ctx, s.ID); err != nil {
			return nil, err
		}
	}
	// The read-only account people inspect the copy with.
	inspection := p.Inspection
	inspection.State, inspection.Host = "present", "%"
	if s.Engine == "postgres" {
		inspection.Host = ""
	}
	inspection.Grants = nil
	for _, d := range p.Databases {
		inspection.Grants = append(inspection.Grants, Grant{Database: d, Privileges: []string{"SELECT"}})
	}
	if _, err := db.userApply(ctx, s.ID, inspection); err != nil {
		return nil, fmt.Errorf("the inspection account: %w", err)
	}
	if s.Engine != "postgres" {
		// Now read-only in the config as well (it survives a restart): the container restarts with it.
		applied, err := db.apply(ctx, InstancePayload{Instance: s, Password: p.Password}, st)
		if err != nil {
			return nil, err
		}
		res.Health = applied.(InstanceResult).Health
		if err := db.readOnlyWhenReady(ctx, s.ID); err != nil {
			return nil, err
		}
	}
	if latest || res.RecoveredToEnd {
		res.RecoveredToEnd = true
		fmt.Fprintf(st.Stdout(), "%s recovered to the end of the shipped log, read-only\n", name)
	} else {
		res.RecoveredTo = when
		fmt.Fprintf(st.Stdout(), "%s recovered to %s, read-only\n", name, when)
	}
	for _, d := range p.Databases {
		counts, err := db.tableCounts(ctx, s.ID, d)
		if err != nil {
			res.Warnings = append(res.Warnings, fmt.Sprintf("row counts of %s: %v", d, err))
			continue
		}
		res.TableCounts[d] = counts
	}
	res.DurationMS = time.Since(start).Milliseconds()
	return res, nil
}

// restoreRoom fails when the new volume can't hold the base (stored and unpacked) and the segments (decrypted, plus
// the largest stored one while it is decrypted).
func (db *DB) restoreRoom(vol string, p PITRRestorePayload) error {
	need := p.Base.SizeBytes + p.Base.PlaintextBytes
	var largest int64
	for _, s := range p.Segments {
		need += s.PlaintextBytes
		largest = max(largest, s.SizeBytes)
	}
	need += largest
	free, err := db.d.FreeBytes(vol)
	if err != nil {
		return fmt.Errorf("free space of the new volume: %w", err)
	}
	if free < need+drillDiskMargin {
		return fmt.Errorf("the new volume has %d MiB free, the restore needs %d MiB", free>>20, (need+drillDiskMargin)>>20)
	}
	return nil
}

// fetch downloads one object into the staging directory and checks the stored file's SHA-256.
func (db *DB) fetch(ctx context.Context, o PITRObject, staging string) (string, int64, error) {
	tmp, err := os.CreateTemp(staging, "fetch-*")
	if err != nil {
		return "", 0, err
	}
	tmp.Close()
	if _, _, err := system.Download(ctx, db.d.HTTP, o.URL, o.SHA256, tmp.Name(), 0o600, o.Headers); err != nil {
		os.Remove(tmp.Name())
		return "", 0, redact(err)
	}
	fi, err := os.Stat(tmp.Name())
	if err != nil {
		os.Remove(tmp.Name())
		return "", 0, err
	}
	return tmp.Name(), fi.Size(), nil
}

// restoreBase fetches and authenticates the base, then streams it into `falak-db restore physical` (a one-off
// container of the instance's image on the new data directory).
func (db *DB) restoreBase(ctx context.Context, s InstanceSpec, o PITRObject, staging string, st commands.Stream) (int64, error) {
	file, n, err := db.fetch(ctx, o, staging)
	if err != nil {
		return 0, fmt.Errorf("base backup: %w", err)
	}
	defer os.Remove(file)
	if err := verifyDump(file, o.Encryption, o.PlaintextSHA256); err != nil {
		return 0, err
	}
	fmt.Fprintf(st.Stdout(), "base backup verified (every segment and its SHA-256)\n")
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	in, err := openDump(file, o.Encryption, o.PlaintextSHA256)
	if err != nil {
		return 0, err
	}
	in.cancel = cancel
	defer in.Close()
	if err := in.check(db.oneOff(ctx, s, in, st.Stdout(), false, "restore", "physical", "--in", "-")); err != nil {
		return 0, fmt.Errorf("restore physical: %w", err)
	}
	return n, nil
}

// stageSegment fetches one segment and decrypts it into the replay directory: written to a dot-file, renamed to its
// name only once the whole file authenticated and its content's SHA-256 is the recorded one.
func (db *DB) stageSegment(ctx context.Context, seg PITRSegment, staging, replay string) (int64, error) {
	file, n, err := db.fetch(ctx, seg.PITRObject, staging)
	if err != nil {
		return 0, err
	}
	defer os.Remove(file)
	in, err := openDump(file, seg.Encryption, seg.PlaintextSHA256)
	if err != nil {
		return 0, err
	}
	defer in.Close()
	part := filepath.Join(replay, "."+seg.Name+".part")
	out, err := os.OpenFile(part, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return 0, err
	}
	_, err = io.Copy(out, in)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err = in.check(err); err != nil {
		os.Remove(part)
		return 0, err
	}
	return n, os.Rename(part, filepath.Join(replay, seg.Name))
}

// oneOff runs `falak-db <args>` in a throwaway container of the instance's image on its data directory (and spool),
// with no network: what needs the server stopped (restore physical, postgres' recover).
func (db *DB) oneOff(ctx context.Context, s InstanceSpec, stdin io.Reader, stdout io.Writer, spool bool, args ...string) error {
	vol := db.d.FS.P(db.volumeDir(s.VolumeID))
	argv := []string{"run", "--rm"}
	if stdin != nil {
		argv = append(argv, "-i")
	}
	argv = append(argv, "--name", Container(s.ID)+"-pitr", "--network", "none", "--entrypoint", "falak-db",
		"--mount", "type=bind,source="+filepath.Join(vol, "data")+",target="+dataTarget(s.Engine, s.Version))
	if spool {
		argv = append(argv, "--mount", "type=bind,source="+filepath.Join(vol, "spool")+",target="+spoolTarget)
	}
	argv = append(argv, s.ref())
	argv = append(argv, args...)
	var errBuf bytes.Buffer
	res, err := db.d.Runner.Run(ctx, runner.Cmd{Name: "docker", Args: argv, Stdin: stdin, Stdout: stdout, Stderr: &errBuf})
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("falak-db %s: exit %d: %s", strings.Join(args[:min(2, len(args))], " "), res.ExitCode, lastLines(errBuf.String(), 5))
	}
	return err
}

// readOnlyWhenReady runs `falak-db readonly on`, retrying while postgres still replays (it refuses changes during
// recovery).
func (db *DB) readOnlyWhenReady(ctx context.Context, id string) error {
	deadline := time.Now().Add(db.d.HealthWait)
	for {
		_, _, err := db.exec(ctx, id, nil, nil, nil, "readonly", "on")
		if err == nil {
			return nil
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("read-only mode: %w", err)
		}
		if err := sleep(ctx, db.d.Poll); err != nil {
			return err
		}
	}
}

// ---- db.pitr.promote ----

// PITRPromotePayload is db.pitr.promote: a restored instance the user decided to keep becomes writable; with Stop
// (a swap), the instance it replaces stops (it is kept, with its volume).
type PITRPromotePayload struct {
	Instance string `json:"instance"`
	Engine   string `json:"engine"`
	Stop     string `json:"stop,omitempty"`
	// InspectionUser is the copy's read-only account, dropped now that it is a database of its own.
	InspectionUser string `json:"inspection_user,omitempty"`
}

// PITRPromote ends the restored instance's read-only mode and stops the replaced one.
func (db *DB) PITRPromote(ctx context.Context, p PITRPromotePayload, st commands.Stream) (any, error) {
	if err := checkID("instance", p.Instance); err != nil {
		return nil, err
	}
	if err := checkEngine(p.Engine); err != nil {
		return nil, err
	}
	if isKeyValue(p.Engine) {
		return nil, payloadErr("point-in-time recovery is for postgres, mysql and mariadb")
	}
	ids := []string{p.Instance}
	if p.Stop != "" {
		if err := checkID("stop", p.Stop); err != nil {
			return nil, err
		}
		if p.Stop == p.Instance {
			return nil, payloadErr("an instance can't replace itself")
		}
		ids = append(ids, p.Stop)
	}
	if p.InspectionUser != "" {
		if err := checkIdent("inspection_user", p.InspectionUser); err != nil {
			return nil, err
		}
	}
	defer db.lock(ids...)()
	// The read-only config goes first (a restart), then the read-only mode of the engine.
	if err := db.dropReadOnlySettings(ctx, p.Instance, st); err != nil {
		return nil, err
	}
	if _, _, err := db.exec(ctx, p.Instance, nil, nil, nil, "readonly", "off"); err != nil {
		return nil, err
	}
	if p.InspectionUser != "" {
		if _, err := db.userApply(ctx, p.Instance, UserSpec{Username: p.InspectionUser, State: "absent", Host: map[bool]string{true: "", false: "%"}[p.Engine == "postgres"]}); err != nil {
			return nil, fmt.Errorf("dropping the inspection account: %w", err)
		}
	}
	fmt.Fprintf(st.Stdout(), "%s is writable\n", Container(p.Instance))
	res := ChangedResult{Changed: true}
	if p.Stop != "" {
		if _, err := db.d.Docker.ContainerStop(ctx, Container(p.Stop), stopGrace); err != nil && !docker.IsNotFound(err) {
			return nil, err
		}
		// The replaced instance ships nothing more (its spool is left as it is, with its volume).
		if err := os.Remove(db.d.FS.P(db.pitrFile(p.Stop))); err != nil && !errors.Is(err, os.ErrNotExist) {
			return nil, err
		}
		fmt.Fprintf(st.Stdout(), "%s stopped (kept, with its volume)\n", Container(p.Stop))
	}
	return res, nil
}

// dropReadOnlySettings takes a restored copy's inspection settings (read_only, event_scheduler) out of its settings
// file and restarts it when they were there.
func (db *DB) dropReadOnlySettings(ctx context.Context, id string, st commands.Stream) error {
	p := filepath.Join(db.d.FS.P(db.confDir(id)), "settings.json")
	cur, err := os.ReadFile(p)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	next := settingsWithout(cur, "read_only", "event_scheduler")
	if bytes.Equal(bytes.TrimSpace(cur), next) {
		return nil
	}
	if err := os.WriteFile(p+".tmp", next, 0o644); err != nil {
		return err
	}
	if err := os.Rename(p+".tmp", p); err != nil {
		return err
	}
	fmt.Fprintf(st.Stdout(), "restarting %s without its read-only settings\n", Container(id))
	if err := db.d.Docker.ContainerRestart(ctx, Container(id), stopGrace); err != nil {
		return err
	}
	_, err = db.awaitHealthy(ctx, Container(id))
	return err
}

// settingsWithout is settings JSON without the given keys.
func settingsWithout(settings json.RawMessage, keys ...string) json.RawMessage {
	m := map[string]any{}
	if len(settings) > 0 {
		_ = json.Unmarshal(settings, &m)
	}
	for _, k := range keys {
		delete(m, k)
	}
	b, _ := json.Marshal(m)
	return b
}

// removeContainer stops (60 s) and removes a container; a missing one is fine.
func (db *DB) removeContainer(ctx context.Context, name string) error {
	cur, ok, err := db.d.Docker.ContainerInspect(ctx, name)
	if err != nil || !ok {
		return err
	}
	if _, err := db.d.Docker.ContainerStop(ctx, cur.ID, stopGrace); err != nil && !docker.IsNotFound(err) {
		return err
	}
	return db.d.Docker.ContainerRemove(ctx, cur.ID)
}
