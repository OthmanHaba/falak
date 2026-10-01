# Kiln HTTP API

Three surfaces:

| Surface | Base | Auth | Consumers |
|---|---|---|---|
| Public REST API v1 | `/api/v1` | Sanctum bearer token (`Authorization: Bearer <token>`) | `kiln` CLI (`agent/internal/cli/api`), CI, scripts |
| Deploy hooks | `/api/deploy/{token}` | the unguessable token in the URL | CI / chat ops |
| Internal builder API | `/api/internal` | builder token (`Authorization: Bearer kbt_…`) or signed URLs | `kiln-builder serve` (`agent/internal/builder`) |

The agent protocol (`/agent/v1`, mTLS) is documented in `contracts/agent-protocol/README.md`.

## Conventions (v1)

- Always send `Accept: application/json`.
- **Tokens** are created under *Settings → API tokens*. A token is pinned to **one organization**; its
  abilities are permission names (`deployments.create`, …) or `*`. A request is allowed only when the token
  has the ability **and** the token owner's role grants the permission.
- **Ids** are lowercase ULIDs. Anything that accepts an id also accepts it uppercase. Sites can be addressed
  by **id or slug** everywhere (`{site}`).
- **Responses** wrap payloads in `{"data": …}`; paginated lists add `links` and `meta`
  (`current_page`, `per_page`, `total`, `last_page`; `?page=`, `?per_page=` ≤ 100).
- **Errors**: `401` missing/invalid token, `403` `{message}` (ability or role missing), `404` `{message}`
  (not found *or* in another organization), `422` Laravel validation body
  `{"message": "…", "errors": {"field": ["…"]}}`, `429` rate limited, `503` backend (Loki) unavailable.
- Timestamps are ISO-8601.

### Permissions (token abilities)

| Permission | Roles | Grants |
|---|---|---|
| `sites.view` | admin, developer, viewer | list/show sites |
| `sites.env.view` / `sites.env.manage` | admin, developer | read / replace the site environment |
| `deployments.view` | admin, developer, viewer | deployments, output, releases |
| `deployments.create` | admin, developer | deploy, cancel queued/waiting/building deployments |
| `deployments.rollback` | admin, developer | roll back to an earlier release |
| `deployments.manage` | admin, developer | strategy, health checks, retention, push-to-deploy, deploy hooks (UI) |
| `builds.view` / `builds.manage` | view: all; manage: admin, developer | builds, logs, builders / cancel builds, manage builders (UI) |
| `telemetry.view` | admin, developer, viewer | site logs, access logs |
| `servers.view` | admin, developer, viewer | list/show servers (incl. agent version) |
| `fleet.agents.manage` | admin | upgrade server agents |
| `projects.view` / `projects.manage` | view: all; manage: admin, developer | projects + environments / create, rename, delete, duplicate environments |
| `functions.view` | admin, developer, viewer | functions, their code, versions, schedules and runs |
| `functions.deploy` | admin, developer | deploy function code and versions (also needs `deployments.create`), run schedules |

## Identity

### `GET /api/v1/me`
```json
{"data": {"user": {"id": "…", "name": "Ada", "email": "ada@example.com"},
          "organization": {"id": "…", "name": "Acme", "slug": "acme", "role": "owner"},
          "token": {"name": "cli", "abilities": ["*"]}}}
```

### `GET /api/v1/organizations`
Token requests return the token's organization only; session requests every membership.
```json
{"data": [{"id": "…", "name": "Acme", "slug": "acme", "role": "owner", "current": true}]}
```

## Servers

### `GET /api/v1/servers` · `GET /api/v1/servers/{server}` — `servers.view`
`agent` (null until an agent enrolled) carries `status`, `last_heartbeat_at`, `version`, `available_version` (the
build this control plane ships), `update_available` and `upgrade` (the latest upgrade: `status`
`queued|running|succeeded|failed|cancelled`, `from_version`, `to_version`, `error`, `requested_at`, `finished_at`).

