# Kiln → Falak (فلك) rebrand — plan

Status: approved direction (2026-10-05) · Target: **v0.8.0 "Falak"** (bridge release) · Old names accepted through **v0.9.x** · Compatibility removed in **v1.0.0**

User decisions: rename **everything** (namespaces, Go module, binaries, env vars, paths, units, firewall table, images,
UI, docs, website); **migrate existing installs automatically**; rename the GitHub repos (`OthmanHaba/kiln` →
`OthmanHaba/falak`, `kiln-website` → `falak-website`); domain **falak.sh** (`curl -fsSL https://falak.sh | sudo bash`
once DNS points there; GitHub release URLs stay valid).

Grounded in `v0.7.1` (`7f6c6c3`) and the website at `84d4c6f`.

## 0. Summary

**Scope.** ~15,200 case-insensitive "kiln" hits in ~2,200 tracked files + 106 tracked paths; the website adds ~2,100
hits in 263 files. Per directory: control-plane 10,392, agent 1,393, deploy 724, observability 569, docs 530,
packages 497, runtimes 437, sim 369, contracts 193.

**Principles**

1. **One mechanical commit, then hand-written compat commits.** A script renames everything (contents + paths,
   case-preserving); compatibility is added afterwards in small reviewed commits. Every intentional legacy literal
   carries the marker `legacy:kiln`; a CI guard fails on any other "kiln".
2. **New code reads both names; writers use the new name only — except where an old reader may still exist** (old
   agents, old kiln-ctl, user code, old runtime images, old APM packages, webhook receivers): there both are written
   until v1.0.
3. **Platform layout migrates; workload identifiers on existing installs keep their names.** Agent binary, units,
   `/etc`/`/var/lib`/`/var/log` dirs, edge/gateway/firewall/tunnel units and the nft table move to falak. Names that
   running user workloads depend on — Linux user, `/srv/kiln/sites`, FPM pool/socket names, Redis/Valkey instance
   names, container names, `kiln-fn` network, runtime roots, registry namespace — stay `kiln` on installs that already
   have servers (the install-level **workload naming profile**, D1).
4. **Persisted identities are never rotated**: Fleet CA ("Kiln Agent CA"), agent certificates, compose project `kiln`
   and its volumes, Postgres db/user `kiln`, Redis queue/cache/session prefixes stay as they are on migrated installs;
   fresh installs get falak values.
5. **Upgrades keep working from what is installed**: the v0.7.1 `kiln-ctl update`, the v0.7.1 agent's
   `system.upgrade_agent`, the v0.7.1 `kiln` CLI and CI scripts. The release keeps publishing legacy asset names until
   v1.0.

## 1. Inventory

Classes: **(a)** internal, rename freely · **(b)** persisted state on hosts/DB, needs migration · **(c)** external
contract, accept/emit old and new until v1.0.

### 1.1 Code identity

| Category | Count | Refs | Class |
|---|---|---|---|
| PHP namespaces `Kiln\…` (21 modules + `Kiln\Apm`) | 7,865 hits / 1,516 files | `control-plane/composer.json` psr-4 | (a) code; **(b)** serialized FQCNs in queue payloads, Horizon/`failed_jobs`, cache. Migrations only `use Kiln\…` (rename is behaviour-neutral) |
| PHP classes with Kiln in the name | 3 | `KilnNavigation.php`, `KilnAdjustments.php`, `KilnPlaceholders.php` | (a) |
| Morph map | — | `Identity/src/IdentityServiceProvider.php:66` maps only `user` | none |
| Artisan commands `kiln:admin`, `kiln:agents`, `kiln:registry-idle`, `kiln:registry-prune` | 4 | called by old kiln-ctl (`deploy/kiln-ctl:193,219,716,918,921`) | **(c)** aliases |
| Go module `github.com/kiln/agent` | 327 hits / 131 files | `agent/go.mod:1`, `agent/Makefile:3` | (a) |
| Go cmds/binaries `kiln`, `kiln-agent`, `kiln-builder` | 3 | `agent/cmd/*`, Makefile | (a) repo; **(c)** release asset names |
| TS/React `@/components/kiln` (612 imports), `resources/js/lib/kiln.ts`, Inertia prop `kiln` | | `ProjectsServiceProvider.php:67` | (a) |
| Browser storage keys `kiln:*` | ~12 | `panel-stack.tsx:40`, `Canvas.tsx:169` | (b) minor: copy once client-side |
| Redis runtime keys `kiln:*`, `kiln.identity.permission.cache` | ~30 | `config/permission.php:52` | (a) transient |

### 1.2 Control-plane install

