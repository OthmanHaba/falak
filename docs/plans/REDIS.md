# Redis and Valkey as database engines (plan, v0.7.0)

Today a server can run Redis or Valkey only as a "cache" component: provisioning installs `redis-server` /
`valkey-server` and starts the stock service on `127.0.0.1:6379` with no password, and nothing in Falak manages it.
The canvas picker shows Redis as "Coming soon" and `POST /services` answers `422 Redis services are not supported
yet`. v0.7.0 makes Redis and Valkey first-class engines of the Databases module: create one from the canvas, get
`REDIS_*` references, back it up, restore it, reach it from containers, and turn a compose file's `redis` service
into a Falak Redis.

## Decisions (approved 2026-10-03)

- **One instance per service.** Every Falak Redis is its own `redis-server@falak-<name>` (or `valkey-server@…`)
  process: own port, password, memory limit, eviction policy, persistence and dump file. The Debian packages ship
  the `@` template unit (`/usr/lib/systemd/system/redis-server@.service`, reading `/etc/redis/redis-%i.conf`). Data
  in one service can never be read through another one, and a backup is one service.
- The stock instance on 6379 is **left alone**: apps that already use `127.0.0.1:6379` keep working, and the
  machine check rules for 6379 stay as they are. Falak's instances use **6380–6479**.
- First release includes: canvas + Databases UI, backups + restore, compose apps, container access.

## Data model

Reuse the Databases model so `DatabaseData`, `DatabaseDirectory`, `DatabaseConnections`, backups, schedules and the
canvas keep working through their contracts.