### `POST /api/v1/servers/{server}/agent/upgrade` — `fleet.agents.manage`
Upgrades the server's agent to the shipped build (`system.upgrade_agent` with the panel download URL and its
SHA-256). `202` with the upgrade (`status: running`; the same upgrade when one is already in progress); it succeeds
once the restarted agent reports the new build, fails on a download/checksum/pre-flight error or after
`KILN_AGENT_UPGRADE_TIMEOUT`. `409` with `message` when it cannot run: no agent, agent offline, no verifiable build
for the server's architecture, or the agent already runs it.
```json
{"data": {"id": "01k…", "server_id": "01k…", "status": "running", "from_version": "v0.3.0", "to_version": "v0.4.0",
          "rollout_id": null, "error": null, "requested_at": "2026-09-28T10:00:00+00:00", "finished_at": null}}
```

## Sites

### `GET /api/v1/sites` · `GET /api/v1/sites/{site}` — `sites.view`
```json
{"data": {
  "id": "01k…", "name": "Shop", "slug": "shop", "status": "ready",
  "framework": "laravel", "runtime": "frankenphp", "build_mode": "native",
  "php_version": "8.4", "node_version": null,
  "repository": "acme/shop", "branch": "main", "push_to_deploy": true,
  "domain": "shop.example.com", "url": "https://shop.example.com",
  "web_directory": "public", "root_path": "/srv/kiln/sites/shop", "app_port": null, "container_port": null, "test_domain": null,
  "server_ids": ["01k…"],
  "targets": [{"id": "…", "server_id": "…", "server_name": "web-1", "server_ip": "203.0.113.1", "role": "leader", "status": "ready", "status_message": null, "command_id": null}],
  "strategy": "zero-downtime",
  "current_release": {"id": "01k…", "commit": "a1b2…", "branch": "main", "deployment_id": "01k…", "active": true, "…": "see Release"},
  "created_at": "2026-09-26T10:00:00+00:00"
}}
```
`show` also returns `deploy_script`, `shared_paths`, `laravel`. `strategy` / `current_release` are contributed by
Deployments through `Sites\Contracts\SiteResourceExtension`.

### `POST /api/v1/sites` — `sites.create`
Same body and validation as the web form (`name`, `framework`, `server_ids[]`, optional `leader_server_id`, `runtime`,
`build_mode`, `source_connection_id` + `repository` + `branch`, `push_to_deploy`, `php_version`, `web_directory`,
`app_port`, `container_port`, `health_check_path`, …). `201` with the site resource plus `warnings[]` from the git provider;
`422` on errors. Docker sites take `container_port` (the port the app listens on inside its container, default 3000, may
repeat across sites; an `app_port` sent for a docker site is read as it); their `app_port` is the loopback host port Kiln
allocates. Changing a docker site's `container_port` (`PATCH /sites/{id}`) redeploys it. `DELETE /sites/{id}` stops the
site's containers (compose: `docker compose down`; `delete_volumes: true` also removes named volumes).
Optional `project_id` / `environment_id` place the site (Projects); without them it lands in the organization's
Default project, `production` environment. An environment of another organization/project is a `422`.

