# Production hardening and preview environments (plan, v0.10.0)

v0.10.0 is the release that makes Falak safe to trust with production. It adds:

- point-in-time recovery for PostgreSQL and MySQL/MariaDB;
- encrypted backups with automatic restore drills;
- volumes as a first-class, managed thing;
- rollback when a release goes bad after it is live;
- resource limits for every service;
- alerts for everything that can hurt a user;
- a server security baseline with one-click fixes;
- a real secret store with external providers;
- disaster recovery that Falak asks the user to set up;
- preview environments for every pull request.

Status: **draft, awaiting approval.** Decisions (2026-10-06):

- The version is v0.10.0.
- **Every managed database runs in a container** (Postgres, MySQL, MariaDB, Redis, Valkey). Engines installed on the
  host are removed.
- **No backward compatibility.** Nobody runs Falak in production yet:
  - no migration shims;
  - no feature flags per step;
  - no fallback casts;
  - the agent must match the control plane's version.
- Point-in-time recovery covers PostgreSQL (wal-g) and MySQL/MariaDB (physical base backups with xtrabackup or
  mariabackup, plus binlog).
- Secret providers: Vault/OpenBao, AWS (Secrets Manager and SSM), a **generic HTTP** provider (no command
  provider), and native 1Password, Doppler and Infisical.
- Rollback after a release goes live is **opt-in**, suggested for production services.
- Previews use a **configurable preview domain** (Settings → Previews). It is backed by a DNS provider integration
  (Cloudflare first), which creates the wildcard record and the DNS-01 certificate. Falak Cloud uses
  `*.prv.falak.sh`, configured by the owner through Falak Cloud's own settings. Self-hosted installs set their own.
- The database a preview starts with is chosen per project.

## What exists today (survey, 2026-10-06)

| Area | Today | Gap |
|---|---|---|
| DB backups | Logical dumps (`pg_dump -Fp`, `mysqldump --single-transaction`) and Redis RDB, sent to a storage provider (S3, R2, B2, Spaces or MinIO) over presigned URLs. Servers never hold storage credentials. SHA-256 checked, retention by count and days. | No PITR, no encryption (only gzip or none), no drills |
| Engines | Host packages run as systemd units (Postgres, MySQL, MariaDB, Redis/Valkey) | — |
| Secrets | Laravel `encrypted` casts keyed by `APP_KEY`, spread over 6 models. Versioned site env, reveal is audited, the audit log redacts. | No store, no providers, a single key, plaintext `.env` on servers, deploy and hook output not masked |
| Volumes | Compose named volumes parsed from YAML; `shared_paths` for classic sites; read-only strip on the canvas card | No model, sizes, backups, attach/detach or UI |
| Rollback | Automatic when a deploy step fails (compose, swap, release, functions); manual rollback in the UI and API | Nothing watches a release *after* it went live |
| Limits | The agent already supports `Memory`/`NanoCPUs` in `docker.run` and swap; Functions and workers have memory limits | The CP never sends limits for sites, compose or processes |
| Alerting | Rules, severity, dedup and quiet hours; channels email, Slack, Discord, Telegram and webhook; around 18 events | No disk, memory or CPU alerts, no certificate expiry (`not_after` is stored), no missed backups, no restore failure, no CP backup alerts |
| Servers | SSH hardening, fail2ban, unattended-upgrades, nftables; machine check reads sshd, firewall, ports and packages | No report, score, schedule or fixes |
| CP backups | `falak-ctl backup` (pg, CA, storage, caddy-data, env), optional AES passphrase, S3 upload; `update` backs up first | Not scheduled, restores from local files only, misses `edge-pki`/registry, nothing in the UI |
| Previews | Environments can be forked (`CreateEnvironment` with `$from`, duplicates sites) | Webhooks are push only; no pull request events, comments or commit statuses |

## Build order

All on `release/v0.10.0`, one PR per step, each tested on real servers before the next starts.

| # | Step | Depends on |
|---|---|---|
| 1 | Secret store and providers (A9) | — |
| 2 | Volumes (A3) | — (backups get encryption in 4) |
| 3 | Database containers | 1 (passwords), 2 (data volumes) |
| 4 | Backup encryption and restore drills (A2) | 1 (keys), 3 |
| 5 | Point-in-time recovery (A1) | 3, 4 |
| 6 | Resource limits (A5) | 2 |
| 7 | Rollback after a release went live (A4) | 6 (OOM and crash signals) |
| 8 | Alerts coverage (A6) | 1–7 (their events) |
| 9 | Server baseline and fixes (A8) | 8 |
| 10 | Disaster recovery (A10) | 1, 2, 4 |
| 11 | Preview environments (B1) | 1, 2, 3, 5, 6, 7 |

