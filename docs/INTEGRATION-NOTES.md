# Integration notes

Cross-team decisions and open wiring, collected while modules were built in parallel.
All three build waves are merged; the sections below record the resulting contracts, fixes and limits.

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
✅ Implemented; see `docs/API.md`. Additions on the Go side: `HTTPSink.OnGone` — a `410` from the events endpoint
means the build was cancelled and `kiln-builder` aborts it.

## Builds / Deployments (wave 3)
- `Builds\Contracts\BuildService` (request / find / status / artifactFor / imageFor / cancel / output); events
  `BuildSucceeded`, `BuildFailed` (Alertable), `BuildCancelled`, `BuildOutputReceived`, `BuildUpdated`.
- Deployments events: `DeploymentStarted`, `DeploymentSucceeded`, `DeploymentFailed` (Alertable),
  `DeploymentRolledBack` (Alertable), `ReleaseActivated`. The release directory / `KILN_RELEASE_ID` is the release
  ULID upper-cased; `.env` of every release gets `KILN_SITE_ID`, `KILN_SERVER_ID`, `KILN_DEPLOYMENT_ID`, `KILN_RELEASE_ID`.
- Sites gained `SiteResourceExtension` (tagged; Deployments adds `strategy` + `current_release` to the site API)
  and `SiteDeploySettings` (push-to-deploy toggle from the Deploy settings tab), plus `GET|PUT /api/v1/sites/{site}/env`.
