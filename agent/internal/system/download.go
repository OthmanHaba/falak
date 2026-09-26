package system

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// DownloadAttempts and DownloadBackoff bound retries of transient failures (DNS, resets, truncated
// bodies, 5xx, 429). Variables so tests can shorten them.
var (
	DownloadAttempts = 4
	DownloadBackoff  = time.Second
)

// permanentError marks failures that retrying cannot fix (bad URL, 4xx, checksum mismatch, local I/O).
type permanentError struct{ error }

func (e permanentError) Unwrap() error { return e.error }

// Download fetches url over HTTPS into dst (real path) atomically. When wantSHA is non-empty the
// SHA-256 must match, otherwise nothing is written. Returns the hex digest and byte count.
// Transient network failures are retried with exponential backoff.
func Download(ctx context.Context, client *http.Client, url, wantSHA, dst string, mode os.FileMode, headers map[string]string) (string, int64, error) {
	wait := DownloadBackoff
	for attempt := 1; ; attempt++ {
		sum, n, err := downloadOnce(ctx, client, url, wantSHA, dst, mode, headers)
		var perm permanentError
		if err == nil || errors.As(err, &perm) || attempt >= DownloadAttempts || ctx.Err() != nil {
			if errors.As(err, &perm) {
				err = perm.error
			}
			return sum, n, err
		}
		select {
		case <-ctx.Done():
			return sum, n, ctx.Err()
		case <-time.After(wait):
		}
		wait *= 2
	}
}

func downloadOnce(ctx context.Context, client *http.Client, url, wantSHA, dst string, mode os.FileMode, headers map[string]string) (string, int64, error) {
	if !strings.HasPrefix(url, "https://") {
		return "", 0, permanentError{fmt.Errorf("refusing non-HTTPS download %s", url)}
	}
	if client == nil {
		client = http.DefaultClient
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return "", 0, permanentError{err}
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := client.Do(req)
	if err != nil {
		return "", 0, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		err := fmt.Errorf("GET %s: %s", url, resp.Status)
		if resp.StatusCode >= 500 || resp.StatusCode == http.StatusTooManyRequests {
			return "", 0, err
		}
		return "", 0, permanentError{err}
	}
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return "", 0, permanentError{err}
	}
	tmp, err := os.CreateTemp(filepath.Dir(dst), "."+filepath.Base(dst)+".dl-*")
	if err != nil {
		return "", 0, permanentError{err}
	}
	defer os.Remove(tmp.Name())
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(tmp, h), resp.Body)
	if err != nil {
		tmp.Close()
		return "", n, err
	}
	sum := hex.EncodeToString(h.Sum(nil))
	if wantSHA != "" && !strings.EqualFold(sum, wantSHA) {
		tmp.Close()
		return sum, n, permanentError{fmt.Errorf("sha256 mismatch for %s: got %s, want %s", url, sum, wantSHA)}
	}
	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()
		return "", n, err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return "", n, err
	}
	if err := tmp.Close(); err != nil {
		return "", n, err
	}
	return sum, n, os.Rename(tmp.Name(), dst)
}

// FileSHA256 hashes a real file.
func FileSHA256(p string) (string, error) {
	f, err := os.Open(p)
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
