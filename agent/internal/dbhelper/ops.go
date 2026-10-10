package dbhelper

import (
	"archive/tar"
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"hash"
	"io"
	"io/fs"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Health is `falak-db health`, the image's HEALTHCHECK. It probes over TCP for SQL engines, so the private server
// the official entrypoints run during first-time initialisation (socket only) does not count as ready.
func (h *Helper) Health(ctx context.Context) error {
	var err error
	switch h.Engine {
	case Postgres:
		err = h.Run.Run(ctx, Cmd{Name: "pg_isready", Args: []string{
			"-q", "-h", "127.0.0.1", "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-d", "postgres", "-t", "5",
		}})
	case MySQL, MariaDB:
		var creds []byte
		if creds, err = h.mysqlDefaults(); err != nil {
			return err
		}
		// MySQL's caching_sha2_password needs TLS (the default, preferred) or the server's RSA key when TLS is off.
		// MariaDB 11.4's client would verify the certificate; its ping exits 0 whenever the server answers (even
		// "insecure transport prohibited"), so plaintext is enough there.
		transport := "--get-server-public-key"
		if h.Engine == MariaDB {
			transport = "--skip-ssl"
		}
		c := myCmd(creds, h.Engine.client("mysqladmin"), "--protocol=TCP", "-h", "127.0.0.1", "-P", strconv.Itoa(myPort),
			transport, "--connect-timeout=5", "ping")
		c.Stdout = io.Discard
		err = h.Run.Run(ctx, c)
	case Redis, Valkey:
		var out string
		if out, err = h.kvCmd(ctx, "PING"); err == nil && out != "PONG" {
			err = fmt.Errorf("PING answered %q", firstLine(out))
		}
	}
	if err != nil {
		return fmt.Errorf("unhealthy: %w", err)
	}
	return h.result(map[string]any{"engine": h.Engine, "status": "healthy"})
}

// streamStats counts and hashes a data stream on its way to stdout.
type streamStats struct {
	w io.Writer
	h hash.Hash
	n int64
}

func newStreamStats(w io.Writer) *streamStats { return &streamStats{w: w, h: sha256.New()} }

func (s *streamStats) Write(p []byte) (int, error) {
	n, err := s.w.Write(p)
	s.h.Write(p[:n])
	s.n += int64(n)
	return n, err
}

func (s *streamStats) sum() string { return hex.EncodeToString(s.h.Sum(nil)) }

// BackupResult is the result line of a backup (stderr, prefixed with ResultPrefix).
type BackupResult struct {
	Engine   Engine `json:"engine"`
	Kind     string `json:"kind"`   // logical | physical
	Format   string `json:"format"` // pg_dump-custom | sql | rdb | tar | xbstream
	Database string `json:"database,omitempty"`
	Bytes    int64  `json:"bytes"`
	SHA256   string `json:"sha256"`
	Started  string `json:"started_at"`
	Finished string `json:"finished_at"`
	// Postgres physical: the WAL needed to make the base consistent, first and last segment (inclusive).
	StartWAL string `json:"start_wal,omitempty"`
	StopWAL  string `json:"stop_wal,omitempty"`
	Label    string `json:"label,omitempty"`
}

func checkStdio(dir, flag string) error {
	if dir != "-" {
		return usageErr("%s must be - (the stream is stdin/stdout)", flag)
	}
	return nil
}

// BackupLogical is `falak-db backup logical --database DB --out -`.
func (h *Helper) BackupLogical(ctx context.Context, database, out string) error {
	if err := checkStdio(out, "--out"); err != nil {
		return err
	}
	if err := checkDatabase(h.Engine, database); err != nil {
		return err
	}
	res := BackupResult{Engine: h.Engine, Kind: "logical", Database: database, Started: h.Now().UTC().Format(time.RFC3339)}
	st := newStreamStats(h.Stdout)
	switch h.Engine {
	case Postgres:
		res.Format = "pg_dump-custom"
		if err := h.Run.Run(ctx, Cmd{Name: "pg_dump", Args: []string{
			"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-Fc", "-d", database,
		}, Stdout: st, Stderr: h.Stderr}); err != nil {
			return err
		}
	case MySQL, MariaDB:
		res.Format = "sql"
		creds, err := h.mysqlDefaults()
		if err != nil {
			return err
		}
		args := []string{"--single-transaction", "--routines", "--triggers",
			"--events", "--hex-blob", "--default-character-set=utf8mb4"}
		if h.Engine == MySQL {
			// The dump restores into any instance, whose GTID history is its own.
			args = append(args, "--set-gtid-purged=OFF")
		}
		c := myCmd(creds, h.Engine.client("mysqldump"), append(args, database)...)
		c.Stdout, c.Stderr = st, h.Stderr
		if err := h.Run.Run(ctx, c); err != nil {
			return err
		}
	case Redis, Valkey:
		res.Format = "rdb"
		if err := h.kvSnapshot(ctx); err != nil {
			return err
		}
		f, err := os.Open(filepath.Join(h.path(dataDir(h.Engine, h.Env)), "dump.rdb"))
		if err != nil {
			return err
		}
		defer f.Close()
		if _, err := io.Copy(st, f); err != nil {
			return err
		}
	}
	res.Bytes, res.SHA256, res.Finished = st.n, st.sum(), h.Now().UTC().Format(time.RFC3339)
	return h.streamResult(res)
}

// kvSnapshot runs BGSAVE and waits for it. rdb_saves (INFO persistence) counts finished saves, so a save that
// happens to finish within the same second as the previous one is still noticed. The new dump.rdb is renamed into
// place by the server, so reading it later always sees a complete file.
func (h *Helper) kvSnapshot(ctx context.Context) error {
	info := func() (map[string]string, error) {
		out, err := h.kvCmd(ctx, "INFO", "persistence")
		if err != nil {
			return nil, err
		}
		m := map[string]string{}
		for _, l := range strings.Split(out, "\n") {
			if k, v, ok := strings.Cut(strings.TrimSpace(l), ":"); ok {
				m[k] = v
			}
		}
		return m, nil
	}
	before, err := info()
	if err != nil {
		return err
	}
	saves, err := strconv.Atoi(before["rdb_saves"])
	if err != nil {
		return fmt.Errorf("INFO persistence has no rdb_saves")
	}
	for {
		out, err := h.kvCmd(ctx, "BGSAVE")
		if err == nil {
			break
		}
		// A save (or AOF rewrite) already running: wait for it, then ask again.
		if !strings.Contains(out, "in progress") {
			return err
		}
		h.Sleep(200 * time.Millisecond)
		if ctx.Err() != nil {
			return ctx.Err()
		}
	}
	for {
		m, err := info()
		if err != nil {
			return err
		}
		if n, _ := strconv.Atoi(m["rdb_saves"]); n > saves && m["rdb_bgsave_in_progress"] == "0" {
			if m["rdb_last_bgsave_status"] != "ok" {
				return fmt.Errorf("BGSAVE failed (rdb_last_bgsave_status=%s)", m["rdb_last_bgsave_status"])
			}
			return nil
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		h.Sleep(200 * time.Millisecond)
	}
}

// RestoreLogical is `falak-db restore logical --database DB --in -`. The database must exist (and should be empty).
// Redis/Valkey: the server must be stopped (run it in a helper container on the volume); the RDB replaces dump.rdb
// and any AOF is moved aside, so the server loads the restored data on start.
func (h *Helper) RestoreLogical(ctx context.Context, database, in string, clean bool) error {
	if err := checkStdio(in, "--in"); err != nil {
		return err
	}
	if err := checkDatabase(h.Engine, database); err != nil {
		return err
	}
	switch h.Engine {
	case Postgres:
		args := []string{"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(), "-d", database,
			"--no-owner", "--no-acl", "--exit-on-error"}
		if clean {
			args = append(args, "--clean", "--if-exists")
		}
		if err := h.Run.Run(ctx, Cmd{Name: "pg_restore", Args: args, Stdin: h.Stdin, Stderr: h.Stderr}); err != nil {
			return err
		}
	case MySQL, MariaDB:
		if clean {
			return usageErr("--clean is for postgres (a SQL dump drops and creates each table itself)")
		}
		creds, err := h.mysqlDefaults()
		if err != nil {
			return err
		}
		args, err := h.mysqlSafeClientArgs(ctx)
		if err != nil {
			return err
		}
		c := myCmd(creds, h.Engine.client("mysql"), append(args, "--default-character-set=utf8mb4", database)...)
		c.Stdin, c.Stderr = h.Stdin, h.Stderr
		if err := h.Run.Run(ctx, c); err != nil {
			return err
		}
	case Redis, Valkey:
		if clean {
			return usageErr("--clean is for postgres")
		}
		if err := h.kvRestore(ctx); err != nil {
			return err
		}
	}
	return h.result(map[string]any{"engine": h.Engine, "kind": "logical", "database": database, "restored": true})
}

func (h *Helper) kvRestore(ctx context.Context) error {
	if c, err := (&net.Dialer{Timeout: time.Second}).DialContext(ctx, "unix", h.path(kvSocket)); err == nil {
		c.Close()
		return conflict("the server is running: restore an RDB with the server stopped")
	}
	dir := h.path(dataDir(h.Engine, h.Env))
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return err
	}
	br := bufio.NewReader(h.Stdin)
	if magic, err := br.Peek(5); err != nil || string(magic) != "REDIS" {
		return usageErr("the input is not an RDB file")
	}
	tmp, err := tempFile(dir, "dump.rdb")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err = io.Copy(tmp, br); err == nil {
		err = tmp.Sync()
	}
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return err
	}
	// With AOF on, the server loads the AOF and ignores dump.rdb; move it aside (never delete data).
	aof := filepath.Join(dir, "appendonlydir")
	if _, err := os.Stat(aof); err == nil {
		if err := os.Rename(aof, filepath.Join(dir, "appendonlydir.before-restore-"+h.Now().UTC().Format("20060102T150405Z"))); err != nil {
			return err
		}
	}
	if err := os.Rename(tmp.Name(), filepath.Join(dir, "dump.rdb")); err != nil {
		return err
	}
	if err := syncDir(dir); err != nil {
		return err
	}
	return h.chownTree(dir)
}

