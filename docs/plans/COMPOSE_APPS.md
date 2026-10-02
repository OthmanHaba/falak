# Compose apps from a git repository (plan)

The user says a repository is a Docker Compose app, points Kiln at the compose file in the repo, and decides per
service how it runs. Nothing is auto-detected. Builds on the compose runtime (docs/COMPOSE_TEMPLATES.md §1).

## What exists and what is missing

| Exists | Missing |
|---|---|
| `runtime=compose`, `compose_source=repo`, `compose_file` (API, Settings → Compose) | The Git create step can't choose compose (it only sends a preset) |
| Builds of `build:` services (kiln-builder, docker mode), digest pinning | Kiln can't read a file from a repo, so services are unknown until a deploy |
| Public services on 127.0.0.1 ports, routed by Edge; site IP rules / basic auth apply | Only the primary public service is a real domain (Domains tab, cache modes); the others carry one bare domain |
| Policy checks, labels, Services tab, rollback, named volumes | Repo files a compose file needs (bind mounts, `env_file`, `configs`) never reach the server |
| | A Kiln database can't be reached from containers when it runs on an app server |
| | A site can't run from a repo subfolder (needed to split a service out) |
| | `include`, `extends`, several compose files, `profiles` |

## Flow (Git → "Docker Compose app")

1. **Repository step** (unchanged): connection, repository, branch.
2. **App type**: the user picks **Docker Compose app** (next to the presets). No guessing from the name.
3. **Compose file**: the user types the path (default `compose.yaml`; a list of `*compose*.y*ml` files in the repo is
   offered as suggestions, not chosen automatically) plus optional extra files (`-f` overrides, in order) and
   profiles. Kiln fetches the file(s) from the branch, merges them like `docker compose config`, and shows the
   parse result and the policy check. The repo stays the source of truth: the file is re-read on every deploy.