- Restart phase uses `ProcessControl::restartForSite()`; a restart step completes when all returned commands finish.
- Not supported yet (deployments fail fast with a clear error): **on-server** builds. (Compose: see below.)
- Registry images are not garbage-collected by Kiln (run the registry's GC); artifacts are pruned per site.

## Bindings to wire once providers exist
- ✅ `Insights\Contracts\SiteNameResolver` and `Telemetry\Contracts\ServerSites` are bound by Sites
  (`EloquentSiteNameResolver`, `EloquentServerSites`); Telemetry re-sends `telemetry.configure` on
  `SiteCreated` / `SiteTargetsChanged` / `SiteDeleted`.
- ✅ Alertable events: Databases `BackupFailed` (+ `BackupSucceeded` recovery), `RestoreFinished`; Fleet `AgentRevoked`;
  Network `FirewallApplyFailed` (new, + `FirewallApplied` recovery); Edge `CertificateInstallFailed` (new, +
  `CertificateIssued` recovery); Processes `ProgramCrashLooping` / `ProgramRecovered`. Types are registered with
  `AlertTypes`. Deployments `DeploymentFailed` / `DeploymentRolledBack` and Builds `BuildFailed` implement `Alertable`
  (types `deployments.failed`, `deployments.rolled_back`, `builds.failed`).
- ✅ Servers detail page links to Telemetry server metrics and logs (for `telemetry.view`).
- ✅ Flash messages are shared as `flash` (`success`, `error`, `warning`, `status`) and rendered as toasts by the app
  layouts (`status` values that are machine codes like `verification-link-sent` are left to their pages).

## From Sites / Edge / SourceControl
- ✅ Sites' Laravel toggles (scheduler, Horizon, Octane) are converged by Processes into `proc.apply` / `cron.apply`.

## Processes (wave 3)
- Full desired `proc.apply` + `cron.apply` per server, compiled from every **ready** site target on it that has a
  **live release** there (container runtimes excluded; see *Deploy gaps* below) and dispatched by a debounced unique
  job; identical pending/applied state is never re-sent.
  Triggers: Processes changes, `SiteCreated/Updated/Deleted/TargetsChanged` and the new `Sites\Events\SiteTargetReady`.
- Names: `<slug>.horizon|octane|schedule`, `<slug>.worker-<id8>`, `<slug>.daemon-<id8>`, `<slug>.cron-<id8>` (unique per server).
- **Deployments:** call `Processes\Contracts\ProcessControl::restartForSite($siteId, $serverId)` after activation
  (`$KILN_RESTART_PROCS`). It converges the server first (the new release's env changes every program of the site,
  so that `proc.apply` restarts them — and starts them on the first deploy); running programs it left unchanged get
  `horizon:terminate` (Horizon, system.exec) or `proc.restart`; emits `ProcessesRestarted`.
- Heartbeats: cron jobs carry `site` (slug); the agent maps it to the upper-case site id from `telemetry.configure`.
  Insights consumes `Processes\Contracts\ScheduleDirectory` (site attribution, removed jobs are no longer expected)
  and `Processes\Events\SchedulesApplied` (monitors are seeded, so a job that never runs is detected).
- Crash loops: `proc.status` every `processes.status_poll_minutes` (default 5) on servers with programs; `fatal`, or
  `backoff` with ≥ `crash_loop_restarts` restarts, raises `ProgramCrashLooping`.
- `deploy.container.swap`: use `EdgeRoutes::routeId(siteId)` as `edge_route_id`, then `EdgeRoutes::recordUpstream(...)` with the result.
- Builders get clone URL + credentials from `SourceControlGateway`; pushes arrive as `SourceControl\Events\PushReceived`.
- `edge.caddy.apply` gained optional `basic_auth[].path` and `tls.dns` (Cloudflare DNS-01) — needs a Caddy/FrankenPHP build with the Cloudflare DNS module on servers.
- Site pages are extensible via `registerSiteTabs` (Deployments, Processes add tabs).

## Projects (UI redesign backend)
- Module `Projects` (after Databases in `Kernel\Modules::ALL`): `projects_projects`, `projects_environments`,
  `projects_services` (`kind` site|database, `ref_id` unique per kind, `name` unique per environment, `x`/`y`).
  Contracts `ProjectDirectory` (lookups, `projectOf`, `servicesIn`, `serviceUrl(kind, refId, tab)` →
  `/projects/{p}/{env}/service/{kind}/{id}/{tab}` for the upcoming legacy redirects) and `VariableReferences`;
  events `ProjectCreated`, `EnvironmentCreated`, `ServiceLinked`, `ServiceUnlinked`.
- Data migration `backfill_default_projects` (= `php artisan projects:backfill [--organization=]`, idempotent) gives
  every organization a `Default` project / `production` environment and places every site and database in it.
  `OrganizationCreated` creates it for new organizations.
- Placement: `Sites\Events\SiteCreated` carries an optional `SitePlacement` (project, environment, x, y, service
  name) from `SiteFactory::create` / `POST /sites` (`project_id`, `environment_id`); Projects places the site
  synchronously. Databases are placed on `DatabaseCreated` (i.e. once `db.create` converged) unless the canvas already
  placed them via `Databases\Contracts\DatabaseProvisioner`. `SiteDeleted` / `DatabaseDeleted` unlink.
- New contracts used by Projects: `Sites\Contracts\SiteFactory` (create / duplicate), `Databases\Contracts\
  DatabaseConnections` (reference variables; the only place passwords leave Databases) and `DatabaseProvisioner`,
  `Deployments\Contracts\DeploymentDirectory::currentForSites`, `Identity\Contracts\OrganizationDirectory::all`,
  `DatabaseDirectory::findMany/forOrganization`.
- `Deployments\StepPayloads` resolves references for `deploy.prepare` env files, container env and deploy-script env
  (unresolved → the deployment fails with the reason); `Builds\BuildConfiguration` resolves public build variables.
- Shared Inertia props: `Kernel\Support\SharedProps` registry merged by `HandleInertiaRequests`; Projects registers
  `kiln` (UI_DESIGN §9). Pages `Projects/Index`, `Projects/Canvas`, `Projects/Settings` are rendered with their props;
  the TSX pages come with the UI wave.
- Limits: Redis is not a Databases engine yet (`422` "Redis services are not supported yet"); duplicated environments
  get sites without servers (pick servers per service); canvas status has no "crashed" state yet (no process
  health contract).

## Docker Compose sites (roadmap step 4, lane A — docs/COMPOSE_TEMPLATES.md §1)
- Contracts (Sites): `SiteFactory::create` accepts `compose_source|compose_content|compose_file|public_services|variables|
  template` (§5); `ComposeInspector::parse()` → `ComposeSummary` (services, ports, volumes, policy violations, errors,
  warnings; never throws); `ComposeSites` (inline versions, `render()` for a release, `pinDigests()`, service state for
  the Services tab / canvas). `SiteData::$compose` (`ComposeConfig`: source, file, public services with host ports and
  test domains, template, inline version).
- Rendering: `build:` services → built image (digest), `ports` removed everywhere and `127.0.0.1:<host port>:<port>` on
  public services, labels `kiln.site|release|service`; `${VAR}` stays Compose-native — the release `.env` (site variables
  with references resolved + `KILN_SITE_ID|SERVER_ID|DEPLOYMENT_ID|RELEASE_ID`) is written next to `compose.yaml` and
  also passed as the compose process env. Policy toggle: org setting `sites_organization_settings.allow_privileged_compose`
  (Settings → Compose, permission `sites.compose.policy`, admins).
- Builds: compose sites build in docker mode with a `compose` job spec; kiln-builder builds every `build:` service to
  `<registry>/<ns>/<slug>/<service>:<build id>` and returns the unmodified compose file + pinned images
  (`BuildService::composeFor`). Repo compose sites therefore need a docker-capable builder even without `build:` services.
- Deployments (`compose` strategy; rolling/canary batch the activations): `docker.compose.pull` (FETCH, writes
  `releases/<ID>/compose.yaml` + `.env`) → leader `system.exec` running `docker compose run --rm <svc> <argv>` for every
  `kiln.deploy.leader_command` label (settles instantly when there is none) → `docker.compose.up --wait` → health check of
  every public service through the edge (primary: configured path/status; others: `/` and `< 500`). The rendered files are
  stored encrypted on the release (`deployments_releases.compose`) and pulled images are re-pinned to the digests the
  server resolved; rollback (failure or manual) = `up --wait` with that release's files. Project name = site slug, so
  named volumes survive releases. `deploy.prune` keeps N release directories like native sites.
- Edge: the primary public service is the site route (site domains + `<slug>` test domain → app port = its host port);
  each other public service gets `<route>-svc-<service>[-test]` routes for its custom domain / `<service>-<slug>` test domain.
- Telemetry: compose containers log with `service.name=<slug>` and `kiln.compose.service` (Loki structured metadata
  `kiln_compose_service`, filter `compose_service` on the logs endpoints); `docker stats` → `kiln.container.cpu.utilization`,
  `kiln.container.memory.usage|limit`, `kiln.container.network.io` per site resource.
- Limits: only `compose.yaml` + `.env` reach the servers — repository files referenced by relative bind mounts or extra
  `env_file`s are not shipped; `include`/`extends` are rejected; a failed first deploy has nothing to roll back to
  (containers stay as `up` left them); kiln-builder with registry credentials looks up the buildx builder in its
  temporary DOCKER_CONFIG (the sim registry has no auth).

## Deploy gaps (roadmap step 6)
- **Deploying while servers prepare.** `DeploymentQueue` claims a queued deployment as `waiting` (new status; holds the
  site's queue like a running one) when any site target is `pending`/`provisioning`, with `waiting_reason` /
  `waiting_since`. It is re-evaluated on `Sites\Events\SiteTargetReady`, the new `SiteTargetFailed` (dispatched by
  `TargetProvisioner::fail`), `SiteTargetsChanged` (queued listener `ResumeWaitingDeployments`) and by the minute
  reconciler. Decisions: it waits for **every** preparing server (rather than deploying the ready ones and leaving late
  servers without a release); servers whose preparation failed are skipped with a warning (in the output) while
  another server is ready; a failed **leader** or no preparable server fails it with the reason (`DeploymentFailed`,
  so it alerts); `deployments.waiting.timeout_minutes` (`KILN_DEPLOY_WAIT_TIMEOUT_MINUTES`, default 30) fails it
  too. Non-rollback triggers (panel, API/CLI, push, deploy hook, `DeploymentTrigger`) coalesce into the site's
  waiting deployment — latest branch/commit/trigger wins, same number; rollbacks queue behind it. Cancel works on it
  (panel, and the new `POST /api/v1/deployments/{id}/cancel`). `SiteTargetData` gained `statusMessage`.
  Canvas: a waiting deployment reads *Waiting for servers* (tone `queued`); `DeploymentDirectory::currentForSites`
  returns it as the current deployment.
- **Release ids + site variables in the process env.** Deployments records the release each server runs
  (`deployments_server_releases`, written on activate/switch/swap/compose up/revert; backfilled from active
  releases) and the resolved variables each release's `.env` was written with (`deployments_releases.environment`,
  encrypted; set at the first `deploy.prepare`). Contract `Deployments\Contracts\LiveReleases::onServer()`. Processes
  builds every program and cron job env as: release variables → program env (worker/daemon env; `<slug>.app`'s
  `PORT`/`HOST`/`PATH`, `NODE_ENV` defaulting to `production` unless the site sets it) → `KILN_SITE`,
  `KILN_SITE_ID`, `KILN_SERVER_ID`, `KILN_RELEASE_ID`, `KILN_DEPLOYMENT_ID` (upper-case; the deployment that built
  the release, like its `.env`, also after a rollback). Env edits still take effect on the next deploy (the release
  snapshot, not the latest version). The agent's supervisor already restarts a program whose definition (env
  included) changed; Deployments now settles restart steps on `proc.apply` outcomes and converges its servers when a
  deployment finishes (static sites have no restart step). PHP sites get the same env; Laravel's dotenv doesn't
  override existing env vars and the values are identical.