| Category | Refs | Class |
|---|---|---|
| Compose project `name: kiln`, `-p kiln`, volumes `kiln_<key>` (15) | `deploy/compose.yml:32,510-523`, `kiln-ctl:29,77-80,88` | **(b) critical** (pg-data, Fleet CA, ACME, registry) |
| Volume `kiln-ca` at `/kiln/ca` | `compose.yml:128,314,513` | (b) |
| Postgres db/user `kiln`; hard-coded in kiln-ctl backup/restore | `compose.yml:58-59,137-138,170`, `kiln-ctl:497,622-626` | **(b)** old rollback depends on it |
| `APP_NAME: Kiln` → `kiln_session`, `kiln_cache_`, `kiln_database_`, `kiln_horizon:` | `compose.yml:48`, `config/{session,cache,database,horizon}.php` | **(b)** in-flight jobs |
| Images `${KILN_IMAGE_PREFIX}/kiln-*` | `compose.yml:39,289,344` | (a) new; (b) rollback images stay `kiln-*` |
| Compose service names | `kiln-ctl:34` | **must not change** |
| ~55 host-side `KILN_*` vars in compose | `compose.yml` | **(c)** old kiln-ctl runs new compose with old `.env` |
| ~130 `env('KILN_…')` reads in CP config | `Fleet/config/fleet.php:8-61`, … | **(b)** `/opt/kiln/.env`, `custom.env` |
| `/opt/kiln`, `KILN_DIR`, `/usr/local/bin/kiln-ctl`, `/etc/cron.d/kiln-registry-gc` | `kiln-ctl:28,128-141,363-365`, `install.sh:28,306` | **(b)** |
| Backups `kiln-backup-*`, manifest `kiln_version=`, S3 prefix `kiln` | `kiln-ctl:473-551` | **(b)** |
| Bundle `kiln-deploy.tar.gz`, repo default `OthmanHaba/kiln`, `releases/latest` | `kiln-ctl:339-354`, `install.sh:45,292-296` | **(c)** |
| Bundle install copies `deploy/kiln-ctl` → `/usr/local/bin/kiln-ctl` | `kiln-ctl:356-366` | **(c)** shim hook |
| App storage `storage/kiln/{artifacts,agent,builder,ca}` | `builds.php:38,49`, `fleet.php:8,30` | (b) |
| Image-internal `/opt/kiln/{bin,contracts,templates,observability}`, `kiln-entrypoint`, `kiln-healthcheck` | `control-plane/Dockerfile:72-104` | (a) |
| Registry namespace `kiln`; stored release image refs | `builds.php:73`, `Registry.php:18`, `RegistryPruner.php:45` | (b) |
| `REVERB_APP_ID=kiln` | `install.sh:333` | leave (opaque) |
| Edge `X-Kiln-Client-Cert-Fingerprint` | `deploy/edge/Caddyfile:80-95`, `fleet.php:16` | (a) same release |

### 1.3 Managed servers

| Category | Refs | Class |
|---|---|---|
| `/usr/local/bin/kiln-agent`, `kiln-agent.service` (`EnvironmentFile=-/etc/kiln/agent.env`, `Runtime/State/LogsDirectory=kiln`) | `agent/internal/agent/install.go:17-50` | **(b)** |
| Self-upgrade replaces BinaryPath, keeps `.prev`, restarts `kiln-agent.service` | `system/upgrade.go:47-96`, `agent.go:200-205,308-315` | **(c)** delivery path |
| `/etc/kiln` (identity, certs, wireguard keys, nftables.conf, builder.env…), `/var/lib/kiln`, `/run/kiln`, `/var/log/kiln`, `/srv/kiln/sites` | `config/config.go:34-41` | **(b)** |
| Agent env `KILN_PANEL_URL, KILN_TOKEN, KILN_ETC_DIR…` | `config/config.go:55-70`, `install.go:79-88` | (b)+(c) |
| Units `kiln-edge`, `kiln-fn-gateway`, `kiln-firewall`, `kiln-cloudflared`, `kiln-builder` (+ `/var/lib/kiln-builder`) | `runtime/frankenphp.go:38-40`, `functions/functions.go:35-36,611-620`, `netcfg/firewall.go:105-106`, `netcfg/tunnel.go:19-22`, `InstallServerBuilder.php:17-123` | **(b)** |
| nft `table inet kiln`, comments `kiln:*` | `firewall.go:139,146,288,354` | **(b)** |
| WireGuard default `wg-kiln`; header marker `# Managed by Kiln` | `wireguard.go:100,178`, `peers.go:76`, `redis_boot.go:34` | (b) markers |
| Redis/Valkey `redis-server@kiln-<name>`, users `kiln-redis-*`, GECOS `Kiln Redis instance`, `/etc/kiln-redis`, `/var/lib/kiln-redis`, drop-ins | `db/redis.go:86-114`, `redis_boot.go:44` | **(b)** data |
| Linux user `kiln` (shared deploy user), `sites_sites.unix_user`, `servers_server_ssh_keys.unix_user`, sudoers `kiln-<user>` | `servers.php:64`, `sites.php:11`, `CreateSite.php:309-317`, `system/users.go:184-196` | **(b)** |
| FPM socket `/run/php/kiln-<site>-<ver>.sock` (CP-computed), pool `kiln-<pool>` | `SiteData.php:93`, `CommandPayloads.php:42`, `runtime/fpm.go:58-95` | **(b)** live traffic |
| Runtimes `/opt/kiln/{node,bun,deno}` | `runtime/node.go:60-74`, `jsruntime.go:85-98` | **(b)** |
| Managed files `50-kiln.conf`, `52kiln-unattended`, `99-kiln.ini`, `zz-kiln-network.cnf`, `.disabled-by-kiln`, `# BEGIN kiln-managed`, `managed_by_kiln` | `provision.go:551-557`, `runtime.go:385`, `db/network.go:174-179`, `apt.go:144`, `sshkeys.go:34-35`, `inspect/host.go:253,267` | **(b)** |
| Docker labels `kiln.*`, container names `kiln-<site>-<color>`, `kiln-fn-*`, network `kiln-fn`, buildx `kiln` | `docker/docker.go:198-201`, `swap.go:79,145,160`, `fngateway/spec.go:37-54,193`, `EloquentComposeSites.php:270`, `builder/docker.go:21` | **(b)** running containers; metrics/logs filter on `kiln.site` |
| Caddy ids `kiln-site-*`, `kiln-upstreams-*`, servers `kiln`, loggers | `edge/render.go:58-216` | (b) full re-apply first |
| `.kiln-release.json`, `.kiln-placeholder` | `deploy/fetch.go:50`, `functions.go:37`, `edge/edge.go:193` | **(b)** |
| mTLS subjects (`O=kiln-agent`, `Kiln Agent CA`) — nothing validates them | `enroll.go:180`, `CertificateAuthorityService.php:96-248` | **(b)** never re-issue CA |

