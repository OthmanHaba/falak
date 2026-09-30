# Cloud Functions: plan

Status: agreed design, phase 1 not started (2026-09-30).

A **Function** is a canvas service whose code is written in Kiln's editor (no git), deployed in seconds, reached by a URL
and/or a schedule, scaled with traffic, and **scaled to zero** when idle.

## Decisions (agreed)

| Topic | Decision |
|---|---|
| Who edits | Any team member with `functions.edit`; deploying needs `functions.deploy` |
| History | Every deploy is an immutable version (author, message, hash); rollback = redeploy a version |
| Concurrent edits | Deploy carries its base version; a newer deploy in between → 409 + diff/merge in the editor |
| Files | Single file now; storage is `files{path: content}` + `entrypoint`, so multi-file is UI-only later |
| Scaling | Scale to zero from day one; autoscale 0…max by in-flight concurrency; idle timeout 5 min default |
| Cold start | ~0.2–0.8 s accepted; `min instances = 1` removes it per function |
| Cron | One-shot container per run (phase 2), with timeout, overlap policy, run history |
| Runtimes | Phase 1 Bun + Hono; phase 3 Node, Deno, Python (uv + PEP 723), Go (build step), PHP |
| Placement | Phase 1: one server per function (chosen like a site's server); multi-server later |

## Architecture

```
Visitor → (Cloudflare) → Caddy ──X-Kiln-Function: <slug>──▶ kiln-fn-gateway (127.0.0.1:7070)
                                                              │ start / route / stop
                                                              ▼
                                         function containers 0…N (127.0.0.1:<port>)
```

A Function **is a site** with `SiteRuntime::Function`. It inherits domains, generated names, Cloudflare (DNS,
tunnel, cache, purge), environment versions, `${{ db.DATABASE_URL }}` references, deployments, logs and the
canvas node. The new `Functions` module owns what is specific: code versions, drafts, runtime and scaling settings.

### Control plane

**New module `Functions`** (`Kiln\Functions`, added to `Modules::ALL` after Sites/Deployments' contracts it uses).

Tables:
- `functions_functions`: `id`, `site_id` (unique), `runtime` (`bun`), `entrypoint`, `min_instances` (0),
  `max_instances` (5), `concurrency` (50), `idle_timeout_s` (300), `memory_mb` (256), `cpus` (0.5), `timeout_s`
  (30), `current_version_id`, timestamps.
- `functions_versions` (immutable): `id`, `function_id`, `number`, `files` (json), `entrypoint`, `hash`, `size`,
  `message`, `author_id`, `base_version_id`, `created_at`. Limit 1 MB.
- `functions_drafts`: `function_id`, `user_id`, `files`, `base_version_id`, `updated_at` (autosave per user).

Contracts:
- `FunctionCode::release(siteId, ?versionId): FunctionRelease` returns files, entrypoint, runtime, scaling and
  limits. Deployments uses it.
- `FunctionFactory::create(...)`: the create picker uses it. It goes through `SiteFactory` with runtime `function`
  and a starter.

Actions: `CreateFunction`, `SaveDraft`, `DeployVersion` (checks the base, writes the version, calls
`TriggerDeployment`), `UpdateScaling`, `RestoreVersion` (into the draft).

Permissions:

| Permission | Roles |
|---|---|
| `functions.view` | A, D, V |
| `functions.create` | A, D |
| `functions.edit` | A, D |
| `functions.deploy` | A, D |
| `functions.delete` | A |

Env vars keep using `sites.env.*`.

**Sites**
- Adds `SiteRuntime::Function`. It does not proxy to a port and is not a container.
- The CreateSite branch has no `app_port` and an always-deployable source.

**Deployments**
- `Release` gains `function_version_id`.
- For Function sites, `PlanBuilder` plans `fn:{target}` (`StepKind::FnRelease` → `fn.release.apply`).
- A rollback re-applies the release's version. A failed apply keeps the old release serving; the agent only switches
  after the new one boots.
- `StepPayloads::fnRelease()` resolves variables (references included) and adds `KILN_*` ids.
- A successful deploy fires `DeploymentSucceeded`, which already purges Cloudflare.

**Edge**
- `RouteCompiler`: Function → `reverse_proxy` to `127.0.0.1:7070` with request header
  `X-Kiln-Function: <slug>`.
- No active health checks, since they would keep the function awake.

**Fleet**
- The agent feature `fn.v1` gates function servers in the picker and in `PayloadCompatibility`.

### Agent

**Container hardening** (`internal/docker` `CreateBody` extended):
- `ReadonlyRootfs`, `Tmpfs{/tmp}`
- `CapDrop: [ALL]`, `SecurityOpt: [no-new-privileges]`
- `PidsLimit`, `User: 65534`
- Memory and CPU limits
- Code mounted read-only; no Docker socket
- Network: a `kiln-fn` bridge network (created once). Egress reaches databases on private and WireGuard IPs through
  the host (to verify against the nftables rules).

**Runtime image** `ghcr.io/…/kiln-fn-bun:<bun version>` (`runtimes/functions/bun/`):
- Bun plus a bootstrap that imports the entrypoint.
- It serves `export default` (a Hono app or `{ fetch }`) on `$PORT`, and `/_kiln/ready` for the gateway.
- Pinned by digest in `config('functions.runtimes')` and pulled on the first release.

**`fn.release.apply`** (new package `internal/functions`, redeliverable):
1. Write the files to `/var/lib/kiln/functions/<site>/releases/<release>/`.
2. Resolve dependencies: `bun install` in a one-shot container with a shared cache volume, network on, 120 s
   timeout, output streamed to the deployment log.
3. Boot check: create an instance, start it, wait for ready, then stop it if `min = 0`. A failure fails the step and
   the old release stays live.
4. Register the release with the gateway over `/run/kiln/fn-gateway.sock`. New requests go to the new release, and
   the old instances drain and stop.
5. Keep the last 5 releases on disk, so rollback needs no install.
6. Ensure `kiln-fn-gateway.service` is installed and running (pattern from `netcfg/tunnel.go`).

**`fn.release.remove`**: deregister the function, remove its containers and delete its directory. It runs on site
deletion and when a site leaves a server.

**Gateway** `kiln-agent fn-gateway` (new package `internal/fngateway`, its own systemd unit, so agent upgrades
never cut function traffic):
- Routes by `X-Kiln-Function`. An unknown function gets 404, and the header is stripped before forwarding.
- **Cold start:** instances are pre-created (stopped), so waking is `docker start` plus a ready poll every 10 ms.
  Requests wait in a bounded queue: 503 when full, 504 after the start timeout.
- **Scale up:** requests go to the least-loaded instance. When every instance is at `concurrency` and there are
  fewer than `max`, it starts another.
- **Scale down:** an instance idle for `idle_timeout_s` is stopped (the container is kept). The count goes down to
  `min`, and to zero by default.
- Per-request timeout. Streaming and websockets pass through (`httputil.ReverseProxy`).
- Its state is persisted in `/var/lib/kiln/functions/gateway.json`. On restart it adopts running containers by label
  (`kiln.site`, `kiln.function.release`).
- **Telemetry:** OTLP to the agent's local receiver:
  - a span per request (status, duration, cold/warm)
  - gauges for instances and in-flight requests
  - a counter for cold starts

  Container logs and metrics are already shipped because the containers carry `kiln.site`.

Contracts: `contracts/agent-protocol/commands/fn.release.{apply,remove}.schema.json`, examples, and catalogue
entries.

### UI

**Editor:**
- `@monaco-editor/react` with `monaco-editor`, bundled by Vite (no CDN) and lazy-loaded only on the Code tab.
- Bun and Hono types are added as extra libs, so autocomplete and type errors work.

**Create picker:** a "Function" option with runtime (Bun + Hono), name, server and a starter:
- Hello Hono
- JSON API + Postgres (`${{ db.DATABASE_URL }}`)
- Webhook receiver

**Function service tabs:**
- **Code:**
  - the editor, with draft autosave and a "Deploy" button (message, Cmd+S)
  - on a conflict, a diff editor with "take theirs / keep mine"
  - a status line: live version, instances (0 = sleeping), last cold start
- **Versions:** number, author, message and time; diff against live; **Deploy this version** (rollback);
  **Restore to editor**.
- **Settings → Scaling:** min/max instances, concurrency, idle timeout, memory, CPU, request timeout.
- **Existing tabs** (Deployments, Variables, Networking, Logs, Metrics) work unchanged.
- **Canvas:** a function icon, and a "sleeping" badge when there are 0 instances.

## Phase 1 slices (PRs)

1. **Agent:**
   - container hardening fields
   - `kiln-fn` network
   - `internal/fngateway`, the `fn-gateway` subcommand and unit
   - `fn.release.apply/remove`
   - the Bun runtime image and its CI
   - `fn.v1` feature
   - contracts and tests
2. **Control plane:**
   - the `Functions` module, `SiteRuntime::Function`
   - Deployments step and rollback, the Edge route, feature gate, permissions
   - feature tests
3. **UI:** Monaco editor, create picker and starters, Code/Versions/Scaling, canvas badge.
4. **AWS verification, docs (repo + website), release v0.4.0.**

## Tests

**Agent:**
- Gateway with a fake Docker and httptest backends:
  - cold start with queued requests
  - scale up to max under load, and scale down to 0 after idle
  - release switch with draining
  - restart adoption
  - queue-full and timeout paths
- `fn.release.apply` (install failure keeps the old release; boot check), schema tests.

**Control plane:**
- versions are immutable; a stale base returns 409
- permissions
- deploy plans an `fn` step; rollback re-applies the old version
- references resolve into the env
- the route compiles to the gateway with the header
- servers are gated by the feature

**AWS:**
- Deploy Hello Hono on app-2, then measure cold vs warm latency.
- Run `oha` load: instances rise to max, then return to 0 after the idle timeout.
- Connect to Postgres through a reference.
- Roll back.
- Serve on a Cloudflare name, with cache purge on deploy.

## Later phases

- **Phase 2, cron:** a schedule per function; the agent `cron` package gains a container job; one-shot
  `KILN_TRIGGER=cron` runs call `export async function scheduled()`; timeout, overlap skip/allow, run history
  (existing `cron_heartbeat`), **Run now**.
- **Phase 3, runtimes:** Node 24 (native TS, `@hono/node-server`), Deno (`npm:`/`jsr:`, permission flags), Python
  (FastAPI/ASGI, PEP 723 deps via `uv`), Go (`func Handle(w, r)`, build step with a module cache), PHP.
- **Phase 4:**
  - path mounting (`app.com/api/*` → function)
  - gateway access control (API key, IP allowlist)
  - charts (invocations, p95, errors, cold starts), the last-N requests view
  - a test-request panel
  - more starters
  - `kiln fn deploy|logs|invoke`
- **Later:** a multi-file UI, export to GitHub, async invocations with retries, event triggers, multi-server
  functions, Cloudflare Workers as a target.

## Risks

- **Egress from the `kiln-fn` bridge to databases** on private/WireGuard IPs under Kiln's nftables rules: verify
  first on AWS.
- **Gateway restarts** drop in-flight requests (graceful shutdown with drain; restarts are rare).
- **`bun install` needs registry egress**; its cache volume grows (pruned with releases).
- **Caddy `reverse_proxy` request headers** are new in `edge/render.go`, so they are gated with `fn.v1`.

## Phase 1 wire contract (fixed; both sides build against this)

Ports:
- Gateway listens on `127.0.0.1:7070`.
- Instances bind `127.0.0.1:<21000–29999>` (the gateway allocates the port and persists it). Inside the container
  the port is always `8080`, and `PORT=8080` is set.
- These ranges don't collide with app ports (3000–4999), Octane (8000–8999 / 18000–18999) or anything else Kiln
  allocates.

Caddy (edge.caddy.apply):
- A site entry of kind `reverse_proxy` gains `request_headers: {name: value}`, which is rendered as
  `reverse_proxy.headers.request.set`.
- A function site is `kind: reverse_proxy`, with upstream `127.0.0.1:7070` and
  `request_headers: {"X-Kiln-Function": "<site slug>"}`.

Runtime image convention (runtime-agnostic, so the agent does not know languages):
- **`kiln-fn-install`**:
  - cwd `/app` is the release dir, mounted read-write and owned by 65534
  - `/cache` is `/var/lib/kiln/functions/.cache/<runtime-key>`, read-write
  - env `KILN_ENTRYPOINT`
  - runs as uid 65534 with network
  - exit 0 = ok, and the output is streamed to the deployment
- **`kiln-fn-serve`**:
  - cwd `/app` is mounted read-only
  - env `PORT=8080`, `KILN_ENTRYPOINT` and the user's env
  - listens on `0.0.0.0:$PORT`; an accepted TCP connection means ready
- Every container:
  - user `65534:65534`, read-only root filesystem, tmpfs `/tmp` (64 MiB)
  - `CapDrop ALL`, `no-new-privileges`, `PidsLimit`
  - memory and CPU limits, network `kiln-fn` (a bridge the gateway creates if missing)
  - no Docker socket

Container names and labels:
- Name: `kiln-fn-<site>-<release[:12]>-<slot>`.
- Labels:
  - `kiln.managed=true`, `kiln.site=<site>`
  - `kiln.service=function`, `kiln.release=<release>`
  - `kiln.fn.slot=<n>`, `kiln.fn.port=<host port>`

Agent commands:
- **`fn.release.apply`** (redeliverable):
  - Fields:
    - `site`, `release` (`^[a-z0-9]{1,64}$`), `image`, `pull`, `registry_auth?`, `entrypoint`
    - `files: [{path, content}]` (≤ 200 files, ≤ 2 MiB in total, relative paths, no `..`)
    - `env`
    - `scaling {min_instances 0–20, max_instances 1–50, concurrency 1–10000, idle_timeout_s 10–86400}`
    - `limits {memory_bytes, cpus, pids, request_timeout_s, start_timeout_s}`
    - `install_timeout_s`, `keep_releases`, `labels`
  - Steps:
    1. Write the release dir `/var/lib/kiln/functions/<site>/releases/<release>/`.
    2. Run `kiln-fn-install` (skipped when the dir already exists with the same files hash).
    3. Ensure `kiln-fn-gateway.service` is running.
    4. `PUT` the spec to the gateway: it boots the new release, switches, and drains the old release.
    5. Prune releases beyond `keep_releases`.
  - Result: `{release, previous_release, installed, boot_ms}`.
- **`fn.release.remove`** `{site}`: deregister the function, remove its containers and delete its dir. Result `{removed}`.
- **`fn.status`** `{site?}`: result `{functions: [{site, release, running, starting, in_flight, cold_starts, requests, last_request_at}]}`.

Gateway (`kiln-agent fn-gateway`, unit `kiln-fn-gateway.service`, root, `RuntimeDirectory=kiln-fn`):
- Proxy on `127.0.0.1:7070`: routes by `X-Kiln-Function` (the header is stripped).
  - 404 for an unknown function
  - 503 when the queue is full
  - 504 on a start or request timeout
- Admin API over HTTP on `/run/kiln-fn/gateway.sock`: `PUT|DELETE /v1/functions/{site}`, `GET /v1/functions`.
- State is kept in `/var/lib/kiln/functions/gateway.json`. On start it adopts running containers by label.
- Agent feature flag: `fn.v1`.