Optional `domain` (not for compose sites) — a [domain choice](#domains-and-dns): `{"type": "generated"}` routes
`<slug>.<leader-ip-with-dashes>.sslip.io`, `{"type": "custom", "name": "shop.example.com"}` (or just the name as a
string) routes your domain, `{"type": "test"}` keeps only the test domain (`422` when none is configured). Both are added
as the site's primary domain with automatic TLS, and `APP_URL` in the initial environment uses it. Without `domain` the
site only gets its test domain (as before). A name used by another site is a `422`.

Docker Compose sites (`runtime: compose`, `framework` optional — defaults to `docker`; docs/COMPOSE_TEMPLATES.md §5):
`compose_source` `repo` (`compose_file`, default `compose.yaml` then `docker-compose.yml`, built by kiln-builder) or
`inline` (`compose_content`, versioned; no `build:`), `public_services` `[{service, port, domain?}]` (Kiln allocates a
loopback host port per service; `domain` is a name or a domain choice — generated names are
`<service>-<slug>.<ip-with-dashes>.<suffix>`, `null` / `{"type": "test"}` means the test domain), `variables` `{KEY: value}` (initial environment; `${{ service.KEY }}` allowed) and
`template` `{slug, version, source: catalog|custom}`. Inline files must pass the compose policy (`422` otherwise) unless
the organization allows privileged compose. The site resource then carries `compose {source, file, version,
public_services[] (with host_port, test_domain, url), template}`.

### `GET /api/v1/sites/{site}/env` — `sites.env.view`
Returns the latest environment version as dotenv (audited as a reveal).
```json
{"data": {"content": "APP_ENV=production\nAPP_KEY=base64:…\n", "version": 3}}
```

### `PUT /api/v1/sites/{site}/env` — `sites.env.manage`
Body `{"content": "<dotenv>"}` replaces all variables (deploy-script exposure of existing keys is kept).
`422` on unparsable content (`errors.content`). Takes effect on the next deployment.
```json
{"data": {"version": 4, "changed": true, "keys": ["APP_ENV", "APP_KEY"]}}
```

### `PUT /api/v1/sites/{site}/laravel` — `sites.manage`
Laravel toggles, each optional (unchanged when omitted): `scheduler`, `horizon`, `octane`, `maintenance`, and
`octane_server` (`frankenphp` — FrankenPHP runtime only, the default there — `swoole` (default on PHP-FPM) or
`roadrunner`). The Octane port is allocated by Kiln (unique on every server of the site, persisted) and returned; it
cannot be set. `422` for a Laravel toggle on a non-Laravel site, an unavailable server, or no free port.
```json
{"data": {"scheduler": true, "horizon": false, "octane": true, "maintenance": false, "octane_server": "frankenphp", "octane_port": 8412}}
```

### `GET /api/v1/sites/{site}/logs` — `telemetry.view`
Query: `since` (seconds, default 3600, ≤ 30 days), `limit` (1–1000, default 100), `level`
(`trace|debug|info|warn|error|fatal`), `kind` (`app` — the site's log files, programs, cron, containers — or
`access` — edge requests; default both), `cursor` (from the previous page). Newest first; `meta.cursor` is empty
on the last page.
```json
{"data": [{"at": "2026-09-26T10:00:02.000000+00:00", "level": "ERROR", "source": "laravel", "server": "web-1",
           "message": "boom", "attributes": {"service_name": "laravel", "…": "…"}}],
 "meta": {"cursor": "1790000000000000001"}}
```

### `GET /api/v1/sites/{site}/access-logs` — `telemetry.view`
The site's edge HTTP access log ("Network Logs"): one entry per request served for the site, by its servers or by
the load balancer in front of them. Query: `since`, `limit`, `cursor` as above; filters `server` (server id),
`deployment` (requests served while that deployment's release was live), `method`, `status` (`404` or a class
`5xx`), `path` (substring of the request URI), `client_ip`. `503` when Loki is not configured.
```json
{"data": [{"ts": "1790000000000000002", "at": "2026-09-28T10:00:02.000000+00:00", "method": "GET", "path": "/cart",
           "query": "x=1", "status": 502, "duration_ms": 12.3, "bytes": 512, "request_bytes": 0,
           "client_ip": "203.0.113.9", "user_agent": "curl/8.5", "host": "shop.example.com",
           "server_id": "01k…", "deployment_id": "01k…", "release_id": "01k…"}],
 "meta": {"cursor": "1790000000000000002"}}
```

## Domains and DNS

A **domain choice** is `{"type": "generated" | "test" | "custom", "name"?: string}` (a plain string is a custom
domain). It is accepted by `POST /api/v1/sites` (`domain`), compose `public_services[].domain` and template deploys
(`domains.<service>`; a service without a choice gets the organization default: the test domain when
`KILN_TEST_DOMAIN` is set, else a generated name, else a domain is required).

- **generated** — `<label>.<ipv4-with-dashes>.<suffix>`, e.g. `minio-files.63-182-218-247.sslip.io`. The label is the
  site slug (compose: `<service>-<slug>`); the IP is the leader server's public IPv4 (a site behind a load balancer: the
  `lb` server's). Wildcard DNS services (`sslip.io` by default, `nip.io`, or a self-hosted one via
  `KILN_GENERATED_DOMAIN_SUFFIX`; per organization in Settings → Domains) resolve it to that IP, so it works without DNS
  setup and Let's Encrypt issues its certificate over HTTP-01. `422` when generated names are off or the server has no
  public IPv4 yet. One name per endpoint: sites on several servers without a load balancer are reached on the leader.
- **test** — `<slug>.<KILN_TEST_DOMAIN>` (compose: `<service>-<slug>.…` after the first service).
- **custom** — your domain, routed with automatic TLS once DNS points at the server (see the check below).