### 1.4 Agent protocol

| Item | Refs | Class |
|---|---|---|
| `/agent/v1/*`, enrollment, `/install/<token>` | — | none |
| `X-Kiln-Agent-Session` | `transport/client.go:53`, `ReadsProtocolDocuments.php:107-120` | **(c)** CP accepts both |
| User-Agent `kiln-agent/<v>` | `agent.go:183` | (a) |
| Feature flags + `PayloadCompatibility` | `version/version.go:16-58`, `PayloadCompatibility.php:20-80` | add `falak.v1` |
| Schema defaults/examples (`/srv/kiln/sites`, `wg-kiln`) | contracts | (a); CP keeps sending explicit `sites_root` |
| Inspect `source: "kiln"`, `managed_by_kiln` (stored reports) | `provision.inspect.schema.json:45,238`, `DecisionEngine.php:422-660` | **(c)+(b)** read both |
| `x-kiln-redeliverable`, schema `$id` `https://kiln.dev/…` | `ProtocolSchemas.php:22` | (a) |
| CP-served install script | `Fleet/Infrastructure/InstallScript.php:40-176` | **(c)** detect both layouts |

### 1.5 Contracts with user code and external clients

| Item | Refs | Class |
|---|---|---|
| ~40 env vars injected into user apps/hooks/processes/functions (`KILN_SITE_ID`, `KILN_SERVER_ID`, `KILN_DEPLOYMENT_ID`, `KILN_RELEASE_ID`, `KILN_COMMIT*`, `KILN_BRANCH`, `KILN_TRIGGER`, `KILN_RELEASE_DIR`, `KILN_PHP_BINARY`, `KILN_SITE*`, `KILN_HOOK`, `KILN_PROCESS_*`, `KILN_SCHEDULE*`, `KILN_ENTRYPOINT`, `KILN_OTLP_SOCKET`, `KILN_TELEMETRY`, `KILN_VAR_<NAME>`) | `StepPayloads.php`, `AgentProcessControl.php:84`, `StateCompiler.php:356`, `SiteVariables.php:51`, `deploy/lifecycle.go:215-286`, `supervisor/instance.go:156-157`, `cron/cron.go:357`, `fngateway/spec.go:216`, `DeployHookController.php:40` | **(c)** |
| Deploy-script macros `$KILN_FETCH/ACTIVATE/RESTART_PROCS` (in user scripts stored in DB) | `ScriptSections.php:18-70`, `lifecycle.go:239` | (c)+(b) |
| Reserved prefix `KILN_`; `KILN_INSTALL_COMMAND`/`KILN_BUILD_COMMAND` | `RepoComposeInspection.php:241`, `BuildConfiguration.php:79-80`, … | (c) |
| Template placeholders `${{ kiln.* }}` (50+ in templates; custom templates in DB) | `KilnPlaceholders.php:19,49-58` | (c)+(b) |
| Alert webhooks `X-Kiln-{Event,Delivery,Timestamp,Signature}` | `WebhookSender.php:48-51` | (c) send both |
| Git webhooks `X-Kiln-Token`; deploy hook `kiln_deploy_*` | `WebhookPayloads.php:35`, `DeployHookController.php:21` | (c) accept both |
| Function headers `X-Kiln-Key`, `X-Kiln-Function`, `X-Kiln-Client-IP`, `X-Kiln-Cold-Start`, `X-Kiln-Exit-Code`, `X-Kiln-Error`; `kiln-fn-{serve,install,run}`; `/app/.kiln/fn` | `fngateway/*`, `RouteCompiler.php:255,383`, runtimes | (c) |
| Token prefixes `kfn_`, `kbt_` (hashed, prefix unchecked) | `ManageAccess.php:45`, `Builder.php:64` | (a) new tokens |
| CLI `kiln`, `~/.config/kiln/credentials.json`, `KILN_URL/TOKEN/CONFIG_DIR`, `.kiln-function.json`, `.kilnignore` | `cli/config.go:18-28`, `cli/functions.go:31,631` | (c)+(b) |
| CI downloads `releases/latest/download/kiln-linux-amd64`; `install-cli.sh` | website docs, `deploy/install-cli.sh` | (c) |
| APM packages `kiln/apm-laravel`, `@kiln/apm-node` (not published; default socket `/run/kiln/otlp.sock`) | `packages/` | (c)-light |
| Telemetry `kiln.*` OTLP attributes (~576), `kiln_*` Loki/PromQL labels (~503), `service.name=kiln-agent` | `metrics/containers.go:180-186`, `otlp/attrs.go:128`, `loki.yaml:56-59`, `LogQueryBuilder.php:37`, `TraceQueryBuilder.php:20` | (b)+(c) |
| Grafana uids `kiln-*`, folder `kiln-org-<id>`, tag `kiln`, provisioning files | `DatasourceDefinitions.php:21-89`, `GrafanaNames.php:12`, `GrafanaAnnotations.php:33` | (b) |

### 1.6 Release, repo, website

| Item | Refs | Class |
|---|---|---|
| GHCR `ghcr.io/othmanhaba/kiln-{control-plane,builder,edge,fn-*}` | `.github/workflows/release.yml:3-5,70,136` | (c) old tags stay pullable |
| Release assets `kiln-agent-*`, `kiln-builder-*`, `kiln-{linux,darwin}-*`, `kiln-deploy.tar.gz`, `kiln-ctl`, `install*.sh`, `SHA256SUMS` | `deploy/tools/release-assets.sh:5-48` | **(c)** |
| Repo docs; `docs/plans/*` historical | | (a) + history policy |
| Website (2,115 hits, 3 paths, `SITE_URL`, GitHub links, `KILN_REPO_PATH`) | `astro.config.mjs:12,66-67`, `scripts/sync-templates.ts:14` | (a) + redirects |
| sim | `sim/` | (a) |