// BackupPhysical is `falak-db backup physical --out -`.
//
// Postgres: pg_basebackup as one tar on stdout with no WAL inside (-X none): the WAL comes from the archive, which is
// the spool. With archiving on, the server only ends the backup once every segment it needs is archived ("all
// required WAL segments have been archived"), so when this command succeeds start_wal..stop_wal are in the spool.
// stop_wal comes from the backup_manifest at the end of the stream.
// MySQL: xtrabackup --stream=xbstream. MariaDB: mariadb-backup --stream=xbstream.
func (h *Helper) BackupPhysical(ctx context.Context, out string) error {
	if err := checkStdio(out, "--out"); err != nil {
		return err
	}
	res := BackupResult{Engine: h.Engine, Kind: "physical", Started: h.Now().UTC().Format(time.RFC3339)}
	st := newStreamStats(h.Stdout)
	switch h.Engine {
	case Postgres:
		res.Format = "tar"
		res.Label = "falak-" + h.Now().UTC().Format("20060102T150405Z")
		segSize, err := h.psql(ctx, "SELECT setting FROM pg_settings WHERE name = 'wal_segment_size'")
		if err != nil {
			return err
		}
		pr, pw := io.Pipe()
		scanned := make(chan tarScan, 1)
		go func() { scanned <- scanBaseTar(pr) }()
		err = h.Run.Run(ctx, Cmd{Name: "pg_basebackup", Args: []string{
			"-h", pgSocketDir, "-p", strconv.Itoa(pgPort), "-U", h.pgUser(),
			"-D", "-", "-Ft", "-X", "none", "--checkpoint=fast", "-l", res.Label,
		}, Stdout: io.MultiWriter(st, pw), Stderr: h.Stderr})
		pw.Close()
		scan := <-scanned
		if err != nil {
			return err
		}
		if res.StartWAL = parseBackupLabel(scan.label); res.StartWAL == "" {
			return errors.New("pg_basebackup: no backup_label in the stream")
		}
		size, _ := strconv.ParseInt(segSize, 10, 64)
		if res.StopWAL, err = manifestStopWAL(scan.manifestTail, size); err != nil {
			return err
		}
	case MySQL, MariaDB:
		res.Format = "xbstream"
		creds, err := h.mysqlDefaults()
		if err != nil {
			return err
		}
		work, err := os.MkdirTemp("", "falak-db-xb-")
		if err != nil {
			return err
		}
		defer os.RemoveAll(work)
		tool := "xtrabackup"
		if h.Engine == MariaDB {
			tool = "mariadb-backup"
		}
		c, cleanup, err := backupToolCmd(creds, tool, "--backup", "--stream=xbstream", "--target-dir="+work)
		if err != nil {
			return err
		}
		defer cleanup()
		c.Stdout, c.Stderr = st, h.Stderr
		if err := h.Run.Run(ctx, c); err != nil {
			return err
		}
	default:
		return unsupported(h.Engine, "backup physical")
	}
	res.Bytes, res.SHA256, res.Finished = st.n, st.sum(), h.Now().UTC().Format(time.RFC3339)
	return h.streamResult(res)
}