### `GET /api/v1/domains/options?server=<id>[,<id>…]` · `?site=<site>` — `edge.view`
What a create form offers: `{test_domain, generated: {suffix, ipv4, target, available, reason}, default, targets[]}`
(`targets`: `{server_id, name, ipv4, ipv6, load_balancer}` — where DNS must point: the site's load balancer, else each
server, leader first). `server` may repeat (`server[]=`) or be comma-separated; `site` uses the site's servers.

### `GET /api/v1/dns/check?name=<domain>&server=<id>…` · `&site=<site>` — `edge.view` (60/min)
Resolves `name` from the control plane (DNS-over-HTTPS, `KILN_DNS_RESOLVER=doh|system`, 3 s timeout) and compares it
with the targets:

```json
{"data": {
  "name": "shop.example.com", "status": "ok", "message": "Points to app-2 (63.182.218.247)",
  "addresses": ["63.182.218.247"], "cnames": [], "targets": [...], "matched": [...],
  "instructions": {"zone": "example.com", "host": "shop", "apex": false, "ttl": 300,
    "records": [{"type": "A", "name": "shop.example.com", "host": "shop", "value": "63.182.218.247", "target": "app-2"}],
    "alternative": {"type": "CNAME", "host": "shop", "value": "shop.63-182-218-247.sslip.io"}, "notes": ["…"]},
  "certificate": null, "checked_at": "2026-09-28T12:00:00+00:00"}}
```

`status`: `ok` (every address is a target), `mismatch` ("Resolves to 1.2.3.4 — expected …", or extra records to
remove), `proxied` (Cloudflare proxy addresses: HTTP-01 fails until the record is "DNS only"), `missing` (no A/AAAA
yet), `error` (lookup failed, invalid name, or no server IP to compare with). `instructions` lists the records to add
(A per target IPv4, AAAA per IPv6; apex vs subdomain; a CNAME to the generated name as an alternative for subdomains of
single-target sites). With `site` and `tls=1`, `certificate` reports what the site's server serves for the name:
`{status: issued|pending, issuer, expires_at, message}` (probed only when the name points at the site).

## Projects

Projects → environments → services (sites / databases). Every organization has a `Default` project (`is_default`);
new sites and databases without a placement land in its `production` environment. Environments are addressed by
slug or id.

```json
{ "id": "01j…", "name": "Shop", "description": null, "icon": null, "is_default": false, "created_at": "…",
  "environments": [{ "id": "01j…", "project_id": "01j…", "name": "production", "slug": "production",
                     "is_production": true, "forked_from_id": null, "services_count": 3, "created_at": "…" }] }
```

### `GET /api/v1/projects` · `GET /api/v1/projects/{project}` — `projects.view`
### `POST /api/v1/projects` `{name, description?, icon?}` — `projects.manage`
Creates the project with its `production` environment. `201`.
### `PATCH /api/v1/projects/{project}` · `DELETE /api/v1/projects/{project}` — `projects.manage`
Only empty, non-default projects can be deleted (`422` otherwise).
### `GET /api/v1/projects/{project}/environments` — `projects.view`
### `POST /api/v1/projects/{project}/environments` `{name, from_environment_id?}` — `projects.manage`
With `from_environment_id` (also needs `sites.create`), every site is copied through `Sites\Contracts\SiteFactory`
(configuration, deploy script, toggles, shared paths, variables — no servers, push-to-deploy off) at the same canvas
position and service name; databases are not copied. `201 {data, warnings[]}`.
### `PATCH|DELETE /api/v1/projects/{project}/environments/{environment}` — `projects.manage`
Rename (the slug follows). Only empty, non-production environments can be deleted.

### Variable references
Site variables may contain `${{ <service>.<KEY> }}`; they resolve at deploy time (release `.env`, deploy script
environment, public build variables) against services of the **same environment**. Service names match
case-insensitively with spaces/dots/underscores as dashes. Database services expose `DATABASE_URL`,
`DB_CONNECTION`, `DB_HOST` (private network → provider private IP → public IP), `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD` (oldest user granted on the database); site services expose their own variables.
Unknown services/keys and cycles fail the deployment: `Unresolved variable references: …`.

## Source control