## 2. Migration design

### 2.0 Versions and alias policy
- **v0.8.0 (bridge):** all artifacts falak-named; every compat path of §2.6 active.
- **v0.8.x/v0.9.x:** deprecation window; panel notices for `$KILN_*` in deploy scripts, outdated agents, CLI invoked as `kiln`.
- **v1.0.0:** writers stop emitting legacy names; readers drop legacy *inputs*; keep reading legacy *persisted markers*;
  legacy assets stop; `falak-ctl update` to ≥1.0 refuses unless migrated and all agents ≥0.8.
- **Upgrade paths:** Kiln ≤0.7.1 → v0.8/0.9 directly (old `kiln-ctl update` or installer re-run); ≥0.8 → v1.0. An old
  kiln-ctl pointed at v1.0 fails safely (no `kiln-deploy.tar.gz`, it dies before swapping).

### 2.1 Control-plane host
**v0.7.1 `kiln-ctl update --version v0.8.0` (unchanged code):** backup (`pg_dump -U kiln -d kiln`, volumes) → download
`github.com/OthmanHaba/kiln/releases/download/v0.8.0/kiln-deploy.tar.gz` (rename redirect) → swap `deploy/`,
`observability/`, install `deploy/kiln-ctl` to `/usr/local/bin` → `env_set KILN_VERSION`, compose `-p kiln` pull + up
→ health + `artisan kiln:agents --outdated --count` → on failure restore with the old hard-coded names.

**v0.8.0 bundle guarantees**
- Publish `kiln-deploy.tar.gz` (identical to `falak-deploy.tar.gz`), both in `SHA256SUMS`.
- `compose.yml` resolves with an old `.env`: nested defaults `${FALAK_X:-${KILN_X:-<default>}}` for ~55 vars (validate
  with `docker compose config` in P0; if nested `:?` is unsupported, drop the guards and validate in falak-ctl).
- **Persisted-state defaults are the legacy values** (fresh installs get explicit falak values from `install.sh`):
  `FALAK_DB_NAME/USER:-kiln`, `FALAK_REDIS_PREFIX:-kiln_database_`, `FALAK_CACHE_PREFIX:-kiln_cache_`,
  `FALAK_SESSION_COOKIE:-kiln_session`, `FALAK_HORIZON_PREFIX:-kiln_horizon:`, CA volume key `fleet-ca` with
  `name: ${FALAK_FLEET_CA_VOLUME:-kiln_kiln-ca}`, `FALAK_REGISTRY_NAMESPACE:-kiln`. `APP_NAME` → `Falak` without
  moving keys. Fresh `.env`: `FALAK_PROJECT=falak`, `FALAK_DB_NAME=falak`, … `FALAK_REGISTRY_NAMESPACE=falak`.
- Compose **service names unchanged**.
- `deploy/kiln-ctl` in the bundle is the **shim**; `deploy/falak-ctl` is the tool.
- Container env is FALAK_-only; `LegacyEnv` bootstrapper (`bootstrap/app.php`, before `LoadConfiguration`) copies
  `KILN_X` → `FALAK_X` when unset (covers `custom.env`), one deprecation log line per key.
- Artisan aliases `kiln:agents`, `kiln:admin`, `kiln:registry-idle`, `kiln:registry-prune`.
- Entrypoint moves `storage/kiln` → `storage/falak` once (+ symlink).
- Result: v0.8.0 images run in the **legacy layout** (`/opt/kiln`, `KILN_*`, project `kiln`) — fully supported.

**Shim `/usr/local/bin/kiln-ctl`** (~30 lines): note on stderr; find falak-ctl (`/usr/local/bin`, `/opt/falak/deploy`,
`/opt/kiln/deploy`, install it if needed); `exec falak-ctl "$@"`; if none (rolled back to a 0.7 bundle) `exec
/opt/kiln/deploy/kiln-ctl` when that file is not itself a shim.

**`falak-ctl`**: `FALAK_DIR` = `/opt/falak` if `/opt/falak/.env` exists else `/opt/kiln` (legacy mode, hint); honours
`KILN_DIR`; project from `FALAK_PROJECT` → `KILN_PROJECT` → `kiln` in legacy dirs. **Migration** (`cmd_migrate`,
idempotent, locked) runs as step 0 of `falak-ctl update`, on `falak-ctl migrate`, and from a new `install.sh` that
finds a legacy install; other commands only hint.
1. `cmd_backup --label pre-falak-migration`.
2. Rewrite `.env`/`custom.env` `KILN_X` → `FALAK_X`, add pins (`FALAK_PROJECT=kiln`, db/user `kiln`, the four prefixes,
   `FALAK_FLEET_CA_VOLUME=kiln_kiln-ca`, `FALAK_REGISTRY_NAMESPACE=kiln`, `FALAK_BACKUP_S3_PREFIX=kiln` when S3 is set
   without a prefix); keep `.env.kiln-backup`, `custom.env.kiln-backup` (600).
3. `mv /opt/kiln /opt/falak` + `ln -s /opt/falak /opt/kiln` when it holds only CP entries; otherwise move only those
   (agent runtimes may live there, R7).
4. `compose up -d --wait` (bind sources changed → recreate, short blip).
5. `/etc/cron.d/kiln-registry-gc` → `falak-registry-gc`, log path updated.
6. Install `/usr/local/bin/falak-ctl`; keep the shim until v1.0.
7. Write `$FALAK_DIR/.migrated-from-kiln`.

