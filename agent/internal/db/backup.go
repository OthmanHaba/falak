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

	"github.com/kiln/agent/internal/commands"
	"github.com/kiln/agent/internal/runner"
	"github.com/kiln/agent/internal/system"
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
}

func (e engine) dumpCmd(dbname string) runner.Cmd {
	if e.name == "mysql" {
		return runner.Cmd{Name: "mysqldump", Args: []string{"--single-transaction", "--quick", "--routines", "--triggers", "--events",
			"--hex-blob", "--default-character-set=utf8mb4", "--no-tablespaces", dbname}}
	}
	return runner.Cmd{Name: "pg_dump", Args: []string{"-Fp", "--no-owner", "--no-acl", "-d", dbname}, User: "postgres"}
}

// Backup dumps a database, optionally gzips it, and stores it locally or PUTs it to a presigned URL.
func (db *DB) Backup(ctx context.Context, p BackupPayload, st commands.Stream) (any, error) {
	start := time.Now()
	e, err := db.engine(p.Engine)
	if err != nil {
		return nil, err
	}
	if err := checkIdent("database", p.Database); err != nil {
		return nil, err
	}
	var file string
	switch p.Destination.Kind {
	case "local":
		if !strings.HasPrefix(p.Destination.Path, "/") {
			return nil, &commands.PayloadError{Err: errors.New("destination.path must be absolute")}
		}
		if err := db.d.FS.MkdirAll(filepath.Dir(p.Destination.Path), 0o700); err != nil {
			return nil, err
		}
		file = db.d.FS.P(p.Destination.Path) + ".partial"
	case "presigned_url":
		if !strings.HasPrefix(p.Destination.URL, "https://") {
			return nil, &commands.PayloadError{Err: errors.New("destination.url must be https")}
		}
		f, err := os.CreateTemp(db.d.TempDir, "kiln-backup-*")
		if err != nil {
			return nil, err
		}
		f.Close()
		file = f.Name()
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("unknown destination kind %q", p.Destination.Kind)}
	}
	defer os.Remove(file)

	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, err
	}
	h := sha256.New()
	fileW := io.MultiWriter(f, h)
	var out io.Writer = fileW
	var gz *gzip.Writer
	if p.Compression != "none" {
		gz = gzip.NewWriter(fileW)
		out = gz
	}
	c := e.dumpCmd(p.Database)
	c.Stdout = out
	c.Stderr = st.Stderr()
	res, err := db.d.Runner.Run(ctx, c)
	if err == nil && res.ExitCode != 0 {
		err = &runner.ExitError{Cmd: c.Name, Code: res.ExitCode, Stderr: string(res.Stderr)}
	}
	if gz != nil {
		if cerr := gz.Close(); err == nil {
			err = cerr
		}
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return nil, fmt.Errorf("dump %s: %w", p.Database, err)
	}
	fi, err := os.Stat(file)
	if err != nil {
		return nil, err
	}
	result := BackupResult{SizeBytes: fi.Size(), SHA256: hex.EncodeToString(h.Sum(nil))}
	if p.Destination.Kind == "local" {
		if err := os.Rename(file, db.d.FS.P(p.Destination.Path)); err != nil {
			return nil, err
		}
		result.Location = p.Destination.Path
	} else {
		if err := db.put(ctx, file, fi.Size(), p.Destination); err != nil {
			return nil, err
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
}

// RestoreResult is its result.
type RestoreResult struct {
	Bytes      int64 `json:"bytes"`
	DurationMS int64 `json:"duration_ms"`
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

// Restore loads a dump into a database (created if missing).
func (db *DB) Restore(ctx context.Context, p RestorePayload, st commands.Stream) (any, error) {
	start := time.Now()
	e, err := db.engine(p.Engine)
	if err != nil {
		return nil, err
	}
	if err := checkIdent("database", p.Database); err != nil {
		return nil, err
	}
	var file string
	switch p.Source.Kind {
	case "local":
		file = db.d.FS.P(p.Source.Path)
		if p.SHA256 != "" {
			sum, err := system.FileSHA256(file)
			if err != nil {
				return nil, err
			}
			if !strings.EqualFold(sum, p.SHA256) {
				return nil, fmt.Errorf("sha256 mismatch: got %s, want %s", sum, p.SHA256)
			}
		}
	case "url":
		tmp, err := os.CreateTemp(db.d.TempDir, "kiln-restore-*")
		if err != nil {
			return nil, err
		}
		tmp.Close()
		defer os.Remove(tmp.Name())
		if _, _, err := system.Download(ctx, db.d.HTTP, p.Source.URL, p.SHA256, tmp.Name(), 0o600, p.Source.Headers); err != nil {
			return nil, redact(err)
		}
		file = tmp.Name()
	default:
		return nil, &commands.PayloadError{Err: fmt.Errorf("unknown source kind %q", p.Source.Kind)}
	}
	if _, err := db.Create(ctx, CreatePayload{Engine: p.Engine, Name: p.Database}, st); err != nil {
		return nil, err
	}
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	var in io.Reader = f
	if p.Compression != "none" {
		gz, err := gzip.NewReader(f)
		if err != nil {
			return nil, fmt.Errorf("gunzip: %w", err)
		}
		defer gz.Close()
		in = gz
	}
	cr := &countingReader{r: in}
	c := e.cmd(p.Database)
	c.Stdin = cr
	c.Stdout, c.Stderr = st.Stdout(), st.Stderr()
	if _, err := runner.Check(ctx, db.d.Runner, c); err != nil {
		return nil, fmt.Errorf("restore into %s: %w", p.Database, err)
	}
	return RestoreResult{Bytes: cr.n, DurationMS: time.Since(start).Milliseconds()}, nil
}