| Today | For Redis |
|---|---|
| `DatabaseServer`: one row per server (`server_id` unique), the SQL engine | one row **per engine** per server: unique `(server_id, engine)`. A default app server has a PostgreSQL row and a Redis row. Lookups by `server_id` alone (8 in Databases, plus Projects' picker) take an engine or a kind. |
| `Database`: a SQL database | an **instance**. New nullable columns `port` and `settings` (json: `maxmemory_mb`, `eviction`, `persistence`). |
| `DatabaseUser` + grants | one user `default` per instance (the `requirepass` password), created with the instance, never edited, grants hidden. Reveal and rotate keep working. |

`Engine` gains `Redis = 'redis'` and `Valkey = 'valkey'`, plus `kind(): EngineKind` (`Sql` | `KeyValue`). The SQL
methods (charset, collation, privileges, reserved names, `protocol()`) are only called for `Sql` engines; the
`KeyValue` ones answer `defaultPort() = 6379`, `driver() = 'redis'`, reserved names `default`, `falak`.

**Defaults:** `maxmemory` 128 MB (bounded by the server's RAM), eviction `noeviction` (safe for queues; caches
can pick `allkeys-lru`), persistence `rdb` (snapshots) with `aof` and `none` offered. Name pattern
`^[a-z][a-z0-9_-]{0,40}$`.

**Ports:** the control plane allocates the lowest free port in 6380–6479 per server (taken: other instances, and
the ports in the server's latest machine inspection). The agent re-checks before it starts and fails with "port
6381 is in use by <process>" instead of fighting over it.

## Engine install and inventory

- `EngineInventory::sync` also reads `ServerData::cacheEngine`, so a provisioned app or cache server gets its Redis /
  Valkey `DatabaseServer` row with no extra step. The version comes from facts (the agent now reports
  `redis-server --version` / `valkey-server --version` under `facts.runtimes`) or `databases.distro_versions`.
- `InstallDatabaseEngine` (Servers) also installs a cache engine on a server without one ("Install Redis on this
  server"), with the same machine check rules.
- **Availability per OS:** `valkey-server` is only in Ubuntu's archive from 26.04 (8.1). New config
  `servers.caches_by_os` (like `php_versions_by_os`): 22.04 / 24.04 / Debian 12 offer Redis, 26.04 offers Redis and
  Valkey. The picker and the server forms only show what installs.

## Agent: `db.redis.*` (feature `db.redis`)

New commands, schemas in `contracts/agent-protocol/commands/`; `engine` is `redis` | `valkey`. Older agents don't
advertise `db.redis`, so the control plane refuses to create an instance there with "Update the agent on <server>
first".

| Command | Does |
|---|---|
| `db.redis.apply` | Writes `/etc/redis/redis-falak-<name>.conf` (Valkey: `/etc/valkey/valkey-falak-<name>.conf`), 0640 `redis:redis`: `port`, `bind` (127.0.0.1 + the allowed addresses below), `protected-mode yes`, `requirepass`, `maxmemory`, `maxmemory-policy`, `save` / `appendonly`, `dir /var/lib/redis/falak-<name>`, `rename-command` for `CONFIG`, `DEBUG`, `MODULE`, `SHUTDOWN` (users can't rewrite the config). Enables and starts `redis-server@falak-<name>`; restarts only when the file changed; waits for `PING` with the password. Idempotent. |
| `db.redis.remove` | Stops and disables the unit, deletes the config and the data dir. |
| `db.backup` / `db.restore` with `engine: redis` | See Backups. |

Passwords never land on the command line: `redis-cli` gets them through `REDISCLI_AUTH`. The config file holds the
password (as in every Redis install) and is readable only by root and `redis`.

## Network and container access

- `bind` covers `127.0.0.1`, plus the server's private address when the project has services on other servers
  (the SQL engines' "remote" rule), plus the Docker bridge gateways when container access is on. *(As built: the
  environment's sites' servers, docker0's address; see Phases.)*
- `DatabaseContainerPorts` reports **each instance's port**, so the Network module's firewall opens exactly those
  ports to the Docker ranges on the bridges, and the private address to the project's servers. Public access stays
  closed.
- `EnableContainerAccess` re-applies the instances (new `bind`) the way it re-applies SQL users today.

## Connections and references

`DatabaseConnections` keys become engine-aware: `keysFor(engine)`. For Redis / Valkey:

| Key | Value |
|---|---|
| `REDIS_URL` | `redis://default:<password>@<host>:<port>` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Laravel's names (`config/database.php` reads them) |
| `REDIS_CLIENT` | `phpredis` (the server's PHP has the `redis` extension) |

Host reachability uses `HOST_KEYS` (`REDIS_HOST`, `REDIS_URL`) and the same rules as SQL: same server →
`127.0.0.1`, container on the same server → the bridge gateway, another server in the project → the private
address. The canvas variable list, `${{ redis.REDIS_URL }}` references and the service panel's "Connect" card show
these keys.

## Backups and restore

- **Backup:** `redis-cli --rdb <tmp>` (a consistent RDB snapshot from a running instance, no restart), gzip, upload
  through the presigned PUT. Object key `…/<name>-<ts>.rdb(.gz)`. Schedules, retention and pruning are unchanged.
- **Restore:** download, check the RDB magic (`REDIS` header), stop the unit, move the current dump aside as
  `dump.rdb.falak-<ts>`, put the snapshot in place, and, when the instance uses AOF, start it with `appendonly no`,
  then run `BGREWRITEAOF` and switch AOF back on (Redis would otherwise ignore the RDB). If startup fails, the old
  dump is put back and the restore fails with the log tail.
- Restore targets: Redis and Valkey instances only (Valkey 8 loads Redis ≤ 7.2 RDBs; a Redis 7.4+/8 RDB can't
  load into Valkey and the restore says so).

## Canvas and Databases UI

- Picker: Redis and Valkey become real choices. Each lists the servers that have that engine (or can install it),
  plus memory limit and eviction under "Advanced".
- Canvas node: icon redis / valkey, subtitle "Redis 7.0 · 128 MB", volume `redis-data`.
- Databases pages: the panel for a Redis service shows **Overview** (connection card with `REDIS_*` and a
  `redis-cli` line), **Settings** (memory limit, eviction, persistence, version), **Backups**. The Users tab, charset
  and collation and privileges are hidden for key-value engines.

## Compose apps

- `ENGINE_IMAGES` gains `redis`, `valkey` (official images, any tag); `redis-stack` and `bitnami/redis` stay
  containers (modules / different config).
- Choosing "Falak database" for such a service creates a Falak Redis on the stack's server, removes the service from
  the compose file and rewrites references: `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_URL`, and
  `redis://<service>:6379` inside other values.
- The container's data is **not** migrated (same as SQL today). The service panel says so before you confirm.

## Phases

1. **Engine + instances:** enum, migration, inventory, facts versions, `caches_by_os`, agent `db.redis.apply` /
   `remove`, create / delete through `DatabaseProvisioner` (canvas), connections + references, Databases UI. *Built
   (v0.7.0).*
2. **Network:** bind addresses, per-instance firewall ports, container access, remote access. *Built (v0.7.1); see
   "As built" below.*
3. **Backups + restore.** *Built (v0.9.0); see "As built (v0.9.0, phase 3)" below.*
4. **Compose apps.** *Built (v0.7.1).*

### As built (v0.7.1, phases 2 and 4)
Details in `docs/INTEGRATION-NOTES.md` ("Redis and Valkey network access and compose apps"). Deviations from the plan:
- A new agent feature `db.redis.network` after all (the plan expected none): the agent now refuses public bind
  addresses, skips missing ones and resolves `containers` to docker0; older agents keep 127.0.0.1.
- Containers use **docker0's address** (one bind for every bridge network on the server), not each network's gateway.
- "The project's servers" became **the servers of the sites in the instance's environment** (references resolve per
  environment); the firewall opens the port to exactly those servers' private addresses (`container_ports[].peers`),
  not to every private-network member. Private address order: Falak private network, then the provider private network
  (same provider credential and region, DigitalOcean / Lightsail only; custom servers only in the sim); never public —
  such references stay unresolved with a message.
- Compose: the leader must already run the image's engine (no automatic engine install); `REDIS_PORT` /
  `REDIS_PASSWORD` are added next to a `REDIS_HOST` that had none; inline stacks can take services out at creation.

### As built (v0.9.0, phase 3)
Details in `docs/INTEGRATION-NOTES.md` ("Redis and Valkey backups and restores") and `contracts/agent-protocol/README.md`.
- **Protocol:** no new commands. `db.backup` / `db.restore` gained `engine: redis | valkey` with the instance name as
  `database` (schema `if/then`: instance names for key-value engines, SQL identifiers otherwise); results add `rdb`
  (e.g. `REDIS0011`, `VALKEY080`) and, for restores, `moved_aside`. New agent feature **`db.redis.backup`**; the control
  plane refuses backups, schedules and restores on servers without it ("Update the agent on <server> first: backups and
  restores of Redis instances need a newer agent (feature db.redis.backup)."), scheduled runs there fail with that text.
- **Object key:** `<prefix>/<server-slug>-<id6>/<instance>/<Y>/<m>/<UTC ts>-<backup id>.rdb.gz` (`.rdb` uncompressed) —
  the SQL layout with the instance as the database segment, not `…/<name>-<ts>.rdb.gz`.
- **Header check accepts two magics** (deviation): Valkey 9 writes `VALKEY080` (9 bytes, `VALKEY` + 3 digits), not
  `REDIS…`. The agent compares the RDB version with the installed server (`<engine>-server --version`) before it stops
  anything: Valkey refuses Redis' 12+ (Redis 7.4 / 8; the plan's case), Redis refuses any `VALKEY…`, Valkey < 9 refuses
  `VALKEY…`, Redis 6.x / 7.0 / 7.2 refuse versions above 9 / 10 / 11 (so a Valkey 7.2–8.1 or Redis 7.2 snapshot into
  Ubuntu 24.04's Redis 7.0 is refused up front). Redis ≥ 7.4 has no known upper bound (Redis 8.6 writes `REDIS0013`):
  the server decides, and a failed load rolls back. Valkey → Redis is therefore allowed by the control plane too.
- **AOF:** no separate `BGREWRITEAOF`: the instance starts from its config with `appendonly no`, then `CONFIG SET
  appendonly yes` (the renamed command) starts the rewrite from memory; the agent waits for it (`INFO persistence`,
  the apply's `setPersistence`) and puts the config file back. The AOF instance has no save points, so only its
  `appendonlydir` (or 6.0's `appendonly.aof`) is moved aside, plus any `dump.rdb`.
- **Persistence `none`:** the loaded `dump.rdb` is deleted after the start (a restart starts empty, as `none` promises).
- **Moved-aside files stay** in the data directory (`<file>.falak-<UTC time>`, reported in `moved_aside` and the
  command output), owner-only (0600 files, 0700 `appendonlydir`; Redis writes `dump.rdb` 0660) — only the latest set: once a restore succeeded, every other `dump.rdb` / `appendonlydir` /
  `appendonly.aof` `.falak-*` copy (earlier restores, persistence changes) is removed (listed in the output; links
  are removed, never followed). A restore that moved nothing (an instance without files) removes nothing. A rollback (start, `PING` or AOF switch failed) removes the restored files,
  renames them back, rewrites the earlier config / state and starts the instance if it ran before (own 10-minute
  budget), failing with "the earlier data is back: … <log tail>".
- **Targets:** existing, active instances of either key-value engine; a restore never creates an instance (nor a
  `databases_databases` row). SQL dumps never go into instances and snapshots never into SQL engines.
- **Backup:** `redis-cli --rdb` writes to a root-only temp file outside the data directory; the instance lock is held
  only for the snapshot, not the upload. The raw file and its gzipped copy share the agent's TempDir while it
  compresses (the raw one is removed before the upload); streaming (`--rdb -`, Redis 7.0+ only) is left for later. A stopped instance fails ("is not running"). Redis 7+ waits
  `repl-diskless-sync-delay` (5 s by default) before it streams the snapshot.
- **Extra:** a Download action (SQL backups too): `GET /databases/backups/{backup}/download`, a 5-minute presigned GET
  (`FALAK_BACKUP_DOWNLOAD_LINK_TTL`), restore permission, audited `databases.backup_downloaded`.
- **Tests:** Go unit tests (header, version matrix, backup, rdb / AOF / none restores, rollback, refusals), a test
  against a local `redis-server` when one is installed, and the Docker integration test (`FALAK_REDIS_INTEGRATION=1`)
  backs up and restores on all six images (rdb, AOF + restart, broken snapshot rollback, Redis 6.0 / 7.0 snapshots into
  Valkey, Redis 8.0's refused). Pest: gating, schedules, retention, restore rules, panel data, download.
- **Not verified:** a real systemd host (ownership 0600 / instance user, `ProtectSystem=strict` with the staging file
  in the data directory), Playwright, the sim E2E.

Each phase: Pest + Go tests, the sim E2E for create → reference → deploy a Laravel site that uses
`REDIS_URL`, review by a separate reviewer, then an rc tag.

## Testing

- Go: config rendering, idempotency (unchanged file → no restart), port-in-use failure, backup / restore with a real
  `redis-server` in the test container, AOF restore path.
- Pest: engine kind gates, port allocation, two engines per server, `keysFor`, picker data, compose extraction and
  reference rewriting, backup keys.
- Sim E2E: the sim's server containers install `redis-server`.
- **Live test:** there is no live install since the AWS fleet was torn down. The rc needs a real Ubuntu VM (24.04 for
  Redis, 26.04 for Valkey) before v0.7.0 is released.

## Out of scope

Redis Cluster / Sentinel, replicas, ACL users per app, modules (RedisJSON, search), TLS for Redis, migrating data
out of a compose container, KeyDB / Dragonfly.