Backups after migration: `falak-backup-*`, manifest `falak_version=`; list/retention/restore accept both names and
both manifest keys; restore uses resolved `FALAK_DB_*` and volume names; restoring a Kiln backup onto a migrated
install works (pinned names). Pruning handles `falak-*` and `kiln-*`, never the `FALAK_PREVIOUS_VERSION` tag.
Downgrade to 0.7.x after migration: runbook `falak-ctl migrate --revert` + `kiln-ctl restore <pre-update> --yes`.

**New `install.sh`:** legacy `/opt/kiln/.env` without `/opt/falak/.env` → migrate then re-run (never a second stack);
no-arg piped run prompts on `/dev/tty`; `KILN_*` installer env accepted; `DEFAULT_REPO=OthmanHaba/falak`;
downloads `falak-deploy.tar.gz`.

### 2.2 Managed servers
**Delivery:** unchanged v0.7.1 `system.upgrade_agent` path → the new binary first runs **as `kiln-agent.service` from
`/usr/local/bin/kiln-agent` with `/etc/kiln/agent.env`**.

**Dual-layout binary:** `brand` package — every setting `FALAK_X` → `KILN_X` → default; default dirs `/etc/falak` if
`/etc/falak/agent.json` exists, else `/etc/kiln` if `/etc/kiln/agent.json` exists (same for state/log/run). If
migration fails it keeps running in legacy mode, reports `layout: "kiln"` + `migration_error`, retries next start.

**Legacy-layout migration (`internal/legacy`, before identity load and polling):**
1. Detect legacy identity (or running inside `kiln-agent.service` via `/proc/self/cgroup`); flock; step journal
   `/var/lib/falak-migration.json` (restart-safe, reversible).
2. Copy `/proc/self/exe` → `/usr/local/bin/falak-agent` (atomic).
3. `rename(2)` `/etc/kiln` → `/etc/falak`, `/var/lib/kiln` → `/var/lib/falak`, `/var/log/kiln` → `/var/log/falak`;
   compat symlinks at the old paths until v1.0.
4. Rewrite `agent.env` keys; write `/etc/falak/naming` = `kiln` (D1).
5. Write `falak-agent.service` (`Conflicts=kiln-agent.service`, `After=kiln-agent.service`,
   `EnvironmentFile=-/etc/falak/agent.env`, `Runtime/State/LogsDirectory=falak`,
   `OnFailure=falak-agent-rescue.service`); daemon-reload; enable falak, disable kiln (unit file kept for rescue).
6. `/usr/local/bin/kiln-agent` → symlink to `falak-agent`; `.prev` stays the 0.7.1 binary.
7. Handover: `systemctl --no-block start falak-agent.service` (Conflicts stops the old one gracefully first).

**After the first start as falak-agent** (journaled, only when the legacy unit exists): `kiln-edge` → `falak-edge`
(keep User/Binary/SupplementaryGroups; ~1 s Caddy restart; ACME data untouched), `kiln-fn-gateway` →
`falak-fn-gateway` (adopts running containers), `kiln-firewall` → `falak-firewall` (oneshot, rules stay loaded),
`kiln-cloudflared` → `falak-cloudflared`; `/run/kiln` → `/run/falak` symlink each start until v1.0 (APM sockets);
facts add `features: falak.v1`, `layout`, `naming`.

**CP reaction to `falak.v1`:** re-send `net.firewall.apply` (renderer prefixes `table inet kiln {}` + `delete table
inet kiln` before `table inet falak {…}` — one atomic `nft -f`); re-run the server builder install with falak names +
cleanup of `kiln-builder` and `/var/lib/kiln-builder`; full `edge.caddy.apply` (PATCH-by-id looks up
`falak-upstreams-*` then `kiln-upstreams-*`).

**Managed files rename-on-converge:** writing `50-falak.conf`, `52falak-unattended`, `99-falak.ini`,
`zz-falak-network.cnf`, `/etc/sudoers.d/falak-<user>` (after `visudo -c`) removes the legacy counterpart.

**Legacy markers read permanently:** `# Managed by Kiln`, `# BEGIN kiln-managed`, GECOS `Kiln … instance`,
`.disabled-by-kiln`, `.kiln-release.json`, `.kiln-placeholder`, `table inet kiln`, `managed_by_kiln`, `source: kiln`.

**Rescue/rollback:** failure before handover → undo journal, continue legacy, CP warns from facts. Failure after
handover → `OnFailure=falak-agent-rescue.service` (**shell** oneshot): undo symlinks/moves, restore `agent.env`
backup, `kiln-agent.prev` → `/usr/local/bin/kiln-agent`, `systemctl disable falak-agent; enable --now kiln-agent` —
the 0.7.1 agent reconnects with the same identity; the CP's upgrade timeout (`AgentUpgradeRollout.php:149`) marks the
upgrade failed (message mentions both units). Panel downgrade of migrated hosts to <0.8 unsupported.

**Identity/mTLS preserved** (files moved, not regenerated; CA pinned by `ca.crt`; renewal CSRs `O=falak-agent`
ignored; CA subject never changed).

**Fresh enrolment:** install script handles neither layout (fresh falak), `/etc/kiln/agent.json` present (back up to
`previous/`, stop both units, enrol falak layout), and writes `/etc/falak/naming` from the enroll response.

### 2.3 Database (v0.8.0 migrations)