### `GET /api/v1/source-control/connections` — `source_control.view`
### `POST /api/v1/source-control/connections` — `source_control.manage`
`{provider: github|gitlab|bitbucket|custom, auth_type: token|basic|none, name?, base_url?, token?, username?, password?}`.
Tokens are verified against the provider before saving. Credentials are write-only and never returned.
Custom git uses `auth_type: none` (public URLs) or per-site deploy keys.
GitHub App connections (`auth_type: app`) are created only through the browser flow (Settings → Source control →
Connect GitHub), since GitHub asks the user to confirm the app and pick repositories; they are listed like any
other connection and can be used as `source_connection_id` for sites.

## Deployments

### Deployment resource
```json
{"id": "01k…", "site_id": "01k…", "number": 42,
 "status": "queued|waiting|building|deploying|succeeded|failed|cancelled",
 "phase": "build|fetch|prepare|migrate|activate|restart|healthcheck|rollback|null",
 "trigger": "manual|push|api|hook|rollback", "strategy": "zero-downtime",
 "branch": "main", "commit": "a1b2c3…", "message": "Fix checkout", "author": "Ada",
 "release_id": "01k…", "build_id": "01k…", "rolled_back": false,
 "url": "https://kiln.example.com/sites/01k…/deployments/01k…", "error": null,
 "waiting_reason": null, "waiting_since": null,
 "created_at": "…", "started_at": "…", "finished_at": "…"}
```
`rolled_back: true` with `status: failed` means servers that had switched were returned to the previous release.

`waiting`: the deployment was triggered while some of the site's servers are still being prepared (site user,
PHP-FPM pool, Bun/Deno runtime). It holds the site's queue, `waiting_reason` says why
(`"Waiting for 2 servers to finish preparing: web-1, web-2"`) and `waiting_since` when it began; it starts on its
own once every preparing server is ready (`started_at` is set then). Servers whose preparation failed are skipped
with a warning in the output as long as another server is ready; it fails (`error` says why) when the leader's
preparation fails, when no server can be prepared, or after `KILN_DEPLOY_WAIT_TIMEOUT_MINUTES` (default 30).

### `POST /api/v1/sites/{site}/deployments` — `deployments.create`
Body (all optional): `{"branch": "main", "commit": "<sha>"}`. Without a commit the branch head is resolved
through the source-control provider. → `201 {"data": Deployment}`. The deployment starts immediately
(`building`), waits behind the site's running deployment (`queued`), or waits for the site's servers to finish
preparing (`waiting`). While a (non-rollback) deployment is `waiting`, further triggers — this endpoint, the CLI,
the panel, push-to-deploy, deploy hooks — update that deployment instead of creating another: the latest
branch/commit wins, and the response is that deployment (same `id`).

### `GET /api/v1/sites/{site}/deployments` — `deployments.view`
Paginated, newest first.

### `GET /api/v1/deployments/{deployment}` — `deployments.view`
Deployment + `targets`:
```json
{"targets": [{"id": "…", "server_id": "…", "server_name": "web-1", "role": "leader", "batch": 0,
  "status": "pending|deploying|succeeded|failed|rolled_back|skipped", "activated": true, "error": null,
  "steps": [{"key": "fetch:…", "kind": "fetch", "label": "fetch", "phase": "fetch", "rollback": false, "batch": 0,
             "status": "pending|running|succeeded|failed|skipped", "command_id": "…", "exit_code": null, "error": null,
             "started_at": "…", "finished_at": "…", "duration_ms": 812}]}]}
```

### `GET /api/v1/deployments/{deployment}/output?after=<seq>` — `deployments.view`
Lines with `seq > after` (≤ 1000 per page), in order. Poll with `after = meta.next` until the deployment is
terminal (`meta.status`). `server` is null for build/orchestration lines.
```json
{"data": [{"seq": 1812, "at": "…", "server": "web-1", "server_id": "…", "step_id": "…",
           "phase": "migrate", "stream": "stdout|stderr", "data": "Migrating: …\n"}],
 "meta": {"next": 1812, "status": "deploying"}}
```

### `POST /api/v1/deployments/{deployment}/cancel` — `deployments.create`
Cancels a `queued` or `waiting` deployment, or one still `building` (nothing has touched the servers yet).
→ `200 {"data": Deployment}`; `422` otherwise.