type tarScan struct {
	label        []byte // backup_label
	manifestTail []byte // the last 64 KiB of backup_manifest (WAL-Ranges is near its end)
}

// scanBaseTar reads a pg_basebackup tar stream to the end, keeping backup_label and the tail of backup_manifest.
func scanBaseTar(r io.Reader) tarScan {
	defer io.Copy(io.Discard, r) //nolint:errcheck // drain whatever follows the archive
	var s tarScan
	tr := tar.NewReader(r)
	for {
		hdr, err := tr.Next()
		if err != nil {
			return s
		}
		switch strings.TrimPrefix(hdr.Name, "./") {
		case "backup_label":
			s.label, _ = io.ReadAll(io.LimitReader(tr, 64<<10))
		case "backup_manifest":
			t := &tailBuffer{max: 64 << 10}
			_, _ = io.Copy(t, tr)
			s.manifestTail = t.bytes()
		}
	}
}

var labelStart = regexp.MustCompile(`(?m)^START WAL LOCATION: \S+ \(file ([0-9A-F]{24})\)`)

func parseBackupLabel(b []byte) string {
	if m := labelStart.FindSubmatch(b); m != nil {
		return string(m[1])
	}
	return ""
}

var walRange = regexp.MustCompile(`"Timeline":\s*(\d+),\s*"Start-LSN":\s*"[0-9A-F]+/[0-9A-F]+",\s*"End-LSN":\s*"([0-9A-F]+)/([0-9A-F]+)"`)