| Data | Action |
|---|---|
| Workload naming | `kernel_settings` table: `workload_naming = kiln` when servers or sites exist at migration time, else `falak`; read via `Kernel\Support\Brand::workloadPrefix()` — drives unix user, new shared site user, `sites.root`, FPM socket, runtime root, registry namespace default, default wg iface, enroll response |
| `unix_user='kiln'` rows (sites, ssh keys, terminal sessions) | unchanged (D1) |
| `$KILN_*` in deploy scripts, `KILN_INSTALL/BUILD_COMMAND`, `${{ kiln.* }}` in custom templates | not rewritten; read both until v1.0; `falak:legacy-references` report + panel notice |
| Existing user variables named `FALAK_*` (newly reserved) | warning in migration + panel |
| Inspection reports/facts with `source: kiln`, `managed_by_kiln` | read both permanently |
| Serialized `Kiln\…` FQCNs | alias autoloader (`class_alias` `Kiln\X` → `Falak\X`) until v1.0 |
| Grafana objects | re-provision `falak-*`, delete `kiln-*` uids; annotations match tags `falak` OR `kiln`; provisioning `deleteDatasources` for `kiln-*` |
| Registry repos `<registry>/kiln/<site>` | namespace pinned `kiln` on migrated installs; pruner scans both |

### 2.4 Running user apps and containers
- **Env (CP):** `RuntimeEnv::withLegacyAliases()` at the four injection points adds `KILN_X` next to every `FALAK_X`
  for all agents until v1.0 (compose `.env` in releases carries both).
- **Env (agent):** `HookEnv`, supervisor, cron, fn env emit both; macros `FALAK_*` and `KILN_*` both defined as `:`;
  `ScriptSections` accepts both (mixing counts as a repeat).
- **Labels:** new containers get `falak.*` and `kiln.*` until v1.0; readers filter `falak.site` then `kiln.site`; CP
  compose label injection writes both; containers recreate on the user's next deploy.
- **Container names, fn network** follow D1 (`/etc/falak/naming`); firewall fn-bridge wildcard covers both.
- **Redis/Valkey** follow D1 and are self-describing (state records `prefix`, missing = `kiln`); existing instances
  keep `redis-server@kiln-<name>`, users, dirs, drop-in names — **no data moves**.
- **FPM, `/srv/kiln/sites`, `/opt/kiln/{node,bun,deno}`, user `kiln`:** unchanged on migrated installs.
- **Functions:** `falak-fn-*` images ship `falak-fn-{serve,install,run}` + `kiln-fn-*` symlinks, read `FALAK_*` then
  `KILN_*`, `x-falak-cold-start` then `x-kiln-cold-start`, `/app/.falak/fn` then `/app/.kiln/fn`; gateway sends both
  cold-start headers and env sets, invokes `falak-fn-serve` when the image label `sh.falak.fn.commands=falak` is set
  else `kiln-fn-serve` (pinned `kiln-fn-*:v0.7.x` keep working); accepts `X-Falak-Function`/`X-Kiln-Function`,
  `X-Falak-Client-IP`/`X-Kiln-Client-IP`; CP route compiler sets both until v1.0; `X-Falak-Key` and `X-Kiln-Key`
  accepted permanently.
- **Telemetry:** agent relay rewrites `kiln.` → `falak.` attribute keys and metric names (one function in
  `internal/otlp`); agent `service.name` → `falak-agent`; Loki indexes `falak.*` and, until v1.0, `kiln.*`; panel
  log/trace builders query both spellings until v1.0 (TraceQL `||`, two LogQL selectors merged); charts and dashboards
  switch to `falak_*`, older metric history stays in Grafana Explore under `kiln_*` (D6).

### 2.5 External clients
- Old `kiln` CLI keeps working (API paths/auth have no brand).
- New `falak` CLI: reads `FALAK_URL/TOKEN/CONFIG_DIR` then `KILN_*`; copies `~/.config/kiln/credentials.json` when
  the falak one is missing; reads `.falak-function.json` then `.kiln-function.json` (rewrites on next deploy),
  `.falakignore` then `.kilnignore`; invoked as `kiln` → one-line deprecation notice.
- Release assets `kiln-{linux,darwin}-{amd64,arm64}` published until v1.0 (copies of `falak-*`); `install-cli.sh`
  installs `falak` + a `kiln` symlink.
- Alert webhooks send both header sets (same signature) until v1.0.
- Git webhooks accept `X-Falak-Token`/`X-Kiln-Token` permanently; deploy hooks `falak_deploy_*`/`kiln_deploy_*`;
  script env `FALAK_VAR_*` + `KILN_VAR_*` until v1.0.
- Existing API/builder/function tokens stay valid; new prefixes `fbt_`, `ffn_` (D8).
- Template placeholders `${{ falak.* }}` and `${{ kiln.* }}` both accepted.

### 2.6 Alias register (each code site carries `legacy:kiln`)

| Old name | Where | Until |
|---|---|---|
| `KILN_*` in CP `.env`/`custom.env` | compose nested defaults + `LegacyEnv` | v1.0 |
| `KILN_*` agent/builder env | `brand.Env` fallback | v1.0 |
| `KILN_*` user-app env vars | emitted alongside `FALAK_*` | v1.0 |
| `$KILN_FETCH/ACTIVATE/RESTART_PROCS` | parsed + defined | v1.0 |
| `KILN_INSTALL_COMMAND`/`KILN_BUILD_COMMAND`; reserved prefix `KILN_` | read; reserved | read v1.0; reserved permanently |
| `${{ kiln.* }}` | accepted | v1.0 |
| `X-Kiln-{Event,Delivery,Timestamp,Signature}` | emitted | v1.0 |
| `X-Kiln-Token`, `kiln_deploy_*`, `KILN_VAR_*` | accepted/emitted | v1.0 (token permanently) |
| `X-Kiln-Key` | accepted | permanent |
| `X-Kiln-{Function,Client-IP,Cold-Start,Exit-Code,Error}` | emitted + accepted | v1.0 |
| `X-Kiln-Agent-Session` | accepted by CP | v1.0 |
| `kiln.*` Docker labels | emitted + read | emit v1.0, read permanently |
| `kiln.*` OTLP attrs/metrics | translated + dual query | v1.0 |
| `kiln-ctl` shim, artisan `kiln:*` | | v1.0 |
| `kiln` CLI assets, `kiln-agent-*`/`kiln-builder-*`/`kiln-deploy.tar.gz`/`kiln-ctl` assets | | v1.0 |
| `/opt/kiln`, `/etc/kiln`, `/var/lib/kiln`, `/var/log/kiln`, `/run/kiln`, `storage/kiln`, `/usr/local/bin/kiln-agent` symlinks | | v1.0 cleanup |
| `Kiln\*` class aliases | | v1.0 |
| legacy markers, GECOS, `inet kiln` delete preamble, `.kiln-*` files, `~/.config/kiln` | read | permanent |
| `/srv/kiln/sites`, user `kiln`, `redis-server@kiln-*`, `/run/php/kiln-*.sock`, `kiln-<site>-<color>`, `kiln-fn`, registry ns `kiln`, compose project/volumes/db `kiln`, Redis prefixes `kiln_*` | **not aliases** — the legacy profile of migrated installs (D1–D4) | permanent unless a future opt-in tool |