4. **Services**: one row per service with what Kiln found (image or build context, ports, volumes, depends_on,
   `${VAR}` references). For each service the user chooses:
   - **Keep in compose** (default): runs in the stack; internal unless made public.
   - **Public**: port (from the service's ports), domain (generated / test / custom / Cloudflare zone), and the Kiln
     edge features below.
   - **Kiln database** (postgres, mysql/mariadb, redis/valkey images only): the service is removed from the stack and
     a Kiln-managed database is created (or an existing one picked); the stack's variables that pointed at it are
     rewritten to `${{ <db>.KEY }}` references; `depends_on` on it is dropped.
   - **Own Kiln service**: a service with `build:` (or an image) is taken out of the stack and created as its own
     Kiln site on the same canvas: Laravel / Node / Docker runtime chosen by the user, repo subfolder = the build
     context, its variables carried over; references from the stack to it become `${{ <site>.URL }}`-style
     variables (internal URL over the server's network).
5. **Variables**: every `${VAR}` the stack uses and every key of its `env_file`s is listed with its default from the
   file; required ones (no default) must be filled before the first deploy.
6. **Create**: one compose site (plus the databases and split-out sites) in the environment; the canvas shows the
   group; first deploy starts.

All choices stay editable in Settings → Compose (services table with the same per-service options).

## Kiln-managed edge for public services (every public service, not only the first)

Each public service gets the same edge features as a site:
- Domains: several per service, managed in the Domains tab (per service), generated/test/custom/Cloudflare names,
  automatic TLS, `www` redirects.
- Cloudflare: DNS records, proxied mode, **cache mode** per domain, purge after deploy, Under Attack, origin
  lock-down; works through a **Cloudflare Tunnel** with no change (routes are Caddy routes on the server).
- Edge rules per service: IP allow/deny, basic auth, rate limits, headers, redirects, path mounts
  (`app.example.com/api/*` → a service).
- Health checks per public service (path), shown in the Services tab; Insights/uptime per service domain.

## Compatibility layer ("Kiln adjustments")

Kiln never edits the repo file. At render time it applies an overlay and shows it as a diff in Settings → Compose:
- host ports removed, public services on loopback ports (exists);
- `container_name` removed (clashes between environments and during rollback);
- host bind mounts of data folders (`./data:/var/lib/...`) → named volumes (the user confirms per mount); bind
  mounts of repo files (`./nginx.conf`) are kept and the files are shipped with the release (below);
- `env_file` keys folded into the site variables (the files themselves are not shipped unless committed);
- services replaced by Kiln databases or split into Kiln sites removed, with their references rewritten;
- `restart: unless-stopped` when missing; a warning per public service without a healthcheck;
- policy violations stay blocking unless the org allows privileged compose.

## Full compose support

- **Repo files with the release**: the builder (or a checkout on the leader for image-only stacks) collects the files
  the compose file references (bind-mount sources inside the repo, `env_file`s, `configs: file:`, `secrets: file:`)
  into the release directory; paths are rewritten relative to it. Size-limited; symlinks outside the repo refused.
- **Several files / overrides**: `compose_files: [...]`, merged by `docker compose config` in the builder (or a
  PHP merge for image-only stacks), the merged result is what gets rendered and pinned.
- **profiles**: `compose_profiles: [...]`.
- **include / extends**: resolved during the merge (files from the repo only).

## Prerequisites in other modules

- **SourceControl**: `file(connection, repo, ref, path)` and `tree(connection, repo, ref, glob)` on the gateway —
  GitHub/GitLab/Bitbucket APIs; plain git servers via a shallow clone by the builder (cached per commit).
- **Databases**: engines on app servers also listen on the Docker bridge address (firewalled to the bridge
  networks), so containers on the same server can reach a Kiln database; `DatabaseConnections` gives containers
  the bridge address instead of an error.
- **Sites/Builds**: `root_directory` (repo subfolder) for native and Docker sites: build, deploy and hooks run there.
- **Edge**: per-service domains as `edge_domains` rows owned by (site, service), so every Domains-tab feature applies.

## Phases

1. **Git compose flow**: SourceControl file read; "Docker Compose app" in the Git step; compose path + parse +
   services table with Keep / Public; variables from `${VAR}`; Settings → Compose services table.
2. **Edge for every public service**: per-service domains (edge_domains), Cloudflare (DNS, cache, purge, tunnel),
   edge rules, health checks per service.
3. **Kiln databases and split-out services**: Docker-bridge database access; replace a service with a Kiln database;
   `root_directory`; split a service into its own Kiln site; reference rewriting.
4. **Full compose**: repo files shipped with releases; several files, profiles, include/extends; the adjustments
   diff.

Each phase: tests (Pest, Go, Playwright for the flow), AWS rc verification with a real multi-service repo (app with
`build:`, postgres, redis, nginx with a mounted config), docs (docs/COMPOSE_TEMPLATES.md, website guide), release.

## Contract between lanes (fixed; built in parallel)

Three lanes branch from `feat/compose-apps`; each owns its files and changes others only through these contracts.

**Lane 1 — flow + full compose (phases 1 and 4)** owns SourceControl file reading, the Git step, Settings → Compose,
rendering (`EloquentComposeSites`), the builder/agent compose file shipping.
- `SourceControlGateway::file(string $connectionId, string $repository, string $ref, string $path): ?string`
  (null = not found; throws on errors; max 1 MB) and `tree(string $connectionId, string $repository, string $ref,
  string $glob = '*'): list<string>` (paths, max 2000). Providers without an API: `Exceptions\NoApi`.
- Site columns: `compose_files` json (list of repo paths, in `-f` order; replaces `compose_file`, which is migrated
  into it), `compose_profiles` json (list), `compose_services` json: `{<service>: {"mode": "keep"|"database"|"site",
  "database_id"?: ulid, "site_id"?: ulid}}` (absent service = keep). Public services stay in `public_services`.
- `ComposeConfig` gains `files`, `profiles`, `services` (decisions) — `file` stays as `files[0] ?? null`.
- `POST /sites/compose/inspect` {source_connection_id, repository, branch, files[], profiles[]} → parsed summary
  (services with image/build context/ports/volumes/depends_on/env refs, variables, violations, errors, warnings,
  adjustments). Also used by Settings → Compose for repo sources.
- Rendering removes services whose mode is `database`/`site`, drops `depends_on` on them, and applies the
  adjustments; it calls lane 3's contract for the variable rewrites.

**Lane 2 — edge for every public service (phase 2)** owns Edge.
- Every public service's domains are `edge_domains` rows with a new nullable `compose_service` column (null = the
  site's primary service, as today). `PublicService::$domain` stays as the first domain (read model), so lane 1's
  code keeps working; creation with a `domain` choice creates the row.
- Domains tab: a service selector for compose sites; cache modes, Cloudflare DNS/purge/tunnel, `www` redirects,
  edge rules (IP rules, basic auth, headers, rate limits) and path mounts apply per (site, service).
- `PublicService` gains `healthCheckPath` (?string); the HEALTHCHECK step checks each public service.

**Lane 3 — Kiln databases and split-out services (phase 3)** owns Databases, Builds/Deployments root directory, and
two actions in Sites.
- Databases: engines on app/worker servers also listen on the Docker bridge gateway (firewall: only the Kiln
  bridge networks); `DatabaseConnections::variables()` gives containers that address (`DatabaseConsumer` with
  `containerized: true` on the same server) instead of the error.
- Sites/Builds/Deployments: `root_directory` (repo subfolder, validated like `compose_file`) for native and Docker
  sites: builds, deploy steps and hooks run there.
- `Sites\Contracts\ComposeServiceExtraction`:
  - `toDatabase(string $siteId, string $service, ?string $databaseId, string $engine): DatabaseData` — creates (or
    links) the Kiln database on the site's leader, records `compose_services[service] = {mode: database,
    database_id}`, and stores the variable rewrites;
  - `toSite(string $siteId, string $service, array $site): SiteData` — creates the Kiln site (framework/runtime the
    user picked, `root_directory` = the service's build context, same repo/branch, the service's environment as
    variables), records `{mode: site, site_id}`;
  - `rewrites(string $siteId): array<string, string>` — env var → replacement (`${{ db.DATABASE_URL }}`, internal
    URL of a split-out site) used by lane 1's rendering.
- Detection of which variables point at a service (hostname = service name in URLs/hosts) lives here.

## Status

- **Lane 1 (phases 1 and 4)** on `feat/compose-apps-flow`: SourceControl `file()`/`tree()`, the Git step's
  "Docker Compose app", the services table, variables, Settings → Compose, Kiln adjustments at render time,
  multi-file/profile/include/extends projects in kiln-builder (shared merge cases) and repository files shipped
  with releases (agent feature `compose.v2`). `Sites\Events\ComposeServicesUnpublished` fires when a service stops
  being public (Edge removes its domains).
