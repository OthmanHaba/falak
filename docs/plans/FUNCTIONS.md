# Cloud Functions: plan

Status: phases 1–4 shipped in v0.4.0–v0.4.3 (2026-10-01); phase 5 (several files, Go) on feat/function-go.

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
  the host (verified on AWS against Kiln's nftables rules).

**Runtime image** `ghcr.io/…/kiln-fn-bun:<bun version>` (`runtimes/functions/bun/`):
- Bun plus a bootstrap that imports the entrypoint.
- It serves `export default` (a Hono app or `{ fetch }`) on `$PORT`; an accepted TCP connection means ready.
- Pinned by digest in `config('functions.runtimes')` and pulled on the first release.

**`fn.release.apply`** (new package `internal/functions`, redeliverable):
1. Write the files to `/var/lib/kiln/functions/<site>/releases/<release>/`.
2. Resolve dependencies: `bun install` in a one-shot container with a shared cache volume, network on, 120 s
   timeout, output streamed to the deployment log.
3. Boot check: create an instance, start it, wait for ready, then stop it if `min = 0`. A failure fails the step and
   the old release stays live.
4. Register the release with the gateway over `/run/kiln-fn/gateway.sock`. New requests go to the new release, and
   the old instances drain and stop.
5. Keep the last 5 releases on disk, so rollback needs no install; lock files are kept per code hash, so the same
   code always installs the same dependency versions.
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
- The gateway listens on `127.0.0.1:7070`.
- Instances publish no host port. The gateway reaches each instance at `<container ip>:8080` on the `kiln-fn`
  bridge, and `PORT=8080` is set inside the container.
- Why no host port: with Docker's userland proxy, a published loopback port accepts TCP connections before the
  runtime listens, which would break "accepted connection = ready".

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
  - `kiln.fn.slot=<n>` (slots keep counting per function), `kiln.fn.spec=<spec hash>`

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

## Phase 3 contract: Node, Deno and Python runtimes (fixed)

Every runtime image ships `kiln-fn-install`, `kiln-fn-serve` and `kiln-fn-run`, following the runtime convention in
`runtimes/functions/bun/README.md`. The agent and gateway do not change.

**Mounts and environment**
- `/app` is the release, read-only at serve and run time. `/cache` exists during install only.
- `PORT=8080`, `KILN_ENTRYPOINT`.
- Telemetry: `KILN_OTLP_SOCKET`, the `X-Kiln-Cold-Start` header, `KILN_TELEMETRY=off`.
- Schedules: `KILN_TRIGGER`, `KILN_SCHEDULE`, `KILN_SCHEDULE_NAME`, `KILN_SCHEDULE_CRON`.

**Telemetry, the same in every runtime** (`contracts/telemetry/README.md`)
- `request` spans named by the route template, `(unmatched)` when no route matched; `faas.coldstart`.
- Exceptions with stack traces.
- `outgoing_request` spans for HTTP clients (no query strings).
- `scheduled_task` spans for runs.
- Batched OTLP/HTTP JSON to the socket; it never blocks a request, and drops data when the socket is down.

| Runtime | Image (`runtimes/functions/<r>`) | Entrypoint | You export | Dependencies |
|---|---|---|---|---|
| `bun` | `oven/bun` slim | `index.ts` | `default` Hono app / `{ fetch }` / fetch function; `scheduled(event)` | bare imports → `bun install` (lock kept) |
| `node` | `node:24-slim` | `index.ts` (Node strips TypeScript types natively; use erasable syntax) | same as Bun | bare imports → generated `package.json` → `npm install` (`package-lock.json` kept) |
| `deno` | `denoland/deno` (Debian) | `index.ts` | same as Bun | bare imports → `deno.json` imports `npm:<pkg>` (or the user's own `deno.json` / `package.json`) → `deno install` into `/app` |
| `python` | `python:3.13-slim` + `uv` | `main.py` | `app` (an ASGI app: FastAPI, Starlette, …); `scheduled(event)` (sync or async) | a PEP 723 `# /// script` block or `requirements.txt` → `uv` into `/app/.venv` (`requirements.lock` kept); the image provides `uvicorn` |

- **TypeScript starters** are shared by bun, node and deno: Hono, the Web APIs, and npm packages that run on all
  three (e.g. `postgres`).
- **Python starters** use FastAPI and `httpx`.
- **Deno** runs with `--allow-net --allow-env --allow-read=/app,/tmp,/run/kiln-otlp --allow-write=/tmp,/run/kiln-otlp`.

## Phase 4 contract: access control, path mounts, API and CLI (fixed)

Agent feature flag: `fn.v2`. The control plane sends the new fields below only to agents that report it.

### Access control (gateway)

`fn.release.apply` gains an optional field:

```json
"access": {"api_key_hashes": ["<sha256 hex of a key>"], "allow_cidrs": ["203.0.113.0/24", "2001:db8::/32"]}
```

- **API key:** when `api_key_hashes` is non-empty, every proxied request must carry a key, as
  `Authorization: Bearer <key>` or `X-Kiln-Key: <key>`.
  - The gateway compares `sha256(key)` with constant time against the list.
  - A missing or wrong key gets `401` with `{"error":"…"}` and `WWW-Authenticate: Bearer`.
  - On a match, the gateway removes `X-Kiln-Key`, or the `Authorization` header if the key came in there, before
    forwarding. A function can then still use `Authorization` for its own scheme when callers send the Kiln key in
    `X-Kiln-Key`.
- **IP allowlist:** when `allow_cidrs` is non-empty, the client IP must be inside one of them, else `403`.
  - The client IP comes from `X-Kiln-Client-IP`, which Caddy sets to `{http.vars.client_ip}` and which honours
    Cloudflare's trusted proxies. When that header is missing, the gateway uses the TCP peer.
- Both checks can be on at once. Neither applies to scheduled runs.
- Rejected requests are reported as `request` spans, like other requests the gateway answers itself, but without
  the ERROR status (they are 4xx).
- The gateway removes `X-Kiln-Client-IP` before forwarding.
- Changing the access settings redeploys the live version, like scaling changes do.

### Path mounts (Caddy)

A site entry in `edge.caddy.apply` gains an optional field:

```json
"mounts": [{"path_prefix": "/api", "strip_prefix": true,
            "dial": "127.0.0.1:7070" | "fn.example.com:443", "tls_server_name": "fn.example.com" (optional),
            "request_headers": {"X-Kiln-Function": "<slug>", "X-Kiln-Client-IP": "{http.vars.client_ip}", "Host": "…"}}]
```

- A mount matches `<path_prefix>` and `<path_prefix>/*`, and runs after the site's own access rules (basic auth,
  IP allow and deny) and before its main handler.
- `strip_prefix` removes the prefix before proxying.
- With `tls_server_name`, the upstream is HTTPS with that SNI.
- Mounts are rendered in order, longest prefix first.
- **Two upstreams:**
  - The function runs on the same server: Caddy dials the local gateway with `X-Kiln-Function`.
  - Otherwise: Caddy proxies to the function's primary domain over HTTPS, with `Host` set to it.
- Function sites also get `X-Kiln-Client-IP: {http.vars.client_ip}` in their own route's request headers.

### API (Sanctum, `/api/v1`) and CLI

**Endpoints**, each checked against permission abilities:

| Endpoint | Body / result | Permission |
|---|---|---|
| `GET /functions` | list: site id, name, slug, runtime, live version, url | `functions.view` |
| `GET /functions/{site}` | head with files, live version, settings, schedules | `functions.view` |
| `POST /functions/{site}/deploy` | `{files: {path: content}, message?, base_version_id?, force?}`; 409 + head on conflict | `functions.deploy` + `deployments.create` |
| `GET /functions/{site}/versions` | version list | `functions.view` |
| `GET /functions/{site}/versions/{n}` | one version | `functions.view` |
| `POST /functions/{site}/versions/{n}/deploy` | rollback | `functions.deploy` + `deployments.create` |
| `POST /functions/{site}/schedules/{id}/run` | `{run_id}` | `functions.deploy` |
| `GET /functions/{site}/runs/{run}` | run status | `functions.view` |

`{site}` is the site id or slug.

**CLI** (`kiln fn …`):

| Command | Does |
|---|---|
| `list` | lists the functions |
| `pull <fn> [dir]` | writes the newest version's files and a `.kiln-function.json` holding the site id and base version id |
| `deploy <fn> [dir] [-m msg] [--force]` | sends the directory's files (the function's known file set: the entrypoint plus other files already in the version), using `.kiln-function.json` as the base; a conflict prints the newer version and exits 4 unless `--force`; `--wait` streams the deployment |
| `versions <fn>` | lists the versions |
| `rollback <fn> <n> [--wait]` | deploys version `n` again |
| `run <fn> <schedule>` | runs a schedule and streams its output |
| `logs <fn> [--follow]` | the site's logs, reusing `kiln logs` |
| `invoke <fn> [path] [-X method] [-d body] [-H 'K: V']…` | HTTP request to the function's URL; prints status, headers and body |