**Agent ↔ CP versions.** From v0.10.0, the CP refuses to send commands to an agent whose version differs from its
own, and shows "Update the agent on <server>" with a one-click upgrade. So the steps below need no feature flags
and no `PayloadCompatibility` entries. Schemas still gain new commands and the contract tests still enforce them.

---

## 1. Secret store (A9)

### Goals
Secret values are unreadable at rest, in transit, in logs, in backups and on server disks wherever we can manage it.
Every read is attributed. Rotation is a button.

### Key hierarchy (envelope encryption)
- **Key-encryption key (KEK).** It is *not* `APP_KEY`. It comes from a **KEK provider**:
  - `local` (default): `/opt/falak/secrets/kek`, 32 random bytes, mode 0400, outside the database and outside
    `.env`, mounted read-only into the app containers only.
  - `aws-kms`, `vault-transit`: the KEK never leaves the KMS.
- **Data key per organisation.** Wrapped by the KEK and stored in `secrets_keys`; key rotation re-wraps it.
- **Secret values.** AES-256-GCM under the org data key. The AAD binds the ciphertext to org, secret id and version,
  so a row copied elsewhere fails to decrypt.
- **Emergency kit.** At install and on every KEK rotation the user downloads a recovery file and confirms they stored
  it. Without the KEK, a database backup holds no readable secrets (see step 10).
- The existing `encrypted` casts on `EnvironmentVersion`, `Deployment`, `Release`, `Daemon`/`Worker`, `DatabaseUser`,
  `StorageProvider` and `Command` move to a `SecretCast` on the same envelope. One migration re-encrypts them during
  the upgrade, with no fallback to the old cast.

### Model (new `Secrets` module)
- `secrets_secrets`: org, scope (org, project, environment or service), name, `kind` (`managed` | `linked`), flags
  `sensitive` (write-only after save), `available_to_previews` (off by default), rotation policy.
