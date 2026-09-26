# Integration notes

Cross-team decisions and open wiring, collected while modules were built in parallel.
Wave 3 (Builds, Deployments, Processes + public API) must honour these.

## Identifiers
- The control plane stores ULIDs **lowercase** (Laravel default). Agent schemas require **uppercase** Crockford ULIDs.
  **Rule:** convert to uppercase only at the agent boundary (payloads, `KILN_*` env injected into releases);
  normalise to lowercase on ingest (Fleet, Insights already do). Telemetry query filters use the uppercase form
  because agents label signals with what they were given.

## Agent ↔ control plane
- `deploy.fetch|prepare|activate|rollback` accept optional `context` (`deployment_id`, `site_id`, …). Deployments
  **must** send it, or deployment lifecycle logs (Grafana "deployment failed" alert, Deployments dashboard) lose ids.
- `deploy.container.swap` rewrites Caddy upstreams on the host and returns `upstream` in its result. Deployments must
  call `Edge\Contracts\EdgeRoutes::recordUpstream(...)` with it, or the next `edge.caddy.apply` reverts the swap.
- `db.backup` uploads only to a local path or presigned URL (Databases presigns; no storage creds on servers).
- Terminal output events carry base64 PTY bytes in `data`.
- Cron heartbeats arrive at `/agent/v1/insights` as `kind: cron_heartbeat` (schema: `cron.apply` `$defs.heartbeat`).
- Agent restart (`KillMode=mixed`) restarts supervised programs.
- `telemetry.configure` is sent on provisioning completion (or ~30s after enroll), not synchronously at enroll.

## Public API expected by the Go tools
`agent/internal/cli/api/endpoints.go` and `agent/internal/builder/endpoints.go` define the paths the `kiln` CLI and
`kiln-builder` call. Existing: `/api/v1/me`, `/api/v1/servers`. Wave 3 implements the rest to match (or updates the
Go endpoint files in the same change): sites, deployments (+ output `?after=seq`), rollback, releases, env
(`{content}`), logs (`meta.cursor`), `GET /api/internal/builds/next` (200 job | 204), `POST /api/internal/builds/{id}/events` (NDJSON).

## Bindings to wire once providers exist
- `Insights\Contracts\SiteNameResolver` and `Telemetry\Contracts\ServerSites` have fallback defaults; Sites must bind real ones.
- Events that should alert (deploy failed, backup failed, …) implement `Alerting\Contracts\Alertable`.
- Servers detail page should link to the Telemetry server metrics page.
- Flash messages are not shared to Inertia yet (Telemetry settings success notice never shows).

## From Sites / Edge / SourceControl
- Sites' Laravel toggles (scheduler, Horizon, Octane) are **stored only** — Processes must turn them into `proc.apply` / `cron.apply`.
- `deploy.container.swap`: use `EdgeRoutes::routeId(siteId)` as `edge_route_id`, then `EdgeRoutes::recordUpstream(...)` with the result.
- Builders get clone URL + credentials from `SourceControlGateway`; pushes arrive as `SourceControl\Events\PushReceived`.
- `edge.caddy.apply` gained optional `basic_auth[].path` and `tls.dns` (Cloudflare DNS-01) — needs a Caddy/FrankenPHP build with the Cloudflare DNS module on servers.
- Site pages are extensible via `registerSiteTabs` (Deployments, Processes add tabs).

## Known limits (accepted for now)
- LB active health checks send the backend IP as Host, so backends whose routes are domain-only can be marked down; leave health path empty to balance without active checks. Weights are emulated by repeating upstreams.
- SourceControl: no Bitbucket Server; OAuth for only one self-hosted GitLab (others via token).
- Providers: AWS is Lightsail only (no EC2).
- Provision plan has no dedicated db/cache/docker sections → no Postgres version pinning.
- Deleting an org revokes agents but does not destroy provider machines.
- Alerting webhook SSRF guard doesn't resolve DNS (rebinding not blocked).
- Grafana dashboards are copied per org folder but not org-filtered inside Grafana.
- Laravel generated Dockerfile builds assets before composer (projects importing CSS from vendor/ need own Dockerfile).
