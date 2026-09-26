package system

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

// Download fetches url over HTTPS into dst (real path) atomically. When wantSHA is non-empty the
// SHA-256 must match, otherwise nothing is written. Returns the hex digest and byte count.
func Download(ctx context.Context, client *http.Client, url, wantSHA, dst string, mode os.FileMode, headers map[string]string) (string, int64, error) {
	if !strings.HasPrefix(url, "https://") {
		return "", 0, fmt.Errorf("refusing non-HTTPS download %s", url)
	}
	if client == nil {
		client = http.DefaultClient
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return "", 0, err
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
		return "", 0, fmt.Errorf("GET %s: %s", url, resp.Status)
	}
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return "", 0, err
	}
	tmp, err := os.CreateTemp(filepath.Dir(dst), "."+filepath.Base(dst)+".dl-*")
	if err != nil {
		return "", 0, err
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
		return sum, n, fmt.Errorf("sha256 mismatch for %s: got %s, want %s", url, sum, wantSHA)
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
