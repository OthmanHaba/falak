package builder

import "net/url"

// Control-plane endpoints used by `falak-builder serve`. All paths live here so they are easy to adjust
// while the Builds module API is being built. Authenticated with `Authorization: Bearer <builder token>`.
const (
	// PathNextBuild long-polls for the next build job: 200 + Job JSON, or 204 when none is queued.
	// Query: wait=<seconds>, builder=<name>, run=<run id of this process> (builds an earlier run claimed are
	// failed by the control plane: the process that ran them is gone).
	PathNextBuild = "/api/internal/builds/next"
	// PathBuildEvents receives batched NDJSON events (event.schema.json) for a build: POST.
	pathBuildEvents = "/api/internal/builds/%s/events"
	// pathBuildHeartbeat is POSTed every HeartbeatInterval while a build runs: 204, or 410 when the build is over on
	// the control plane (cancelled, reaped, timed out) and must be aborted.
	pathBuildHeartbeat = "/api/internal/builds/%s/heartbeat"
)

// HeartbeatPath returns the heartbeat path for a build.
func HeartbeatPath(buildID string) string {
	return sprintf(pathBuildHeartbeat, url.PathEscape(buildID))
}

// EventsPath returns the event ingestion path for a build.
func EventsPath(buildID string) string {
	return sprintf(pathBuildEvents, url.PathEscape(buildID))
}
