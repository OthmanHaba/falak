// Package version holds the build version, injected via -ldflags.
package version

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