- `secrets_versions`: immutable; ciphertext, created_by, created_at, `disabled_at`. Rollback is to any version.
- `secrets_providers`: type and encrypted connection config; health status.
- `secrets_access_log`: who or what read which version, and why (deploy #, reveal, API token). Kept apart from the
  general audit log, with a longer retention.

### Linked secrets (providers)
A `linked` secret stores a **reference**, not a value: `vault://kv/data/app#DB_PASS`, `aws-sm://prod/db#password`,
`op://Vault/Item/field`, `doppler://project/config/KEY`, `infisical://project/env/path/KEY`, `https://…`.

| Provider | Auth | Notes |
|---|---|---|
| Vault / OpenBao | AppRole, token, JWT | KV v2; also usable as the KEK provider (transit) |
| AWS | access keys or role (instance profile when the CP runs on AWS) | Secrets Manager and SSM Parameter Store; KMS as KEK provider |
| 1Password | Connect server (URL + token) | service account tokens need the SDK/CLI: run Connect |
| Doppler | service token | |
| Infisical | universal auth (client id and secret), self-hosted URL | |
| Generic HTTP (webhook) | bearer or header auth, mTLS optional, HTTPS only | `GET {url}?ref=` returns `{ "value": "…" }` (same model as External Secrets' webhook provider); contract in docs |

- Resolution happens **at deploy time on the control plane**. The value is cached encrypted, with a TTL; if the
  provider is unreachable, the last good value is used and an alert fires.
- Optional **watch**: poll every N minutes. On a change, create a new version and, per secret, choose: do nothing,
  restart the services that use it, or redeploy them. The version keeps the reference plus a sealed snapshot of the
  value (only an HMAC of the last value is kept for change detection); rolling back to it pins the secret to that
  value until a new reference is saved. Details: docs/SECRET_PROVIDERS.md.
- References in variables: `${{ secrets.NAME }}`, alongside the existing `${{ service.KEY }}`. A plain variable can be
  promoted to a secret in one click.

### On servers
- **Classic sites.** `.env` moves to tmpfs: `/run/falak/env/<site>.env` (0440, site user and site group: the edge user
  runs PHP under FrankenPHP). The release's `.env` becomes a symlink to it. After a reboot the agent reports the site in
  its heartbeat (`missing_secrets`) and the CP re-sends it (`site.env.write`), so secrets are never written to
  persistent disk. *(Built in step 1d.)*
- **Containers.** Per site, choose env vars (default) or **files** (`/run/secrets/NAME`, tmpfs bind; 0444 in a 0555
  directory under a 0700 root-only parent, or 0400 owned by a numeric container user), which `docker inspect` does not
  show. Compose projects keep env vars for now. *(Built in step 1d.)*
- **Masking.** Each command payload lists the *names* of its secret variables (`mask`); the agent takes the values from
  the same payload (or the site's env file for hooks), so no extra copy is ever sent. It replaces exact occurrences,
  plus base64 and URL-encoded forms, with `••••` in deploy, hook, build and command output before anything leaves the
  server. The CP deployment log applies the same filter again. Until the store exists, names are matched against
  `sites.secret_variables` (one config list; the store's `sensitive` flag replaces it). *(Built in step 1d.)*

### UI
- A Secrets page per project and environment: list, version history, diff of metadata (never values), last
  accessed, the services that use each secret, rotate, roll back.
- Reveal needs a recent re-authentication (2FA if enabled) and is logged.

## 2. Volumes (A3)

New `Volumes` module, so every piece of persistent data on a server is visible and managed. That includes database
data from step 3.

### Model
`volumes_volumes`: server, name, `kind`, `size_limit_bytes` (nullable), `used_bytes`, `protected`, `labels`.
`kind` is one of:
- `docker`: named volume;
- `sized`: an ext4 image file loop-mounted under `/var/lib/falak/volumes/<id>`, which gives a hard size limit that
  can be grown online. This is the default for database data.
- `bind`: a host path, admin-only, from an allowlist;
- `shared_path`: classic sites, replacing `sites.shared_paths`.

`volumes_attachments`: volume ↔ service (site, compose service or database), mount path, read-only flag.

Compose named volumes are created as `Volume` rows when the stack is deployed. `shared_paths` becomes volumes of
kind `shared_path`. The column is dropped, with no import step.

### Operations (UI and API)
- Create, attach and detach. Attaching to a running service redeploys it.
- Resize (grow `sized` volumes online with `resize2fs`; shrinking is refused).
- Usage graph and alert thresholds.
- A read-only **file browser**: list, search, and download one file or a folder as `.tar.zst`. Admin and owner only,
  audited, capped in size. Database volumes are not browsable; they use backups.
- **Backups.** Scheduled, encrypted (step 4), retained and restored like databases. The archive is a consistent
  `tar | zstd` snapshot; the consistency mode can be `none`, `pause` (docker pause for seconds) or `stop`.
- **Clone** to a new volume on the same or another server (used by previews).
- **Move** to another server (backup, restore, re-attach, redeploy).
- Delete, with protection: protected volumes can't be deleted, and others ask the user to type the name. "Delete
  volumes" on service delete becomes opt-in per volume.

### Canvas
Volumes are shown as small disks attached to service cards, with used/limit. Clicking one opens its page.

## 3. Database containers

Every managed database is a container. **Removed:**
- installing engines during provisioning (`databases` and `cache` server components);
- `EngineInventory`, `InstallDatabaseEngine` and the "install Redis on this server" flow;
- `redis-server@` instances and `/etc/falak-redis`;
- "container access" to host engines (`EnableContainerAccess`, `db.containers`);
- the machine check's engine adoption rules.

Every server gets Docker; a server whose role was "database" is just a server.

### Images
Built in this repo (`images/db/<engine>/`), published by CI as `ghcr.io/othmanhaba/falak-<engine>:<major>` and
`:<major>-falak<release>`. They are multi-arch (amd64 and arm64), scanned with Trivy (a high or critical CVE fails
the build), and signed with cosign; the agent verifies the signature before pulling.

| Engine | Versions | Base | Added tools |
|---|---|---|---|
| PostgreSQL | 15, 16, 17, 18 | `postgres:<v>-bookworm` | wal-g, `falak-db` helper |
| MySQL | 8.0, 8.4 | `mysql:<v>` | Percona XtraBackup (matching version), `falak-db` |
| MariaDB | 10.11, 11.4 | `mariadb:<v>` | mariabackup (already in the image), `falak-db` |
| Redis | 7.4, 8 | `redis:<v>` | `falak-db` |
| Valkey | 8.1 | `valkey/valkey:<v>` | `falak-db` |

`falak-db` is a small Go binary (built from `agent/cmd/falak-db`). It is the one entry point for backups, WAL and
binlog spooling, health checks and config rendering inside the container, so the agent drives everything with
`docker exec <ctr> falak-db <op>`, never by building shell strings.

### Model
- `DatabaseServer` → **`DatabaseInstance`**: one container. Fields: server, engine, version, image digest, port,
  volume, limits, settings, `pitr_enabled`, status.
- `Database` and `DatabaseUser` stay, *inside* an instance. A canvas database service is one instance with one
  default database and user, as on Railway, and more can be added.
- Tables are recreated in a fresh migration set for the module. There is no data migration.

### Runtime
- **Container** `falak-db-<instance>`:
  - restart `unless-stopped`;
  - data on its volume (step 2);
  - config rendered from settings and **tuned to the memory limit** (Postgres `shared_buffers` = 25%,
    `effective_cache_size` = 75%; InnoDB buffer pool = 50–60%; Redis `maxmemory` = 80%);
  - `stop_grace_period` 60 s, so the engine shuts down cleanly.
- **Docker daemon:** provisioning sets `live-restore: true`, so restarting dockerd or upgrading Docker leaves
  databases running.
- **Network:**
  - The instance joins the project's private Docker network, where apps reach it by name (`DB_HOST` =
    `falak-db-<instance>`).
  - For other servers, it publishes on the server's private or WireGuard address only.
  - **Public access** is off by default. When on, it requires TLS with a Falak CA or ACME certificate, plus a
    firewall allowlist.
- **TLS** is enabled inside the container by default, with certificates from the Falak CA.
- **Health:** `falak-db health` (pg_isready / mysqladmin ping / PING) runs as a Docker healthcheck, and its status
  is reported in heartbeats.
- **Version upgrades:**
  - Minor upgrades pull the new digest and restart.
  - **Major upgrades** run in a *new* instance, through dump/restore or `pg_upgrade --link` in a helper container,
    then switch over. The old instance is kept for 24 h.
- Logs go through the existing log shipper (Loki), and slow query logging is on by default.

### Agent
`db.*` commands now act on instances:
- `db.instance.create|update|delete|restart|upgrade`;
- `db.create|drop`;
- `db.user.*`;
- `db.backup` and `db.restore`, which call `falak-db` in the container. Their result format is unchanged.

Redis/Valkey commands (v0.9.0) move to the same path.

### Compose apps
A compose `postgres`/`mysql`/`mariadb`/`redis`/`valkey` service can still become a managed instance. Extraction now
creates a Falak instance container instead of a host instance.

## 4. Backup encryption and restore drills (A2)

- **Encryption is always on.** There is no unencrypted option, because nothing needs compatibility.
  - `falak-db` streams `dump → zstd → AES-256-GCM` (chunked STREAM construction, 64 KiB segments, so it works on
    files of any size and detects truncation).
  - A random **data key per backup**, wrapped by the org's backup key held in the secret store. The agent receives
    the unwrapped key only inside the encrypted command payload, and only in memory.
  - **Customer-held key (optional, per schedule).** The user supplies an `age` public key. The CP can never decrypt
    those backups; a restore asks for the private key, or works offline with `falak-restore`, a small static binary
    published with releases.
- **Header.** Version, cipher, wrapped key id and plaintext SHA-256, so the agent verifies after decrypting.
- **Compression.** zstd replaces gzip.
- **Restore drills.** Per schedule: off, weekly (default for production environments) or monthly.
  - The agent starts a **temporary instance** (same image and version, small limits, a throwaway volume) and
    restores the latest backup into it. Checks: it restores cleanly, has tables, the row counts of the 10 largest
    tables are within ±X% of the source, plus an optional user SQL check. The instance and volume are then
    deleted.
  - The result (`passed`, `failed`, duration, RTO estimate) is shown on the backup with a "verified" badge, and a
    failure alerts.
  - If the server is too small for the drill, the user can pick a "drill server".

## 5. Point-in-time recovery (A1)

PITR is enabled **per instance**, and on by default for instances in production environments. Every instance keeps a
**base backup** plus a **continuous log**, both encrypted as in step 4 and stored with the storage provider.

### Shipping without storage credentials on servers
`falak-db` writes WAL segments and closed binlogs into a spool directory on the instance's volume
(`/var/lib/falak/db/<id>/spool`, so it is size-limited with the data). The agent ships the spool in batches,
asking the CP for presigned PUT URLs over its existing channel (`pitr.upload_urls`). If the CP is unreachable the
spool grows; there is an alert when it passes 20% of the volume, and the agent never deletes unshipped segments.
Target RPO: **≤ 60 s**, which we enforce with `archive_timeout = 60` (Postgres) and a 60 s binlog rotation.

### PostgreSQL (wal-g)
- Image config: `wal_level=replica`, `archive_mode=on`, `archive_command='falak-db wal-push %p'` (wal-g with a file
  target pointing at the spool, encryption applied by `falak-db`). It's set at creation, so no restart is needed
  later.
- Base backups: `wal-g backup-push` (physical), weekly by default, plus one immediately when PITR is enabled.

### MySQL / MariaDB (physical + binlog)
- Image config: `log_bin`, `binlog_format=ROW`, `binlog_row_image=FULL`, `sync_binlog=1`,
  `innodb_flush_log_at_trx_commit=1`, a unique `server_id`, and GTID on (MySQL `gtid_mode=ON`, MariaDB GTID native).
- Base backups: `xtrabackup --backup --stream=xbstream` (MySQL) or `mariabackup --backup --stream=xbstream`
  (MariaDB), physical and without locking InnoDB, weekly by default.
- `falak-db` rotates the binlog every 60 s (`FLUSH BINARY LOGS`) and spools the closed files.

### Restore to a time T (both engines)
Because an instance is one container, a restore **never touches the running database until the user confirms**:
1. Create a **new instance** (same image) on a new volume. Fetch the latest base before T through presigned GET
   URLs, then replay to T:
   - Postgres: `wal-g backup-fetch` + `recovery_target_time`.
   - MySQL/MariaDB: `xtrabackup --prepare`, then `mysqlbinlog --stop-datetime=T | mysql`.
2. It starts **read-only** for inspection. The UI shows connection details and row counts, and the user can query it.
3. The user picks one of:
   - **Swap**: the new instance takes over the name, port and network alias, and the services that reference it
     restart. The old instance is kept stopped for 24 h.
   - **Keep as a new database**: it appears on the canvas.
   - **Discard.**

The same flow restores a normal (non-PITR) backup.

### Retention and UI
- The PITR window is set in days (default 7). Bases and log segments older than the window, beyond the newest base
  that covers it, are pruned.
- The UI shows a **timeline** of available recovery points with gaps highlighted, and a time picker.
- RPO and lag are shown live ("last segment shipped 12 s ago").

## 6. Resource limits (A5)

- Per service (site, container, compose service, **database instance**, worker or daemon): `memory_limit`, `memory_reservation`, `cpus`,
  `pids_limit`, `restart_policy` and max restarts, `log_max_size`/`log_max_files`, and OOM behaviour.
- Delivery:
  - **Docker sites and database instances:** `HostConfig` (the agent already supports it; the CP starts sending
    it). Database configs are re-tuned whenever the memory limit changes (step 3).
  - **Compose:** a generated `compose.falak.yml` override with `deploy.resources.limits`, `mem_reservation` and
    `logging`.
  - **Classic sites, PHP-FPM, workers and daemons:** a systemd slice per service, `falak-<svc>.slice`, with
    `MemoryMax`, `MemoryHigh`, `CPUQuota` and `TasksMax`. The supervisor launches each process inside it.
- Defaults per environment: production limits are unset unless the user sets them; previews get small defaults.
- **Capacity view per server:** the sum of reservations and limits against RAM and CPU, with a warning when overcommitted.
- Disk is limited through `sized` volumes (step 2) and log caps.
- OOM kills and restart counts are reported as events (they feed steps 7 and 8).

Implementation notes (built on `feat/v010-limits`):

- A `Limits` module owns the `ResourceLimits` value, validation against the agents' facts, defaults per environment
  (`config/limits.php`), the capacity view (`GET /servers/{server}/capacity`) and `ServiceOomKilled` /
  `ServiceRestartLoop` (alertable). Sites store `limits` / `compose_limits`, Processes a `limits` column on workers and
  daemons; database instances and functions keep their own memory / CPU settings and are only counted.
- Slices are keyed `falak-site_<slug>.slice`, `falak-worker_<id>.slice`, `falak-daemon_<id>.slice` (a dash would nest
  a slice in another). proc.apply carries the full set; the agent writes the units and applies changes with
  `systemctl set-property --runtime` (live). A supervised program in a slice starts as `systemd-run --scope
  --slice=… -- setpriv --reuid --regid --init-groups -- <cmd>` (same pid and process group; `OOMPolicy=continue` on
  systemd ≥ 253). A site's web process, Octane and Horizon share the site's slice.
- **PHP-FPM:** one master's pools are its forked children in its cgroup, so a pool can't be limited on its own. A
  PHP-FPM site with memory, CPU or process limits runs its pool in its own master, `falak-fpm-<slug>.service`
  (`Slice=` the site's slice, same socket path); without limits it stays in the shared `phpX.Y-fpm`. FrankenPHP sites
  run inside the shared edge and only take restart / log / OOM settings.
- Compose limits go into the generated `compose.falak.yaml` (both `mem_limit`/`cpus`/`pids_limit` and their
  `deploy.resources` twins, which Compose requires to agree), written by the control plane per deploy.
- Defaults are written on a service when it is created outside production (a site placed in a non-production
  environment, a new worker or daemon) and validated with it; nothing merges defaults at runtime, so an upgrade or a
  changed default never caps existing services. An invalid slice is skipped by the agent and reported
  (`slice_errors`), never the whole proc.apply.
- Agents report `service_events` in heartbeats: Docker `oom` events, restart-count increases of managed containers,
  slices' `memory.events` `oom_kill` and supervised programs' restart counters, each delivered once.

## 7. Rollback after a release goes live (A4)

Rollback when a deploy step *fails* already exists. New: a **watch window** after a successful deploy (default 5
minutes, configurable per service, off for the first deploy).

- **Triggers** (each can be switched on or off):
  - health check failing N times in a row through the edge;
  - crash loop or OOM kill (step 6);
  - the 5xx rate in the edge access log exceeding `max(baseline × 3, 5%)`, where the baseline is the previous
    release's last hour;
  - a new Insights issue at error level or higher (opt-in).
- **Action:** the existing rollback path (`Trigger::Rollback`) to the previous active release, with deployment status
  `rolled_back` plus the reason. Alert and canvas badge.
- **Loop guard:** no automatic rollback to a release that was itself rolled back; at most one automatic rollback per
  hour per service.
- **Migrations:** database migrations are *not* reversed. If the deploy ran migrations, the UI and the alert say
  so, and the user can choose "alert only" for those services.

## 8. Alerts coverage (A6)

New event sources, and a **default rule pack** created for every org (editable, can be switched off), routed to the
org's default channel. New orgs are prompted to add a channel.

| Area | Events |
|---|---|
| Server | disk > 80/90%, **disk full forecast < 48 h** (linear fit over 6 h), memory > 90% for 10 min, CPU > 90% for 15 min, load > cores × 2, swap thrash, reboot required, agent outdated |
| Certificates | expiring in 14, 7 and 1 days; renewal failed |
| Backups | failed, **missed** (no success within 2× the interval), drill failed, restore failed, storage provider unreachable |
| PITR | archive lag > 5 min, spool > 20% of disk, gap in the timeline |
| Deploys | auto rolled back, watch window warning, failed |
| Services | OOM kill, crash loop, restarts > N/hour, volume > 85% |
| Databases | connections > 80% of max, replication/PITR stopped |
| Secrets | provider unreachable, linked value changed, rotation due, unusual reveal activity |
| Security | baseline score dropped, new critical finding, new open port |
| Control plane | backup missing or failed, DR not configured (reminder), drill failed |

Each alert links to the page where it can be fixed, and carries a "suggested fix" action where one exists (for
example "grow volume", "fix in baseline").

## 9. Server baseline report (A8)

- New agent command `security.audit`, run daily and on demand. It returns checks as `{id, title, status
  (pass|warn|fail|info), severity, evidence, fix_id?}`.
- **Score** out of 100 per server, a history graph, and a "Production ready" badge when there are no failing checks
  of high severity or above.

### Checks (first set)

| Area | Check | One-click fix |
|---|---|---|
| SSH | root login by password, password auth, empty passwords, `PermitRootLogin`, unknown authorized keys | yes (edit `50-falak.conf`, validated with `sshd -t`, rolled back on failure) |
| Updates | pending security updates, unattended-upgrades on, reboot required | yes (install now / enable / schedule a reboot in a window) |
| Firewall | nftables default deny active, unexpected listening ports on public interfaces, Docker publishing around the firewall | yes (enable / close the port / bind to loopback) |
| Intrusion | fail2ban active with the sshd jail | yes |
| Docker | daemon on TCP without TLS, containers with `--privileged`, docker.sock mounted into containers | partial (TCP: yes; others: shows the service) |
| Files | `.env` and secret files permissions, world-writable files in site roots, unattended `/tmp` executables | yes for permissions |
| Kernel | key sysctls (`rp_filter`, `accept_redirects`, `tcp_syncookies`, `kptr_restrict`) | yes |
| Accounts | users with UID 0, sudoers with NOPASSWD outside Falak, shell users not known to Falak | info only |
| Time | NTP sync | yes |
| Backups | databases without backups, backups unencrypted, no drill | link to the page |

### Fixes
`security.fix {fix_id}`.
- Each fix is idempotent and saves a backup of every file it touches.
- It can be undone for 7 days.
- It runs only from an **allowlist compiled into the agent**. The CP can't send arbitrary shell commands.
- "Fix all safe" applies every fix marked non-disruptive. The others, such as reboots and SSH changes, ask one by one.


## 10. Disaster recovery (A10)

### Control plane
- **Scheduled backups.** The installer and `falak-ctl dr setup` install a systemd timer (default every 6 h). Upload
  to S3 is required for "configured" status, and encryption is required whenever there is an upload.
- **Coverage.** Backups now also include `edge-pki` and the secret store KEK, *wrapped with the DR passphrase*.
  Without the passphrase the backup is useless to a thief. The registry can be included as an option.
- **Restore.** `falak-ctl restore s3://latest` (or a named backup) works on a fresh host: install, restore, and the
  agents reconnect because the CA and agent tokens are in the backup. A runbook in `docs/DISASTER_RECOVERY.md`.
- **CP drill.** `falak-ctl dr drill` restores the latest backup into a throwaway compose project on other ports,
  runs `doctor` against it, then tears it down. It can run monthly from the timer and reports to the UI.
- **UI.** Settings → Disaster recovery: last backup, age, size, last drill, and the configuration (which writes
  `custom.env` through an agent-free helper on the CP host).
- **The prompt.** Until DR is configured there is a persistent banner for owners and admins, an item on the
  dashboard checklist, and a weekly reminder alert. A dismissal hides it for 30 days, then it comes back.
  `doctor` reports it as a warning.

### User workloads
- **Server recovery wizard** ("This server is gone"):
  1. pick or create a replacement (provider API or a new SSH server);
  2. Falak reprovisions it;
  3. restores the databases (latest or PITR) and volumes;
  4. redeploys every service that was on the old server to the new one;
  5. moves domains.
- Each step can be retried. A dry-run shows the plan and the data loss estimate per database.
- A **DR readiness score** per project: is every database backed up (and encrypted, and drilled), every volume
  backed up, PITR on for production databases? It links to fix each gap.

## 11. Preview environments (B1)

### Source control
- **GitHub (app and hooks):** add the `pull_request` events (opened, synchronize, reopened, closed), commit statuses
  (or check runs with the app) and PR comments.
- **GitLab:** `merge_requests_events`, MR notes and commit status.
- **Bitbucket:** `pullrequest:*`, comments and build status.
- New events: `PullRequestOpened`, `PullRequestUpdated` and `PullRequestClosed`.

### Project settings ("Previews")
- **Enabled**, a **base environment** (usually staging), the **services** to include (others are shared from the
  base environment or left out), and the **server** to run on (default: the base environment's servers).
- **Domain** pattern `pr-{number}-{service}.{preview_domain}`. The preview domain is an instance setting (Settings →
  Previews: domain, DNS provider connection, target edge server), not hard-coded.
  - With the Cloudflare integration, Falak creates the `*.{preview_domain}` record pointing at the preview edge and
    gets a wildcard certificate through DNS-01.
  - Other DNS providers: the user adds the wildcard record themselves, and certificates come from on-demand TLS
    restricted to live preview hostnames.
  - Falak Cloud: `prv.falak.sh` through Cloudflare, configured in the UI by the owner.
- **Database strategy**, per project:
  1. **empty**: create the database, then run migrate and seed commands;
  2. **clone latest backup** of a chosen environment (restored from the newest backup, so production is not loaded);
  3. **clone production + sanitize**: restore and then run a user SQL or command sanitize script *before* the preview
     starts. The preview fails closed if the script fails.
- Redis/Valkey: always empty.
- **Volumes:** empty by default, or cloned from the base environment.
- **Limits:** small defaults (step 6). Max concurrent previews per project (default 5). TTL: idle 72 h, and closed
  PRs are deleted immediately.
- **Access:** basic auth on by default (password shown in the PR comment to people with access), or public.
- **Secrets:** only secrets flagged `available_to_previews`. Others are absent, and the deploy fails with a clear
  message if one is required.

### Lifecycle
- Open → fork the base environment (existing `CreateEnvironment`), create the databases and volumes using the
  strategy above, deploy, then post a comment and status with the URLs.
- Push → redeploy.
- Close or merge → tear everything down, including databases, volumes and DNS, and update the comment.
- **Fork PRs never deploy automatically**, because they could steal secrets. A project member must approve with a
  `/falak preview` comment or in the UI, and fork previews never get secrets.

### UI
A "Previews" tab per project showing each PR, its status, URLs, age and cost estimate, with redeploy and delete. In
the environment switcher, previews are grouped under "Previews".

---

## Security review points (all steps)
- Threat model doc: a stolen DB backup, a stolen CP host disk, a compromised server, a malicious fork PR, a malicious
  org member with `viewer`, a provider token leak.
- Each new command schema gets negative tests: unknown fields rejected; fix ids and paths come only from allowlists.
- No secret value in: logs, audit context, exception messages, `docker inspect` (file mode), job payloads in plain
  text, telemetry, or the browser without a reveal.
- New permissions: `secrets.view|reveal|manage`, `volumes.manage|browse`, `security.fix`, `dr.manage`,
  `previews.manage`.
- An external review pass (`/security-review` plus a CodeRabbit run) on steps 1, 3, 4, 5 and 9 before merge.
- Database images: pinned base digests, Trivy in CI, cosign signatures verified by the agent; rebuilt weekly for
  base image security updates (a new `-falak<n>` tag, offered as a minor upgrade).

## Upgrade notes (docs/INSTALL.md §5)
v0.10.0 is a **breaking release**. Nobody runs Falak in production yet, so we don't carry compatibility:
- **Databases installed on servers are not managed any more.** The Databases tables are recreated, and existing host
  databases are left running on the server but disappear from Falak. Back up any data you need first. (Falak
  Cloud: checked and cleaned before its upgrade.)
- **Agents must be updated** to v0.10.0 right after the CP. Until then the server shows "Update the agent" and
  receives no commands. `falak-ctl update` then offers "update all agents".
- Secrets are re-encrypted under the new key hierarchy during the upgrade, and **the KEK emergency kit must be
  downloaded** (banner until confirmed).
- Every server gets Docker, with `live-restore`, on its next provisioning run, triggered automatically after the
  agent update.

## Test plan
- Unit and feature tests per module, contract tests for every new schema (catalogue, registry and examples agree).
- **Real servers:** free-tier AWS t3.micro VMs, tagged and deleted right after. One each of Ubuntu 22.04, 24.04 and
  26.04, plus Debian 12, for each step. Scenarios:
  - PITR to a timestamp 30 s before a `DROP TABLE` (every Postgres, MySQL and MariaDB version in the image table):
    restore → inspect read-only → swap, then keep-as-new and discard.
  - Database containers: create, connect from an app on the private network and from another server, rotate a
    password, grow memory (re-tuned config), minor and major upgrades, `dockerd` restart with no database
    downtime, a reboot.
  - Backup → encrypt → restore; restore with a customer-held key through `falak-restore`; tampered file rejected.
  - Volume clone/move/resize under load.
  - A memory-hog service killed by its limit, not the host.
  - A deploy that 500s after going live rolls back within the window.
  - Every one-click fix applied, then undone.
  - CP restore onto a fresh VM from S3, with agents reconnecting.
  - A PR opened → preview URL → push → close → everything gone (GitHub and GitLab).
- **Falak Cloud** (cloud.falak.sh) is updated last, after the release, with a backup first.

## Rough size
This is about 5× v0.9.0: around 11 PRs, 3 new modules (Secrets, Volumes, Security), a rewrite of the Databases
engine layer onto containers, 5 database image families, and large changes to Deployments, Alerting, SourceControl
and falak-ctl. Dropping backward compatibility removes the feature flags and migration shims, which saves roughly a
step's worth of work. We release only when all eleven steps have passed real-server tests. If a step slips, the
release waits, unless you decide to cut it.

## Resolved questions (industry defaults, 2026-10-06)
1. Generic provider: **HTTP webhook only** (the External Secrets model). No command provider on the CP host.
2. MySQL/MariaDB PITR base: **physical** (xtrabackup / mariabackup) plus binlog, as RDS and Percona do.
3. Rollback after a release goes live: **opt-in**, with a suggestion on production services. The pre-switch health
   gate (already there) stays on.
4. Preview domains: **configurable**. Falak Cloud uses `*.prv.falak.sh`, provisioned through Cloudflare. Because it
   shares the registrable domain with `cloud.falak.sh`, the CP's session and CSRF cookies become `__Host-` prefixed
   (host-only, `Secure`, `Path=/`), so a preview can't plant cookies the panel would read.
5. Managed databases: **containers**, one per instance, with Falak-built images. Host engines are removed with no
   migration path.