// manifestStopWAL names the segment holding the backup's end LSN (the last WAL range of the manifest).
func manifestStopWAL(manifest []byte, segSize int64) (string, error) {
	m := walRange.FindAllSubmatch(manifest, -1)
	if len(m) == 0 {
		return "", errors.New("pg_basebackup: no WAL-Ranges in the backup manifest")
	}
	last := m[len(m)-1]
	tli, _ := strconv.ParseUint(string(last[1]), 10, 32)
	hi, _ := strconv.ParseUint(string(last[2]), 16, 32)
	lo, _ := strconv.ParseUint(string(last[3]), 16, 32)
	end := hi<<32 | lo
	// An end exactly on a segment boundary needs nothing from the segment that starts there (XLByteToPrevSeg).
	if segSize > 0 && end > 0 && end%uint64(segSize) == 0 {
		end--
	}
	return walFileName(uint32(tli), end, segSize)
}

// walFileName is postgres' XLogFileName: the segment holding lsn.
func walFileName(tli uint32, lsn uint64, segSize int64) (string, error) {
	if segSize <= 0 || segSize&(segSize-1) != 0 {
		return "", fmt.Errorf("unexpected wal_segment_size %d", segSize)
	}
	segno := lsn / uint64(segSize)
	perID := uint64(0x100000000) / uint64(segSize)
	return fmt.Sprintf("%08X%08X%08X", tli, segno/perID, segno%perID), nil
}

// RestoreResult is the result of `restore physical`.
type RestoreResult struct {
	Engine   Engine `json:"engine"`
	Kind     string `json:"kind"`
	DataDir  string `json:"data_dir"`
	Files    int    `json:"files,omitempty"`
	Bytes    int64  `json:"bytes,omitempty"`
	StartWAL string `json:"start_wal,omitempty"`
	Prepared bool   `json:"prepared,omitempty"`
}

// RestorePhysical is `falak-db restore physical --in -`: unpack a base backup into the (empty) data directory, with
// the server stopped (a helper container on the new volume). MySQL/MariaDB backups are also prepared (redo applied),
// so the server can start on them directly; binlog replay is `recover`.
func (h *Helper) RestorePhysical(ctx context.Context, in string) error {
	if err := checkStdio(in, "--in"); err != nil {
		return err
	}
	dir := h.path(dataDir(h.Engine, h.Env))
	if h.Engine.kv() {
		return unsupported(h.Engine, "restore physical")
	}
	if ok, err := emptyDir(dir); err != nil {
		return err
	} else if !ok {
		return conflict("%s is not empty: physical restores go into a new, empty volume", dir)
	}
	// Parents (postgres 18: /var/lib/postgresql/18) must stay traversable for the engine's user.
	if err := os.MkdirAll(filepath.Dir(dir), 0o755); err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	res := RestoreResult{Engine: h.Engine, Kind: "physical", DataDir: dataDir(h.Engine, h.Env)}
	switch h.Engine {
	case Postgres:
		n, size, err := extractTar(h.Stdin, dir)
		if err != nil {
			return err
		}
		label, err := os.ReadFile(filepath.Join(dir, "backup_label"))
		if err != nil {
			return fmt.Errorf("the archive has no backup_label: is it a pg_basebackup tar? (%w)", err)
		}
		res.Files, res.Bytes, res.StartWAL = n, size, parseBackupLabel(label)
		if err := os.Chmod(dir, 0o700); err != nil {
			return err
		}
	case MySQL, MariaDB:
		xb, tool := "xbstream", "xtrabackup"
		if h.Engine == MariaDB {
			xb, tool = "mbstream", "mariadb-backup"
		}
		if err := h.Run.Run(ctx, Cmd{Name: xb, Args: []string{"-x", "-C", dir}, Stdin: h.Stdin, Stderr: h.Stderr}); err != nil {
			return err
		}
		if err := h.Run.Run(ctx, Cmd{Name: tool, Args: []string{"--prepare", "--target-dir=" + dir}, Stderr: h.Stderr}); err != nil {
			return err
		}
		res.Prepared = true
	}
	if err := h.chownTree(dir); err != nil {
		return err
	}
	return h.result(res)
}