## 3. Decisions

| # | Decision | Recommendation |
|---|---|---|
| D1 | Workload names on existing installs | **Install-level profile** `kiln`/`falak` (per-server breaks multi-server sites; migrating everything needs downtime). Opt-in `falak-ctl rename-workloads` after v1.0. |
| D2 | Linux user `kiln` on provisioned servers | **Keep** (running pools/programs block `usermod`; SSH configs, keys, sudoers name it). Fresh installs: `falak`. |
| D3 | Redis/Valkey instance names | **Keep** (D1; prefix recorded in agent state; no data moves). |
| D4 | Compose project, volumes, Postgres db/user, Redis prefixes on migrated installs | **Pin** via `.env` (old kiln-ctl rollback hard-codes them). |
| D5 | `/opt/kiln` | **Move** to `/opt/falak` during `falak-ctl update` + symlink until v1.0; only CP entries when the agent shares it. |
| D6 | Telemetry | **Rename + agent translation + panel dual-read**; metric charts reset, old series in Grafana Explore. |
| D7 | Go module | **`github.com/OthmanHaba/falak/agent`**. |
| D8 | Token prefixes | New tokens `fbt_`/`ffn_`; old stay valid. |
| D9 | GHCR `kiln-*` | **Keep all existing tags forever**; no double-publish of v0.8+; make `falak-*` public before release. |
| D10 | Version | **v0.8.0 bridge**, v1.0.0 clean break. |
| D11 | `falak.sh` one-liner | Cloudflare redirect rule (curl/wget UA) → `https://github.com/OthmanHaba/falak/releases/latest/download/install.sh`, plus static `/install.sh` on the website as fallback. |
| D12 | When a CP host migrates | at the next update or `falak-ctl migrate`, never from cron. |
| D13 | Agent dirs | **Move + symlinks** with journal and shell rescue unit. |

## 4. Execution

### 4.1 Phases (each ends green: Pest incl. `packages/apm-laravel`, `go test ./...` + vet, typecheck + build, pint, eslint, shellcheck, `deploy/tests/*.sh`)

| Phase | Content | Size |
|---|---|---|
| P0 Spikes + guardrails | nested compose interpolation on spinta's compose version; `Conflicts=` handover, symlinked `StateDirectory=`, rename-with-symlink of `/etc/kiln` under a running agent (sim container); GitHub rename behaviour on a scratch repo (release downloads, `releases/latest` API, raw URLs, GHCR visibility); `tools/rebrand/rename.py` + `check.sh` | 1–1.5 d |
| P1 Mechanical rename | one generated commit (contents + 106 `git mv` + autoload + `go mod tidy` + build + regenerated dashboards); fallout fixes in a separate commit | 1.5–2 d |
| P2 Compat seams | PHP `Brand`, `LegacyEnv`, alias autoloader, artisan aliases; Go `internal/brand`; bash helpers; `legacy:kiln` marker + allowlist | 1 d |
| P3 CP compat | dual env emission; macros; reserved prefixes; placeholders; webhook headers; tokens; deploy hooks; agent-session header; DecisionEngine; `kernel_settings` + naming migration; enroll `naming`; `falak.v1`; listeners (firewall, builder); Grafana; dual log/trace queries; storage move; localStorage copy | 3–4 d |
| P4 Agent compat + layout migration | `brand.Env`; dual labels/headers; legacy markers; rename-on-converge; nft preamble; OTLP translation; D1 naming in redis/fpm/swap/fngateway; `internal/legacy` (migration, handover, unit conversions, rescue, `/run/kiln`); facts; install script dual detection — tested on re-rooted fixtures of a v0.7.1 host tree | 4–6 d |
| P5 ctl / installer / release | `falak-ctl` (resolution, migrate, revert, dual backups, pruning, v1.0 guard); `kiln-ctl` shim; compose nested defaults; `install.sh`; release assets in both names; `install-cli.sh` | 2–3 d |
| P6 Packages + runtimes | `falak/apm-laravel`, `@falak/apm-node`, fn images with dual commands/env/labels | 1–2 d |
| P7 Docs + website | docs (history policy), `docs/UPGRADING_TO_FALAK.md`; website rename, `SITE_URL=https://falak.sh`, logo + Arabic name, redirects, "Kiln is now Falak" post, env reference with aliases, `FALAK_REPO_PATH` | 2 d |
| P8 Verification | §4.3 | 3–5 d |
| P9 Rollout | rc → repo rename → v0.8.0 → spinta → announcement | 1 d + soak |
| P10 (v1.0) | drop "v1.0" aliases, symlinks, legacy assets; shrink allowlist | 1–2 d |

