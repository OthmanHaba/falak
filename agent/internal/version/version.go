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
}

var (
	binaryOnce sync.Once
	binarySum  string
)

// BinarySHA256 is the SHA-256 of the running executable (computed once; "" when unreadable), reported in facts
// so the control plane can tell whether a server runs the agent build it ships.
func BinarySHA256() string {
	binaryOnce.Do(func() {
		exe, err := os.Executable()
		if err != nil {
			return
		}
		f, err := os.Open(exe)
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