// extractTar unpacks regular files and directories only: no links, devices, absolute paths or "..".
func extractTar(r io.Reader, dst string) (files int, size int64, err error) {
	tr := tar.NewReader(r)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return files, size, nil
		}
		if err != nil {
			return files, size, fmt.Errorf("tar: %w", err)
		}
		name := filepath.Clean(strings.TrimPrefix(hdr.Name, "./"))
		if name == "." {
			continue
		}
		if filepath.IsAbs(name) || name == ".." || strings.HasPrefix(name, "../") {
			return files, size, fmt.Errorf("tar: unsafe path %q", hdr.Name)
		}
		p := filepath.Join(dst, name)
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(p, 0o700); err != nil {
				return files, size, err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
				return files, size, err
			}
			f, err := os.OpenFile(p, os.O_WRONLY|os.O_CREATE|os.O_EXCL, os.FileMode(hdr.Mode)&0o700|0o600)
			if err != nil {
				return files, size, err
			}
			n, err := io.Copy(f, tr)
			if cerr := f.Close(); err == nil {
				err = cerr
			}
			if err != nil {
				return files, size, err
			}
			files++
			size += n
		default:
			return files, size, fmt.Errorf("tar: %q is a %s, only files and directories are restored", hdr.Name, typeName(hdr.Typeflag))
		}
	}
}

func typeName(t byte) string {
	switch t {
	case tar.TypeSymlink:
		return "symlink"
	case tar.TypeLink:
		return "hard link"
	}
	return fmt.Sprintf("type %q entry", t)
}

// RecoverOptions are `falak-db recover` flags.
type RecoverOptions struct {
	TargetTime string // RFC 3339; empty = replay everything available
	WALDir     string // postgres: archived WAL segments to replay
	BinlogDir  string // mysql/mariadb: binlogs to replay
	Action     string // postgres: promote (default) | pause | shutdown
}

// Recover is `falak-db recover`.
//
// Postgres (server stopped, after `restore physical`): writes recovery.signal and falak-recovery.conf
// (restore_command reading --wal-dir, recovery_target_time, recovery_target_action). The next start replays WAL to
// the target, then by default promotes: the restored instance is a new one (the original is untouched), so opening it
// is safe, and making inspection read-only is the agent's business. "pause" exists, but a paused standby holds the
// locks of transactions in flight at the target, so a table that a later DROP TABLE touched cannot even be read until
// `falak-db promote`. Without --target-time, it replays all available WAL and promotes.
//
// MySQL/MariaDB (server running, after `restore physical` and a start): replays the binlogs in --binlog-dir from the
// backup's binlog position up to --target-time (mysqlbinlog --stop-datetime, UTC) into the server.
func (h *Helper) Recover(ctx context.Context, o RecoverOptions) error {
	var target time.Time
	if o.TargetTime != "" {
		t, err := time.Parse(time.RFC3339Nano, o.TargetTime)
		if err != nil {
			return usageErr("--target-time must be RFC 3339 (2026-10-07T12:00:00Z)")
		}
		target = t.UTC()
	}
	switch h.Engine {
	case Postgres:
		return h.pgRecover(o, target)
	case MySQL, MariaDB:
		return h.myRecover(ctx, o, target)
	}
	return unsupported(h.Engine, "recover")
}