### 4.2 Mechanical rename (`tools/rebrand/rename.py`, committed)
- Input `git ls-files`; exclude vendor, node_modules, lock files (regenerate), binaries, `.gitleaksignore`, builder
  fixture lockfiles. Preserved history (no content change, one header line "Written for Kiln, renamed Falak in
  v0.8.0"): `docs/plans/{COMPOSE_APPS,FUNCTIONS,MACHINE_CHECK,REDIS}.md`.
- Ordered map: `github.com/kiln/agent` → `github.com/OthmanHaba/falak/agent`; `OthmanHaba/kiln-website` →
  `OthmanHaba/falak-website`; `OthmanHaba/kiln` → `OthmanHaba/falak`; `https://kiln.dev` → `https://falak.sh`; then
  `KILN`→`FALAK`, `Kiln`→`Falak`, `kiln`→`falak`.
- Paths: same map, `git mv` deepest first; the shim `deploy/kiln-ctl` is re-added in P5.
- Migrations: the script refuses to change string literals in `*/database/migrations/*` (report must show zero).
- Guard `tools/rebrand/check.sh` (CI from P1): `git grep -n -i kiln` empty except lines with `legacy:kiln` and files in
  `tools/rebrand/allowlist.txt`.

### 4.3 Verification
1. Unit/feature tests for both spellings of every alias (Pest + Go), incl. the alias autoloader unserialising a
   `Kiln\…` job and the agent migration + rescue on a re-rooted v0.7.1 tree.
2. `docker compose config` with a v0.7.1 `.env`, a migrated `.env` and a fresh `.env` (images, volumes, db, prefixes).
3. Live upgrade on EC2 (the user prefers EC2 over the sim; free-tier machines, deleted afterwards): CP v0.7.1 + v0.7.1
   agents with a PHP site, Docker site, compose site, scheduled function, Redis instance with keys + AOF, WireGuard
   network, firewall rules → CP to v0.8.0 (old `kiln-ctl update`, then rollback test, then migration) → both env
   prefixes present, old-agent logs/traces visible → "Update all agents" → `falak-*` units active, `inet falak` only,
   same agent id/cert serial, Redis keys intact on `redis-server@kiln-*`, FPM socket unchanged, `X-Kiln-Key` and
   `X-Falak-Key`, `/run/kiln/otlp.sock`, rollback to a `.kiln-release.json` release; forced rescue on one server.
4. Installer re-run on a v0.7.1 install migrates instead of reinstalling; fresh v0.8.0 install has falak names
   everywhere.
5. External: v0.7.1 `kiln` CLI against v0.8.0; new `falak` CLI with only `~/.config/kiln`; `releases/latest/download/
   kiln-linux-amd64` after the rename; webhook receiver verifying `X-Kiln-Signature`.
6. spinta after the rc soak: backup → update → agents one by one → migrate in a quiet window.

### 4.4 Rollout
1. Merge P1–P7 (compat always on, no flags).
2. `v0.8.0-rc.N` as GitHub **prereleases** (old `kiln-ctl update` follows `releases/latest`, so users are safe).
3. Make `falak-*` GHCR packages public; verify an anonymous pull.
4. Rename repos; **never recreate `OthmanHaba/kiln`**; re-check external clients.
5. Tag `v0.8.0` ("Falak v0.8.0 (formerly Kiln)"); upgrade spinta.
6. When DNS is live: Cloudflare redirect for `falak.sh`, website `SITE_URL`, switch documented one-liners.

## 5. Risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | Nested compose interpolation unsupported | P0 spike; drop `:?`, validate in falak-ctl |
| R2 | GitHub redirects behave unexpectedly / someone recreates `OthmanHaba/kiln` | P0 scratch test; never reuse the name; `FALAK_REPO`/`KILN_REPO` override |
| R3 | Old kiln-ctl rollback leaves the shim in place | shim falls back to `/opt/kiln/deploy/kiln-ctl` |
| R4 | Fleet CA / pg-data lost via project/volume rename | legacy-valued defaults + pins + compose-config test |
| R5 | Orphaned queue jobs | prefix pins + alias autoloader |
| R6 | Agent handover disconnects a host | detection before polling, journal, legacy fallback, shell rescue, batch size 1 |
| R7 | CP host is also a managed server | falak-ctl moves only CP entries; test with an agent on the CP host |
| R8 | ~1 s edge restart, tunnel blip | during the operator-triggered agent upgrade; documented |
| R9 | Loki label cardinality doubles in the window | index both only until v1.0 |
| R10 | Mechanical side effects (sort order, +1 char on 32-char user names, 15-char ifaces, 40-char Grafana uids, golden files) | fallout commit; targeted length tests (`db/redis.go:105-110`, `CreateSite.php:316`) |
| R11 | Users' CI/receivers on `kiln-*` assets and `X-Kiln-*` | dual publish/emit until v1.0, announced |
| R12 | Users' existing `FALAK_*` variables become reserved | migration report + panel warning |
| R13 | Grafana duplicates / bookmarks | provisioning deletes legacy uids; release note |
| R14 | Never-upgraded agents | outdated-agent banner; v1.0 refuses while agents <0.8 (`--force`) |

## 6. Website (`kiln-website` → `falak-website`)
Same rename script (`KILN_REPO_PATH` fallback); keep `introducing-kiln.mdx` as history with a banner; rename
`operations/kiln-ctl.mdx` → `falak-ctl.mdx` with Astro redirects; new logo asset; `SITE_URL=https://falak.sh`, GitHub
links; `reference/environment-variables.mdx` documents `FALAK_*` with a "formerly `KILN_*`, accepted until v1.0"
column; static `public/install.sh` copied in `postbuild` (D11 fallback); `/upgrading-from-kiln` page.
