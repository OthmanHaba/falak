package db

import (
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/OthmanHaba/falak/agent/internal/commands"
	"github.com/OthmanHaba/falak/agent/internal/runner"
	"github.com/OthmanHaba/falak/agent/internal/system"
)

// Location is a backup destination / restore source.
type Location struct {
	Kind    string            `json:"kind"` // local | presigned_url | url
	Path    string            `json:"path"`
	URL     string            `json:"url"`
	Headers map[string]string `json:"headers"`
}

// BackupPayload is db.backup.
type BackupPayload struct {
	Engine      string   `json:"engine"`
	Database    string   `json:"database"`
	Compression string   `json:"compression"`
	Destination Location `json:"destination"`
}

// BackupResult is its result.
type BackupResult struct {
	SizeBytes  int64  `json:"size_bytes"`
	SHA256     string `json:"sha256"`
	Location   string `json:"location"`
	DurationMS int64  `json:"duration_ms"`
	// RDB is a Redis / Valkey snapshot's format, e.g. REDIS0011 or VALKEY080.
	RDB string `json:"rdb,omitempty"`
	// UncompressedBytes is the dump's size before compression; a restore of a Redis / Valkey snapshot gets it back
	// (db.restore uncompressed_bytes) to check the disk's free space and cap what it writes.
	UncompressedBytes int64 `json:"uncompressed_bytes,omitempty"`
}

func (e engine) dumpCmd(dbname string) runner.Cmd {
	if e.name == "mysql" {
		return runner.Cmd{Name: "mysqldump", Args: []string{"--single-transaction", "--quick", "--routines", "--triggers", "--events",
			"--hex-blob", "--default-character-set=utf8mb4", "--no-tablespaces", dbname}}
	}
	return runner.Cmd{Name: "pg_dump", Args: []string{"-Fp", "--no-owner", "--no-acl", "-d", dbname}, User: "postgres"}
}

// Backup dumps a database (Redis / Valkey: an RDB snapshot of the instance, see redisBackup), optionally gzips it, and
// stores it locally or PUTs it to a presigned URL.
func (db *DB) Backup(ctx context.Context, p BackupPayload, st commands.Stream) (any, error) {
	if isKeyValue(p.Engine) {
		return db.redisBackup(ctx, p, st)
	}
	start := time.Now()
	e, err := db.engine(p.Engine)
	if err != nil {
		return nil, err
	}
	if err := checkIdent("database", p.Database); err != nil {
		return nil, err
	}
	if err := checkDestination(p.Destination); err != nil {
		return nil, err
	}
	return db.ship(ctx, p, start, st, func(out io.Writer) error {
		c := e.dumpCmd(p.Database)
		c.Stdout = out
		c.Stderr = st.Stderr()
		res, err := db.d.Runner.Run(ctx, c)
		if err == nil && res.ExitCode != 0 {
			err = &runner.ExitError{Cmd: c.Name, Code: res.ExitCode, Stderr: string(res.Stderr)}
		}
		if err != nil {
			return fmt.Errorf("dump %s: %w", p.Database, err)
		}
		return nil
	})
}

func isKeyValue(engine string) bool { return engine == "redis" || engine == "valkey" }

func checkDestination(d Location) error {
	switch d.Kind {
	case "local":
		if !strings.HasPrefix(d.Path, "/") {
			return &commands.PayloadError{Err: errors.New("destination.path must be absolute")}
		}
	case "presigned_url":
		if !strings.HasPrefix(d.URL, "https://") {
			return &commands.PayloadError{Err: errors.New("destination.url must be https")}
		}
	default:
		return &commands.PayloadError{Err: fmt.Errorf("unknown destination kind %q", d.Kind)}
	}
	return nil
}

// ship runs write into a staging file (gzipped unless compression is none, hashed), then moves it to the local
// destination or PUTs it to the presigned URL.
func (db *DB) ship(ctx context.Context, p BackupPayload, start time.Time, st commands.Stream, write func(io.Writer) error) (BackupResult, error) {
	var file string
	if p.Destination.Kind == "local" {
		if err := db.d.FS.MkdirAll(filepath.Dir(p.Destination.Path), 0o700); err != nil {
			return BackupResult{}, err
		}
		file = db.d.FS.P(p.Destination.Path) + ".partial"
	} else {
		f, err := os.CreateTemp(db.d.TempDir, "falak-backup-*")
		if err != nil {
			return BackupResult{}, err
		}
		f.Close()
		file = f.Name()
	}
	defer os.Remove(file)

	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return BackupResult{}, err
	}
	h := sha256.New()
	fileW := io.MultiWriter(f, h)
	var out io.Writer = fileW
	var gz *gzip.Writer
	if p.Compression != "none" {
		gz = gzip.NewWriter(fileW)
		out = gz
	}
	counted := &countingWriter{w: out}
	err = write(counted)
	if gz != nil {
		if cerr := gz.Close(); err == nil {
			err = cerr
		}
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return BackupResult{}, err
	}
	fi, err := os.Stat(file)
	if err != nil {
		return BackupResult{}, err
	}
	result := BackupResult{SizeBytes: fi.Size(), SHA256: hex.EncodeToString(h.Sum(nil)), UncompressedBytes: counted.n}
	if p.Destination.Kind == "local" {
		if err := os.Rename(file, db.d.FS.P(p.Destination.Path)); err != nil {
			return BackupResult{}, err
		}
		result.Location = p.Destination.Path
	} else {
		if err := db.put(ctx, file, fi.Size(), p.Destination); err != nil {
			return BackupResult{}, err
		}
		u, _ := url.Parse(p.Destination.URL)
		u.RawQuery = "" // never echo the signature back
		result.Location = u.String()
	}
	fmt.Fprintf(st.Stdout(), "backup of %s: %d bytes, sha256 %s\n", p.Database, result.SizeBytes, result.SHA256)
	result.DurationMS = time.Since(start).Milliseconds()
	return result, nil
}