func (h *Helper) pgRecover(o RecoverOptions, target time.Time) error {
	if o.BinlogDir != "" {
		return usageErr("--binlog-dir is for mysql and mariadb")
	}
	if !safeShellPath.MatchString(o.WALDir) || !h.allowedWALDir(o.WALDir) {
		return usageErr("--wal-dir must be a directory under /replay or the spool (%s), as a clean absolute path", spoolDir(h.Env))
	}
	if o.Action != "" && target.IsZero() {
		return usageErr("--action needs --target-time (without a target, postgres replays everything and promotes)")
	}
	action := o.Action
	if action == "" {
		action = "promote"
	}
	if !contains([]string{"pause", "promote", "shutdown"}, action) {
		return usageErr("--action must be pause, promote or shutdown")
	}
	dir := h.path(dataDir(h.Engine, h.Env))
	if !fileExists(filepath.Join(dir, "backup_label")) {
		return conflict("%s has no backup_label: run restore physical first", dataDir(h.Engine, h.Env))
	}
	walDir := h.path(o.WALDir)
	if st, err := os.Lstat(walDir); err != nil || !st.IsDir() {
		return usageErr("--wal-dir %s is not a directory", o.WALDir)
	}
	var b strings.Builder
	b.WriteString(header)
	fmt.Fprintf(&b, "restore_command = 'falak-db wal-fetch %%f %%p --from %s'\n", o.WALDir)
	if !target.IsZero() {
		fmt.Fprintf(&b, "recovery_target_time = %s\n", pgQuote(target.Format("2006-01-02 15:04:05.999999-07")))
		fmt.Fprintf(&b, "recovery_target_action = %s\n", pgQuote(action))
	}
	conf := filepath.Join(dir, recoveryConf)
	if err := writeFileAtomic(conf, []byte(b.String()), 0o600); err != nil {
		return err
	}
	signal := filepath.Join(dir, "recovery.signal")
	if err := writeFileAtomic(signal, nil, 0o600); err != nil {
		return err
	}
	// The server (as postgres) reads the segments through wal-fetch.
	if err := h.chown(conf, signal); err != nil {
		return err
	}
	if err := h.chownWAL(walDir); err != nil {
		return err
	}
	res := map[string]any{"engine": h.Engine, "mode": "on-start", "wal_dir": o.WALDir}
	if !target.IsZero() {
		res["target_time"] = target.Format(time.RFC3339Nano)
		res["action"] = action
	}
	return h.result(res)
}

// walReplayRoot is where the agent mounts the WAL (or binlogs) to replay into a restored instance.
const walReplayRoot = "/replay"

// allowedWALDir accepts /replay, the spool, or a directory under one of them, written as a clean path.
func (h *Helper) allowedWALDir(dir string) bool {
	if filepath.Clean(dir) != dir {
		return false
	}
	for _, root := range []string{walReplayRoot, filepath.Clean(spoolDir(h.Env))} {
		if root != "/" && (dir == root || strings.HasPrefix(dir, root+"/")) {
			return true
		}
	}
	return false
}

// walFile matches what restore_command fetches: segments, partial segments, backup history and timeline history.
var walFile = regexp.MustCompile(`^([0-9A-F]{24}(\.partial|\.[0-9A-F]{8}\.backup)?|[0-9A-F]{8}\.history)$`)

// chownWAL hands the WAL directory and the WAL files directly in it (nothing else, not recursively, no links) to
// postgres, which reads them through wal-fetch.
func (h *Helper) chownWAL(dir string) error {
	if err := h.chown(dir); err != nil {
		return err
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return err
	}
	for _, e := range entries {
		if e.Type().IsRegular() && walFile.MatchString(e.Name()) {
			if err := h.chown(filepath.Join(dir, e.Name())); err != nil {
				return err
			}
		}
	}
	return nil
}

// binlogInfo reads the binlog file and position a physical backup was taken at.
func binlogInfo(dataDir string) (file string, pos int64, err error) {
	for _, name := range []string{"xtrabackup_binlog_info", "mariadb_backup_binlog_info"} {
		b, err := os.ReadFile(filepath.Join(dataDir, name))
		if errors.Is(err, fs.ErrNotExist) {
			continue
		}
		if err != nil {
			return "", 0, err
		}
		f := strings.Fields(string(b))
		if len(f) < 2 {
			return "", 0, fmt.Errorf("%s: unexpected content", name)
		}
		pos, err := strconv.ParseInt(f[1], 10, 64)
		if err != nil || !validName(f[0]) {
			return "", 0, fmt.Errorf("%s: unexpected content", name)
		}
		return f[0], pos, nil
	}
	return "", 0, conflict("no xtrabackup_binlog_info in the data directory: was it restored with restore physical?")
}

// binlogSeq splits "binlog.000042" into ("binlog", 42).
func binlogSeq(name string) (string, int, bool) {
	i := strings.LastIndexByte(name, '.')
	if i <= 0 {
		return "", 0, false
	}
	n, err := strconv.Atoi(name[i+1:])
	if err != nil || n < 0 {
		return "", 0, false
	}
	return name[:i], n, true
}