## Phase 5 contract: several files, Go runtime (fixed)

Agent feature flag: `fn.v3`.

### Several files

Storage did not change: a version (and a draft) is `files {path: content}` plus the function's `entrypoint`, and
its hash covers every file. What phase 5 adds:

- **Paths** (`Code::files` on the control plane, `validate()` in the agent, the same rules on both sides):
  - relative, `[A-Za-z0-9_][A-Za-z0-9_.-]*` per segment, at most 8 segments and 200 characters;
  - no dot-files, no `..`, no `node_modules` or `__pycache__` segment (the installers create those);
  - a file is never also a folder (`lib` and `lib/db.ts`), and no two paths differ only in case;
  - at most 50 files and 1 MB per version (the agent accepts up to 200 files and 2 MiB).
- **Old agents:** a version with more than one file is deployed only to servers whose agent reports `fn.v3`. The
  editor's Deploy saves the version and reports "update the agent" instead of deploying; Versions → Deploy and the
  API return 422. Single-file functions keep working on every agent. (Nothing is stripped: a release without some of
  its files would not run.)
- **Version detail** (`GET …/versions/{n}`, panel and API) gains `changes: [{path, status: added|removed|modified}]`
  against the previous version.
- **Editor:** a file tree beside Monaco (add, rename, delete; the entrypoint stays), one Monaco model per file under
  `file:///<slug>/edit/…` so TypeScript resolves relative imports, A/M/D marks against the newest version, and the
  conflict and version views diff file by file.
