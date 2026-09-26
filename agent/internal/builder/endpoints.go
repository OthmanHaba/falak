package builder

import "net/url"

// Control-plane endpoints used by `kiln-builder serve`. All paths live here so they are easy to adjust
// while the Builds module API is being built. Authenticated with `Authorization: Bearer <builder token>`.
const (
	// PathNextBuild long-polls for the next build job: 200 + Job JSON, or 204 when none is queued.
	// Query: wait=<seconds>, builder=<name>.
	PathNextBuild = "/api/internal/builds/next"
	// PathBuildEvents receives batched NDJSON events (event.schema.json) for a build: POST.
	pathBuildEvents = "/api/internal/builds/%s/events"
)

// EventsPath returns the event ingestion path for a build.
func EventsPath(buildID string) string {
	return sprintf(pathBuildEvents, url.PathEscape(buildID))
}