// binlogsFrom lists dir's binlogs of the same series as start, from start on, in order.
func binlogsFrom(dir, start string) ([]string, error) {
	base, first, ok := binlogSeq(start)
	if !ok {
		return nil, fmt.Errorf("unexpected binlog name %q", start)
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	type bl struct {
		name string
		seq  int
	}
	var list []bl
	for _, e := range entries {
		b, n, ok := binlogSeq(e.Name())
		if ok && b == base && n >= first && e.Type().IsRegular() {
			list = append(list, bl{e.Name(), n})
		}
	}
	sort.Slice(list, func(i, j int) bool { return list[i].seq < list[j].seq })
	var names []string
	for i, b := range list {
		if b.seq != first+i {
			return nil, fmt.Errorf("binlog %s.%06d is missing from %s", base, first+i, dir)
		}
		names = append(names, b.name)
	}
	if len(names) == 0 || names[0] != start {
		return nil, fmt.Errorf("%s is not in %s", start, dir)
	}
	return names, nil
}

func (h *Helper) myRecover(ctx context.Context, o RecoverOptions, target time.Time) error {
	if o.WALDir != "" || o.Action != "" {
		return usageErr("--wal-dir and --action are for postgres")
	}
	if o.BinlogDir == "" {
		return usageErr("--binlog-dir is required")
	}
	if !h.allowedWALDir(o.BinlogDir) {
		return usageErr("--binlog-dir must be a directory under /replay or the spool (%s), as a clean absolute path", spoolDir(h.Env))
	}
	// Binlog events have whole-second timestamps: the target is rounded down, and replay stops before the first event
	// at or after it.
	target = target.Truncate(time.Second)
	start, pos, err := binlogInfo(h.path(dataDir(h.Engine, h.Env)))
	if err != nil {
		return err
	}
	dir := h.path(o.BinlogDir)
	names, err := binlogsFrom(dir, start)
	if err != nil {
		return err
	}
	args := []string{"--start-position=" + strconv.FormatInt(pos, 10)}
	if !target.IsZero() {
		args = append(args, "--stop-datetime="+target.Format("2006-01-02 15:04:05"))
	}
	for _, n := range names {
		args = append(args, filepath.Join(dir, n))
	}
	creds, err := h.mysqlDefaults()
	if err != nil {
		return err
	}
	safe, err := h.mysqlSafeClientArgs(ctx)
	if err != nil {
		return err
	}
	apply := myCmd(creds, h.Engine.client("mysql"), safe...)
	apply.Stderr = h.Stderr
	// mysqlbinlog reads --stop-datetime in its own time zone.
	if err := pipe(ctx, h.Run,
		Cmd{Name: h.Engine.client("mysqlbinlog"), Args: args, Env: []string{"TZ=UTC"}, Stderr: h.Stderr},
		apply,
	); err != nil {
		return err
	}
	res := map[string]any{"engine": h.Engine, "mode": "replayed", "binlogs": names, "start_position": pos}
	if !target.IsZero() {
		res["target_time"] = target.Format(time.RFC3339)
	}
	return h.result(res)
}

// Promote is `falak-db promote` (postgres): end recovery paused at the target and open the instance for writes, then
// drop the recovery settings.
func (h *Helper) Promote(ctx context.Context) error {
	if h.Engine != Postgres {
		return unsupported(h.Engine, "promote")
	}
	out, err := h.psql(ctx, "SELECT pg_promote(true, 60)")
	if err != nil {
		return err
	}
	if out != "t" {
		return errors.New("pg_promote did not finish within 60 s")
	}
	if err := os.Remove(filepath.Join(h.path(dataDir(h.Engine, h.Env)), recoveryConf)); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	return h.result(map[string]any{"engine": h.Engine, "promoted": true})
}

// WALPush is postgres' archive_command (`falak-db wal-push %p`, run in the data directory). It prints nothing on
// success, as postgres expects; failures (exit != 0) make postgres retry.
func (h *Helper) WALPush(path string) error {
	if h.Engine != Postgres {
		return unsupported(h.Engine, "wal-push")
	}
	_, err := spoolFile(path, h.path(spoolDir(h.Env)), spoolWAL, filepath.Base(path))
	return err
}

// WALFetch is postgres' restore_command (`falak-db wal-fetch %f %p --from DIR`); silent on success.
func (h *Helper) WALFetch(name, dst, from string) error {
	if h.Engine != Postgres {
		return unsupported(h.Engine, "wal-fetch")
	}
	if from == "" {
		return usageErr("--from is required")
	}
	_, err := fetchFile(h.path(from), name, dst)
	return err
}

const binlogPositionFile = ".binlog-last"

// BinlogRotate is `falak-db binlog-rotate`: FLUSH BINARY LOGS (unless noFlush), then spool every closed binlog not
// spooled yet, oldest first. The newest binlog is the one being written, so it is never spooled. The last spooled
// name is kept in the spool (.binlog-last) so files the agent already shipped (and removed) are not spooled again.
func (h *Helper) BinlogRotate(ctx context.Context, noFlush, restart bool) error {
	if !h.Engine.mysqlFamily() {
		return unsupported(h.Engine, "binlog-rotate")
	}
	creds, err := h.mysqlDefaults()
	if err != nil {
		return err
	}
	if !noFlush {
		if _, err := h.mysqlQuery(ctx, creds, "FLUSH BINARY LOGS"); err != nil {
			return err
		}
	}
	out, err := h.mysqlQuery(ctx, creds, "SHOW BINARY LOGS")
	if err != nil {
		return err
	}
	var logs []string
	for _, l := range strings.Split(strings.TrimSpace(out), "\n") {
		if f := strings.Fields(l); len(f) > 0 {
			logs = append(logs, f[0])
		}
	}
	basename, err := h.mysqlQuery(ctx, creds, "SELECT @@log_bin_basename")
	if err != nil {
		return err
	}
	binDir := h.path(filepath.Dir(strings.TrimSpace(basename)))
	spool := h.path(spoolDir(h.Env))
	if err := os.MkdirAll(spool, 0o700); err != nil {
		return err
	}
	posFile := filepath.Join(spool, binlogPositionFile)
	last := ""
	if b, err := os.ReadFile(posFile); err == nil {
		last = strings.TrimSpace(string(b))
	} else if !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	if restart {
		last = ""
	}
	_, lastSeq, haveLast := binlogSeq(last)

	res := BinlogRotateResult{Engine: h.Engine, Flushed: !noFlush, Spooled: []Spooled{}, Gaps: []BinlogGap{}}
	if len(logs) > 0 {
		res.Current = logs[len(logs)-1]
	}
	gaps, closed := binlogGaps(logs, last)
	res.Gaps = append(res.Gaps, gaps...)
	reset := len(gaps) > 0 && gaps[0].Kind == "reset"
	if !reset {
		for _, name := range closed {
			if _, seq, _ := binlogSeq(name); haveLast && seq <= lastSeq {
				continue
			}
			s, err := spoolFile(filepath.Join(binDir, name), spool, spoolBinlog, name)
			if err != nil {
				return err
			}
			res.Spooled = append(res.Spooled, s)
			if err := writeFileAtomic(posFile, []byte(name+"\n"), 0o600); err != nil {
				return err
			}
		}
	}
	if err := h.result(res); err != nil {
		return err
	}
	if len(res.Gaps) > 0 {
		return conflict("binlog chain broken: %s", res.Gaps[0].Detail)
	}
	return nil
}

// BinlogRotateResult is the JSON result of binlog-rotate. With gaps, the command still prints it, and exits 4.
type BinlogRotateResult struct {
	Engine  Engine      `json:"engine"`
	Flushed bool        `json:"flushed"`
	Current string      `json:"current,omitempty"`
	Spooled []Spooled   `json:"spooled"`
	Gaps    []BinlogGap `json:"gaps"`
}

// BinlogGap is a break in the binlog chain: point-in-time recovery cannot cross it, so the agent alerts and takes a
// new base backup.
//
//	missing: binlogs between two spooled ones are gone (purged or expired before they were spooled); From..To are
//	         the missing names. What is still there is spooled.
//	reset:   the server's numbering went backwards (RESET BINARY LOGS, a new data directory): nothing is spooled until
//	         `binlog-rotate --restart`, after a new base backup.
type BinlogGap struct {
	Kind   string `json:"kind"`
	From   string `json:"from,omitempty"`
	To     string `json:"to,omitempty"`
	Detail string `json:"detail"`
}

// binlogGaps compares the server's binlogs (oldest first; the last is the one being written) with the last spooled
// name, and returns the gaps and the closed binlogs.
func binlogGaps(logs []string, last string) ([]BinlogGap, []string) {
	if len(logs) == 0 {
		return nil, nil
	}
	closed := logs[:len(logs)-1]
	base, lastSeq, haveLast := binlogSeq(last)
	curBase, curSeq, ok := binlogSeq(logs[len(logs)-1])
	if !ok {
		return []BinlogGap{{Kind: "reset", Detail: fmt.Sprintf("unexpected binlog name %q", logs[len(logs)-1])}}, closed
	}
	name := func(n int) string { return fmt.Sprintf("%s.%06d", curBase, n) }
	if haveLast && (base != curBase || curSeq <= lastSeq) {
		return []BinlogGap{{Kind: "reset", From: last, To: logs[len(logs)-1], Detail: fmt.Sprintf(
			"the server is writing %s but %s was already spooled: the binlogs were reset; take a new base backup, then run binlog-rotate --restart",
			logs[len(logs)-1], last)}}, closed
	}
	var gaps []BinlogGap
	expect := -1
	if haveLast {
		expect = lastSeq + 1
	}
	for _, l := range logs {
		_, seq, ok := binlogSeq(l)
		if !ok {
			continue
		}
		if expect >= 0 && seq > expect {
			gaps = append(gaps, BinlogGap{Kind: "missing", From: name(expect), To: name(seq - 1), Detail: fmt.Sprintf(
				"%s to %s are gone from the server before they were spooled; take a new base backup", name(expect), name(seq-1))})
		}
		if seq >= expect {
			expect = seq + 1
		}
	}
	return gaps, closed
}