func (db *DB) put(ctx context.Context, file string, size int64, d Location) error {
	f, err := os.Open(file)
	if err != nil {
		return err
	}
	defer f.Close()
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, d.URL, f)
	if err != nil {
		return err
	}
	req.ContentLength = size
	req.Header.Set("Content-Type", "application/octet-stream")
	for k, v := range d.Headers {
		req.Header.Set(k, v)
	}
	resp, err := db.d.HTTP.Do(req)
	if err != nil {
		return fmt.Errorf("upload backup: %w", redact(err))
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("upload backup: %s: %s", resp.Status, b)
	}
	return nil
}

// redact strips the query string (signature) from url errors.
func redact(err error) error {
	var ue *url.Error
	if errors.As(err, &ue) {
		if u, perr := url.Parse(ue.URL); perr == nil {
			u.RawQuery = ""
			ue.URL = u.String()
		}
	}
	return err
}

// RestorePayload is db.restore.
type RestorePayload struct {
	Engine      string   `json:"engine"`
	Database    string   `json:"database"`
	Compression string   `json:"compression"`
	Source      Location `json:"source"`
	SHA256      string   `json:"sha256"`
	// UncompressedBytes is the size the backup recorded (feature db.redis.restore_checks; Redis / Valkey restores
	// check the free space against it and cap the gunzipped copy).
	UncompressedBytes int64 `json:"uncompressed_bytes,omitempty"`
}

// RestoreResult is its result.
type RestoreResult struct {
	Bytes      int64 `json:"bytes"`
	DurationMS int64 `json:"duration_ms"`
	// Redis / Valkey: the snapshot's format and the instance's earlier files, moved aside (data directory names).
	RDB        string   `json:"rdb,omitempty"`
	MovedAside []string `json:"moved_aside,omitempty"`
	// Warnings: the restore succeeded, but something needs attention (Redis / Valkey: a config file that could not
	// be put back).
	Warnings []string `json:"warnings,omitempty"`
}

type countingWriter struct {
	w io.Writer
	n int64
}

func (c *countingWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n += int64(n)
	return n, err
}

type countingReader struct {
	r io.Reader
	n int64
}

func (c *countingReader) Read(p []byte) (int, error) {
	n, err := c.r.Read(p)
	c.n += int64(n)
	return n, err
}

// Restore loads a dump into a database (created if missing); Redis / Valkey: see redisRestore.
func (db *DB) Restore(ctx context.Context, p RestorePayload, st commands.Stream) (any, error) {
	if isKeyValue(p.Engine) {
		return db.redisRestore(ctx, p, st)
	}
	start := time.Now()
	e, err := db.engine(p.Engine)
	if err != nil {
		return nil, err
	}
	if err := checkIdent("database", p.Database); err != nil {
		return nil, err
	}
	file, cleanup, err := db.fetchSource(ctx, p)
	if err != nil {
		return nil, err
	}
	defer cleanup()
	if _, err := db.Create(ctx, CreatePayload{Engine: p.Engine, Name: p.Database}, st); err != nil {
		return nil, err
	}
	in, closeIn, err := openDump(file, p.Compression)
	if err != nil {
		return nil, err
	}
	defer closeIn()
	cr := &countingReader{r: in}
	c := e.cmd(p.Database)
	c.Stdin = cr
	c.Stdout, c.Stderr = st.Stdout(), st.Stderr()
	if _, err := runner.Check(ctx, db.d.Runner, c); err != nil {
		return nil, fmt.Errorf("restore into %s: %w", p.Database, err)
	}
	return RestoreResult{Bytes: cr.n, DurationMS: time.Since(start).Milliseconds()}, nil
}

// fetchSource gives the dump as a local file: a local source checked against sha256, or a URL downloaded (and checked)
// into a temporary file that cleanup removes.
func (db *DB) fetchSource(ctx context.Context, p RestorePayload) (file string, cleanup func(), err error) {
	cleanup = func() {}
	switch p.Source.Kind {
	case "local":
		file = db.d.FS.P(p.Source.Path)
		if p.SHA256 != "" {
			sum, err := system.FileSHA256(file)
			if err != nil {
				return "", cleanup, err
			}
			if !strings.EqualFold(sum, p.SHA256) {
				return "", cleanup, fmt.Errorf("sha256 mismatch: got %s, want %s", sum, p.SHA256)
			}
		}
		return file, cleanup, nil
	case "url":
		tmp, err := os.CreateTemp(db.d.TempDir, "falak-restore-*")
		if err != nil {
			return "", cleanup, err
		}
		tmp.Close()
		cleanup = func() { os.Remove(tmp.Name()) }
		if _, _, err := system.Download(ctx, db.d.HTTP, p.Source.URL, p.SHA256, tmp.Name(), 0o600, p.Source.Headers); err != nil {
			cleanup()
			return "", func() {}, redact(err)
		}
		return tmp.Name(), cleanup, nil
	}
	return "", cleanup, &commands.PayloadError{Err: fmt.Errorf("unknown source kind %q", p.Source.Kind)}
}

// openDump opens a dump file, gunzipping it unless compression is none.
func openDump(file, compression string) (io.Reader, func(), error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, nil, err
	}
	if compression == "none" {
		return f, func() { f.Close() }, nil
	}
	gz, err := gzip.NewReader(f)
	if err != nil {
		f.Close()
		return nil, nil, fmt.Errorf("gunzip: %w", err)
	}
	return gz, func() { gz.Close(); f.Close() }, nil
}
