# Integration notes

Cross-team decisions and open wiring, collected while modules were built in parallel.
Wave 3 (Builds, Deployments, Processes + public API) must honour these.

## Identifiers
- The control plane stores ULIDs **lowercase** (Laravel default). Agent schemas require **uppercase** Crockford ULIDs.
  **Rule:** convert to uppercase only at the agent boundary (payloads, `KILN_*` env injected into releases);
  normalise to lowercase on ingest (Fleet, Insights already do). Telemetry query filters use the uppercase form
  because agents label signals with what they were given.
  **Audited (wave 3, outside Builds/Deployments):** `telemetry.configure` ids, `KILN_SITE_ID`/`KILN_SERVER_ID` from
  `SiteDirectory::deployVariables()` and site commands (Deployments-supplied `KILN_*_ID` context values are upper-cased
  there too), Processes program/cron env — all upper-case. Ingest: Insights lower-cases `site_id`; Processes'
  `ScheduleDirectory` / `ProcessControl` accept either case. Fleet command and agent ids are minted upper-case
  (`Str::ulid()`) and stored that way, so envelopes/events/heartbeats round-trip unchanged.
  Deliberate lower-case labels (not ids to the agent, schema-mandated or opaque): Edge `route_id`
  (`^[a-z0-9-]+$`), firewall rule ids, Terminal `session_id` (never echoed back). Covered by `tests/Feature/WiringTest.php`.

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
- ✅ `Insights\Contracts\SiteNameResolver` and `Telemetry\Contracts\ServerSites` are bound by Sites
  (`EloquentSiteNameResolver`, `EloquentServerSites`); Telemetry re-sends `telemetry.configure` on
  `SiteCreated` / `SiteTargetsChanged` / `SiteDeleted`.
- ✅ Alertable events: Databases `BackupFailed` (+ `BackupSucceeded` recovery), `RestoreFinished`; Fleet `AgentRevoked`;
  Network `FirewallApplyFailed` (new, + `FirewallApplied` recovery); Edge `CertificateInstallFailed` (new, +
  `CertificateIssued` recovery); Processes `ProgramCrashLooping` / `ProgramRecovered`. Types are registered with
  `AlertTypes`. **Deployments still owes** `DeploymentFailed implements Alertable`.
- ✅ Servers detail page links to Telemetry server metrics and logs (for `telemetry.view`).
- ✅ Flash messages are shared as `flash` (`success`, `error`, `warning`, `status`) and rendered as toasts by the app
  layouts (`status` values that are machine codes like `verification-link-sent` are left to their pages).

## From Sites / Edge / SourceControl
- ✅ Sites' Laravel toggles (scheduler, Horizon, Octane) are converged by Processes into `proc.apply` / `cron.apply`.

## Processes (wave 3)
- Full desired `proc.apply` + `cron.apply` per server, compiled from every **ready** site target on it (container
  runtimes excluded) and dispatched by a debounced unique job; identical pending/applied state is never re-sent.
  Triggers: Processes changes, `SiteCreated/Updated/Deleted/TargetsChanged` and the new `Sites\Events\SiteTargetReady`.
- Names: `<slug>.horizon|octane|schedule`, `<slug>.worker-<id8>`, `<slug>.daemon-<id8>`, `<slug>.cron-<id8>` (unique per server).
- **Deployments:** call `Processes\Contracts\ProcessControl::restartForSite($siteId, $serverId)` after activation
  (`$KILN_RESTART_PROCS`). Horizon gets `horizon:terminate` (system.exec), other programs `proc.restart`; emits
  `ProcessesRestarted`. Only programs the agent confirmed are restarted.
- Heartbeats: cron jobs carry `site` (slug); the agent maps it to the upper-case site id from `telemetry.configure`.
  Insights consumes `Processes\Contracts\ScheduleDirectory` (site attribution, removed jobs are no longer expected)
  and `Processes\Events\SchedulesApplied` (monitors are seeded, so a job that never runs is detected).
- Crash loops: `proc.status` every `processes.status_poll_minutes` (default 5) on servers with programs; `fatal`, or
  `backoff` with ≥ `crash_loop_restarts` restarts, raises `ProgramCrashLooping`.
- `deploy.container.swap`: use `EdgeRoutes::routeId(siteId)` as `edge_route_id`, then `EdgeRoutes::recordUpstream(...)` with the result.
- Builders get clone URL + credentials from `SourceControlGateway`; pushes arrive as `SourceControl\Events\PushReceived`.
- `edge.caddy.apply` gained optional `basic_auth[].path` and `tls.dns` (Cloudflare DNS-01) — needs a Caddy/FrankenPHP build with the Cloudflare DNS module on servers.
- Site pages are extensible via `registerSiteTabs` (Deployments, Processes add tabs).

## Known limits (accepted for now)
- Octane runs on 127.0.0.1:`app_port` (or 8000 + crc32(site id) % 1000); Edge still serves PHP sites directly and does
  not proxy to Octane yet. Octane on php-fpm sites uses `--server=swoole` (needs the extension).
- Programs of a site that was never deployed crash-loop (no `current/`) and may alert until the first deploy.
- LB active health checks send the backend IP as Host, so backends whose routes are domain-only can be marked down; leave health path empty to balance without active checks. Weights are emulated by repeating upstreams.
- SourceControl: no Bitbucket Server; OAuth for only one self-hosted GitLab (others via token).
- Providers: AWS is Lightsail only (no EC2).
- Provision plan has no dedicated db/cache/docker sections → no Postgres version pinning.
- Deleting an org revokes agents but does not destroy provider machines.
- Alerting webhook SSRF guard doesn't resolve DNS (rebinding not blocked).
- Grafana dashboards are copied per org folder but not org-filtered inside Grafana.
- Laravel generated Dockerfile builds assets before composer (projects importing CSS from vendor/ need own Dockerfile).