- **No programs before the first deploy.** Processes skips a site on a server until it has a live release there
  (programs, Horizon, Octane, scheduler, cron), so never-deployed sites no longer crash-loop or alert; they start on
  the first activation (the restart step's `proc.apply`). Consequence: a site's daemons/cron only run once it has
  been deployed, also on a server newly added to a deployed site (until the next deploy).
- **CLI:** `kiln deploy` prints the waiting reason; `api.Deployment.WaitingReason`.
- Still open from *Known limits* (not small or not safe to change here): a failed **first** compose deploy leaves containers as `up` left them (stopping them would also discard their logs
  for debugging); on-server builds; registry GC; the compose file-shipping limits.

## Octane routing (roadmap step 5)
- **Port + server (Sites).** `LaravelSettings` gained `octaneServer` (`Sites\Contracts\OctaneServer`: `frankenphp` — FrankenPHP
  runtime only, its default — `swoole` (PHP-FPM default) or `roadrunner`) and `octanePort`, persisted in the site's
  `laravel` JSON when Octane is enabled (`Sites\Application\OctanePorts`): first candidate `8000 + crc32(site id) % 1000`
  (`sites.octane_port_base|span`), then the next free port. Free = not another site's app port, compose host port, Octane
  port or Octane aux port (port + 10000: FrankenPHP's `--admin-port` — the edge owns :2019 — or RoadRunner's `--rpc-port`)
  on **any** server of the site, and its own aux port free too. Re-checked when servers are added (`SetSiteTargets`),
  on duplication, and by the backfill migration (legacy `app_port`/crc32 ports kept when free). The port is never taken
  from a request; it survives switching Octane off. Edge and Processes both read it from `SiteData::$laravel`.
- **Processes** supervises `<slug>.octane` = `php artisan octane:start --server=<s> --host=127.0.0.1 --port=<p>`
  (+ `--admin-port`/`--rpc-port`) and owns the routing state `processes_octane_routes` (per site × server:
  `starting | listening | failed | draining`), exposed as `Processes\Contracts\OctaneRouting::listeningPort()` and
  `Processes\Events\OctaneRoutingChanged`. A probe (`system.exec`, waits ≤ `processes.octane_probe_seconds` for any HTTP
  answer on 127.0.0.1:<port>) runs after a `proc.apply` / `proc.restart` that involves the program and on every status
  poll for unverified routes; only a verified route is proxied. Programs only exist once a release is live (deploy
  gaps), so never-deployed sites are never probed.
- **Edge** renders a verified Octane site (direct and LB-backend roles, domains + test domain) as `kind: reverse_proxy`
  with `root` = document root and `try_duration_s: 30`: the agent serves existing files under `root` with `file_server`
  (never `*.php`, dotfiles or directories) and proxies the rest, retrying the upstream for 30s. TLS, redirects, headers,
  basic auth, IP rules and body limits are unchanged. Anything else (starting, failed, placeholder release, port moved)
  keeps the FrankenPHP `php_server` / PHP-FPM route. `edge_server_states.octane_sites|applied_octane_sites` record which
  sites the dispatched / applied config proxies (`EdgeRoutes::proxiesToOctane()`).
- **Ordering.** Enable: program converged → probe answers → `OctaneRoutingChanged` → edge switches to the proxy. Disable:
  a verified route turns `draining` (edge serves directly again; the program is kept in `proc.apply`) until neither the
  applied nor a pending edge config proxies to it (`StopDrainedOctane` on `EdgeApplied`, the status poll, or a 10 min
  grace), then it is dropped and the program stops. Port/server change: back to `starting` (served directly until the
  new process answers).
- **Deploys restart, UI restarts reload.** Octane resolves `current` once at start (PHP resolves `__DIR__`/`base_path()`
  through the symlink), so `octane:reload` after a release swap would re-boot the workers on the **old** release. After an
  activation the program is restarted — its definition carries the new `KILN_RELEASE_ID`, so the restart step's
  `proc.apply` does it — while the edge holds requests (`try_duration`) until the new server listens: no failed requests,
  a short latency spike. `ProcessControl::restartForSite(..., newRelease: false)` (Restart processes in the UI, same
  release) sends `php artisan octane:reload --server=<s>` to a verified Octane instead (graceful worker reload behind the
  open port); a failed reload falls back to `proc.restart`.
- API: `PUT /api/v1/sites/{site}/laravel` (docs/API.md). UI: Settings → Laravel (server select, port) + a Processes
  block with per-server routing state; canvas cards / panel header carry an **Octane** badge (`CanvasService.badges`).
- Sim E2E stage `octane` (fixture `laravel-demo` now requires `laravel/octane`, ships `public/frankenphp-worker.php` and a
  `/octane` route whose per-worker counter proves worker mode): placeholder kept before the first deploy, worker mode
  through the edge, static passthrough, zero failed requests during a redeploy and while switching Octane off.

## Found by the sim E2E (all fixed, with regression tests)
Real provisioning and deploys on Ubuntu 24.04 (`sim/e2e-deploy.sh`) surfaced these; each is fixed and covered:
1. A failed live broadcast (Reverb down) aborted provisioning/deployments → all `ShouldBroadcastNow` events are
   `ShouldRescue`; an architecture test enforces it.
2. `sshd -t` failed on fresh 24.04 (`/run/sshd` missing) and on hosts without host keys → created / `ssh-keygen -A`.
3. `systemctl reload ssh` failed when sshd runs on demand; and after an openssh upgrade **nothing listened on :22** →
   the SSH step now converges "SSH reachable" every run (socket vs service aware).
4. hostname / swap steps fail inside containers → skipped only when `systemd-detect-virt --container`.
5. Transient download failures (DNS, truncated bodies, 5xx) failed provisioning → retried with backoff.
6. FrankenPHP rejects a config whose PHP root doesn't exist, so a never-deployed site took down **every route on the
   server** → `current` points at a 503 placeholder release until the first deploy.
7. FrankenPHP resolves the `current` symlink at config load, so a release swap kept serving the **old** release →
   activation force-reloads the running config.
8. Health checks verified internal-CA certificates → only publicly trusted (ACME/DNS-01) certificates are verified.
9. PHP under FrankenPHP (edge user) couldn't read the site `.env` or write `storage/` → edge joins site groups
   (`SupplementaryGroups=`), writable dirs are recursively group-writable (setgid), confined to the site root.
10. Node/Bun/Deno sites were never started and servers had no Bun/Deno → `runtime.bun|deno.install` on target
    preparation; Processes supervises `<slug>.app` (`npm|bun run start` / `deno task start`) on `app_port`.
Also added for automation: `POST /api/v1/sites`, `POST|GET /api/v1/source-control/connections`, `kiln:admin`.

## Not covered by the E2E yet (unit/feature tested only)
Docker/Compose runtimes and docker builds on a real BuildKit, database backups/restore to real S3, WireGuard private
networks, web terminal, recipes, provider APIs (Hetzner/DO/Vultr/Linode/Lightsail), load balancers, DNS-01 wildcard
certificates, alert delivery to real Slack/Discord/Telegram, the Grafana provisioning API, Deno runtime at runtime.

## Known limits (accepted for now)
- Octane: Swoole needs the `swoole`/`openswoole` extension and RoadRunner the `rr` binary + `spiral/roadrunner-http` in
  the app (Kiln installs neither; the probe keeps the site served directly until they work). Octane's FrankenPHP
  server binds `:<port>` on all interfaces (Octane gives no bind option; the nftables default policy drops it) and
  uses the FrankenPHP binary's embedded PHP, not the site's `phpX.Y` CLI. A verified Octane that crashes later is not
  un-routed automatically (502 after the 30s retry window; the crash-loop alert fires) — the next restart/poll re-probes
  only unverified routes.
- LB active health checks send the backend IP as Host, so backends whose routes are domain-only can be marked down; leave health path empty to balance without active checks. Weights are emulated by repeating upstreams.
- SourceControl: no Bitbucket Server; OAuth for only one self-hosted GitLab (others via token).
- Providers: AWS is Lightsail only (no EC2).
- Provision plan has no dedicated db/cache/docker sections → no Postgres version pinning.
- Deleting an org revokes agents but does not destroy provider machines.
- Alerting webhook SSRF guard doesn't resolve DNS (rebinding not blocked).
- Grafana dashboards are copied per org folder but not org-filtered inside Grafana.
- Laravel generated Dockerfile builds assets before composer (projects importing CSS from vendor/ need own Dockerfile).