### `POST /api/v1/sites/{site}/rollback` — `deployments.rollback`
Body `{"release_id": "<ulid>"}` (optional; default = the newest retained release before the current one).
→ `201 {"data": Deployment}` with `trigger: "rollback"`. `422` (`errors.release_id`) when the release is current,
failed or pruned, or when there is nothing to roll back to.

### `GET /api/v1/sites/{site}/releases` — `deployments.view`
Retained releases, current first.
```json
{"data": [{"id": "01k…", "commit": "…", "branch": "main", "message": "…", "author": "Ada",
           "deployment_id": "…", "build_id": "…", "image": null,
           "status": "active|inactive", "active": true, "can_rollback": false,
           "activated_at": "…", "created_at": "…"}]}
```

## Functions

Cloud Functions are sites with the `function` runtime (docs/FUNCTIONS.md); `{site}` is the function's id or slug.
The `kiln fn` commands use these endpoints.

### `GET /api/v1/functions` — `functions.view`
```json
{ "data": [{ "id": "01j…", "name": "Hooks", "slug": "hooks", "runtime": "bun", "entrypoint": "index.ts",
             "live": { "number": 3, "hash": "…", "short_hash": "4f1c2a9" }, "url": "https://hooks.example.com" }] }
```

### `GET /api/v1/functions/{site}` — `functions.view`
`{data: {site: {id, name, slug}, runtime: {key, label, language, family}, entrypoint, url, head, live, settings,
schedules}}`. `head` is the newest version **with** `files` (`{path: content}`); `live` is the version the servers
run (without files). A version is `{id, number, hash, short_hash, message, author, size, created_at}`.

### `POST /api/v1/functions/{site}/deploy` — `functions.deploy` + `deployments.create`
`{files: {path: content}, message?, base_version_id?, force?}`. Saves the files as a new version (unless they equal
the newest one) and deploys it: `201 {data: {version, created: true, deployment_id}, warnings[]}` (`200` with
`created: false` for unchanged code). Without `base_version_id` the code is deployed on top of the newest version.
When someone deployed after `base_version_id`: `409 {message, head}` (the newer version, with files), unless
`force: true`. `files` is the function's complete file set (files left out are removed in the new version); code
with more than one file is saved but not deployed while the server's agent is older than `fn.v3` (a warning says
so). Follow the deployment with `GET /api/v1/deployments/{deployment_id}`.

### `GET /api/v1/functions/{site}/versions` · `GET /api/v1/functions/{site}/versions/{number}` — `functions.view`
Newest first (up to 200); a single version includes `entrypoint`, `files` and `changes`
(`[{path, status: added|removed|modified}]` against the previous version).

### `POST /api/v1/functions/{site}/versions/{number}/deploy` — `functions.deploy` + `deployments.create`
Deploys that version again (a rollback when it is not the newest). `201 {data: {deployment_id}}`; `422` when the
version has several files and the server's agent is older than `fn.v3`.

### `POST /api/v1/functions/{site}/schedules/{schedule}/run` — `functions.deploy`
Runs a schedule now (`{schedule}` = its id, key or name). `202 {data: {run_id, schedule}}`.

### `GET /api/v1/functions/{site}/runs/{run}` — `functions.view`
`{data: {status, finished, exit_code, duration_ms, error, output}}`; poll until `finished`. Runs started from the
panel can be read here too.

## Deploy hooks

### `GET|POST /api/deploy/{token}`
The URL is shown (and regenerated) under *Site → Deploy settings*. Reserved query parameters:

| Parameter | Meaning |
|---|---|
| `kiln_deploy_branch` | branch to deploy (default: the site branch) |
| `kiln_deploy_commit` | exact commit SHA (7–64 hex) |
| `kiln_deploy_author` | author shown in the UI / `KILN_COMMIT_AUTHOR` |
| `kiln_deploy_message` | message shown in the UI / `KILN_COMMIT_MESSAGE` |

Every other parameter becomes `KILN_VAR_<NAME>` in the deploy script environment (name upper-cased,
non-alphanumerics → `_`; ≤ 50 variables, ≤ 4 KiB each; stored encrypted). → `202`
`{"data": {"id", "status", "number", "url"}}`; `404` for unknown/rotated tokens; `422` for an invalid commit.
Rate limited to 30/min.

