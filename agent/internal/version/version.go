// Package version holds the build version, injected via -ldflags.
package version

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"sync"
)

// Version is overridden at build time: -X github.com/kiln/agent/internal/version.Version=v1.2.3
var Version = "dev"

// Features are protocol additions this agent understands, reported in facts ("features"). Agents reject
// unknown payload fields, so the control plane only sends an optional field to agents that list it.
var Features = []string{
	// edge.caddy.apply sites[].access_log (per-site HTTP access logs, shipped as kind=access).
	"edge.access_log",
	// telemetry.configure log_sources[].kind and .multiline.
	"telemetry.log_kind",
	// system.upgrade_agent verifies sha256, swaps atomically and reports the running version.
	"system.upgrade_agent.v2",
	// fn.release.apply / fn.release.remove / fn.status (Cloud Functions + kiln-fn-gateway), and
	// edge.caddy.apply sites[].request_headers.
	"fn.v1",
	// fn.release.apply access (gateway API keys / IP allowlist) and edge.caddy.apply sites[].mounts.
	"fn.v2",
	// fn.release.apply with file trees (several files, folders): validated as a tree before anything is written.
	"fn.v3",
}

var (
	binaryOnce sync.Once
	binarySum  string
)

// BinarySHA256 is the SHA-256 of the running executable (computed once; "" when unreadable), reported in facts
// so the control plane can tell whether a server runs the agent build it ships. It hashes the running image
// (/proc/self/exe keeps pointing at it after system.upgrade_agent renamed a new build over the path), never the
// file now at the path: a process that swapped its binary still reports its own build until it restarts.
func BinarySHA256() string {
	binaryOnce.Do(func() {
		f, err := openRunningExecutable()
		if err != nil {
			return
		}
		defer f.Close()
		h := sha256.New()
		if _, err := io.Copy(h, f); err == nil {
			binarySum = hex.EncodeToString(h.Sum(nil))
		}
	})
	return binarySum
}

// openRunningExecutable opens the executable image of this process.
func openRunningExecutable() (*os.File, error) {
	if f, err := os.Open("/proc/self/exe"); err == nil {
		return f, nil
	}
	exe, err := os.Executable()
	if err != nil {
		return nil, err
	}
	return os.Open(exe)
}