- **CLI:** `kiln fn deploy` sends the whole directory (minus dot-files and dot-folders, `node_modules`,
  `__pycache__`, `.venv`, `venv` and `.kilnignore` patterns), so deleted files leave the new version. Secret-looking
  files, symlinks, refused names and binary files are skipped with a note (an unusable entrypoint is an error).
  Files new to the function are listed and need confirmation (`--yes` outside a terminal). `.kiln-function.json`
  records each file's sha256; `kiln fn pull` removes a file the new version dropped only when it is unchanged.
- **Request limits:** draft and deploy requests are refused above `2 × max_bytes + 64 KB` (413) and above
  `max_files` entries before any per-file work; path checks use hash sets (no quadratic work).

### Go runtime

`runtimes/functions/go`, image `kiln-fn-go` (`golang:1.27-alpine`), config key `go`, entrypoint `main.go`, starter
family `go` (the same 10 starters). It follows the runtime convention; the agent and gateway do not change, and
it needs no new agent feature.

- **What the user writes:** `package main` with `Handler` (an `http.Handler` value or a
  `func(http.ResponseWriter, *http.Request)`) and/or `func Scheduled(ctx context.Context, event Event) error`, and no
  `main()`. `Event` and identifiers starting with `kiln` are reserved for the runtime.
- **`kiln-fn-install`** (a Go program in the image): checks the package with `go/parser`, copies `/app` to
  `/tmp/kiln-build`, adds `kiln_runtime.go` (server, telemetry, `Event`) and a generated `main()`, runs
  `go mod init function` when there is no `go.mod` and `go mod tidy`, copies `go.mod`/`go.sum` back to `/app` (the
  agent keeps them per code hash, like other lock files), and builds `CGO_ENABLED=0 go build -trimpath` into
  `/app/.kiln/fn`. `GOMODCACHE`/`GOCACHE` are in `/cache`.
- **`kiln-fn-serve`** runs `/app/.kiln/fn`; **`kiln-fn-run`** runs `/app/.kiln/fn run`. Hardening is unchanged
  (read-only root, uid 65534, `/app` read-only).
- **Telemetry:** like the other runtimes. Request spans are named by `http.Request.Pattern` (Go 1.23+);
  `http.DefaultClient.Transport` is wrapped for `outgoing_request` spans of calls that carry the request's or run's
  context (Go has no implicit async context; `http.DefaultTransport` stays an `*http.Transport`); panics become
  exceptions. The traced response writer passes Flusher, Hijacker and ReaderFrom through.

### Secrets in telemetry URLs

Every runtime records outgoing URLs as `scheme://host[:port]/path` (no query string, no userinfo) and inbound
`url.path` and fallback route names with secret-looking path segments replaced by `{redacted}`: Telegram
`bot<id>:<token>` → `bot{redacted}`, `<id>:<secret>` (16+ chars after the colon), 32+ chars of `[A-Za-z0-9_:-]`
with a digit, and 20+ chars of `[A-Za-z0-9_-]` mixing upper case, lower case and digits. The rules live in
`shared/redact.mjs`, `python/kiln_fn/redact.py`, the Go runtime and `agent/internal/fngateway/redact.go`, all tested
against `runtimes/functions/tests/redact-cases.json`. The gateway re-applies them to every relayed span (URL
attributes, URL-like span names, exception texts and status messages; `url.query` is dropped), so old runtime images
and functions' own SDKs are covered too. Python dependencies: the `# /// script` blocks of all `.py` files are merged.