## Internal builder API

`kiln-builder serve --url https://kiln.example.com --token kbt_…` (env `KILN_URL`, `KILN_BUILDER_TOKEN`,
`KILN_BUILDER_NAME`). Tokens: the control-plane host builder uses `KILN_LOCAL_BUILDER_TOKEN` (serves every
organization); builder servers get one installed automatically when they finish provisioning; external
builders are created under *Builds → Builders* (organization-scoped). `401` for unknown/disabled tokens.

### `GET /api/internal/builds/next?wait=<s>&builder=<name>&run=<run id>`
`run` identifies the kiln-builder process (random, new at every start). A poll with a new run id first fails the
builds that an earlier run with the same `builder` name had claimed (`Builder <name> restarted during the build.`),
so a restarted builder (e.g. `kiln-ctl update` recreating the container) does not leave them running until the
build timeout. Long-poll (≤ 25 s). `204` when nothing is queued for the builder (organization + mode eligibility), else
`200` with a job (`agent/internal/builder/job.go` `Job`):
```json
{"id": "01k…", "mode": "native", "timeout_s": 1800, "runtime": "php",
 "repo": {"url": "git@github.com:acme/shop.git", "ref": "main", "commit": "a1b2…",
          "deploy_key": "-----BEGIN OPENSSH PRIVATE KEY-----…", "known_hosts": "…"},
 "env": {"VITE_APP_NAME": "Shop"},
 "native": {"upload": {"url": "https://kiln.example.com/api/internal/artifacts/…?expires=…&signature=…",
                       "headers": {"Content-Type": "application/octet-stream"}}}}
```
Docker jobs carry `"docker": {"image": "<registry>/<namespace>/<site-slug>:<build-id>", "dockerfile": "…",
"build_args": {…}, "registry": {"server", "username", "password"}, "push": true}` instead of `native`.
Clone credentials come from SourceControl at hand-out time and are never stored. HTTPS clones use
`token`/`username` instead of `deploy_key`. `env` holds site variables with public front-end prefixes
(`builds.env_prefixes`) plus the variables exposed to the deploy script (the per-variable opt-in for other
build-time settings, e.g. Astro's `SITE_URL`). Native jobs carry `native.install_command` / `native.build_command`
when the site defines the variables `KILN_INSTALL_COMMAND` / `KILN_BUILD_COMMAND` (run with `sh -c`, replacing the
detected install / build step).

### `POST /api/internal/builds/{build}/events`
NDJSON body, one `contracts/agent-protocol/event.schema.json` object per line with `command_id` = build id.
Idempotent on `(build, seq)`. `started` → running; `output` → build log (live on `private-builds.{id}`, copied
into the deployment output as phase `build`); `progress`; `finished` with `exit_code` 0 and the builder
`Result` (`artifact.sha256|size_bytes|format` or `image.ref|digest`) → succeeded, otherwise failed
(`124` → timed out). With the local artifact driver the uploaded file's SHA-256 must match.
Responses: `204`; `404` build unknown or assigned to another builder; `413` batch > 8 MiB; `422` malformed line;
**`410` the build was cancelled — the builder aborts it** (`HTTPSink.OnGone`).

### `POST /api/internal/builds/{build}/heartbeat`
Sent every 20 s while a build runs. `204`; `404` unknown build or another builder's; `410` the build is over on the
control plane (cancelled, failed by the watchdog, reaped) — the builder aborts it. A running build of a builder that
reports run ids fails after `KILN_BUILD_HEARTBEAT_TIMEOUT` seconds (default 90) without a heartbeat or event.

### Artifacts (local driver)
`PUT /api/internal/artifacts/{key}` (builder upload) and `GET /api/internal/artifacts/{key}` (agent
`deploy.fetch`) are authorized by the signed, expiring URL alone (`403` otherwise). URLs are always `https`
(`KILN_ARTIFACTS_URL`, default `APP_URL`). With `KILN_ARTIFACTS_DRIVER=s3` the builder and agents talk to the
bucket directly through SigV4-presigned URLs instead.

### `GET /install/builder/linux-{amd64|arm64}`
kiln-builder binary for builder servers (from `KILN_BUILDER_BINARIES_PATH`, or `KILN_BUILDER_DOWNLOAD_URL`).
