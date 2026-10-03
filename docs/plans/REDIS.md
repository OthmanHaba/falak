# Redis and Valkey as database engines (plan, v0.7.0)

Today a server can run Redis or Valkey only as a "cache" component: provisioning installs `redis-server` /
`valkey-server` and starts the stock service on `127.0.0.1:6379` with no password, and nothing in Kiln manages it.
The canvas picker shows Redis as "Coming soon" and `POST /services` answers `422 Redis services are not supported
yet`. v0.7.0 makes Redis and Valkey first-class engines of the Databases module: create one from the canvas, get
`REDIS_*` references, back it up, restore it, reach it from containers, and turn a compose file's `redis` service
into a Kiln Redis.

## Decisions (approved 2026-10-03)

- **One instance per service.** Every Kiln Redis is its own `redis-server@kiln-<name>` (or `valkey-server@…`)
  process: own port, password, memory limit, eviction policy, persistence and dump file. The Debian packages ship
  the `@` template unit (`/usr/lib/systemd/system/redis-server@.service`, reading `/etc/redis/redis-%i.conf`). Data
  in one service can never be read through another one, and a backup is one service.
- The stock instance on 6379 is **left alone**: apps that already use `127.0.0.1:6379` keep working, and the
  machine check rules for 6379 stay as they are. Kiln's instances use **6380–6479**.
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
`KeyValue` ones answer `defaultPort() = 6379`, `driver() = 'redis'`, reserved names `default`, `kiln`.

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
| `db.redis.apply` | Writes `/etc/redis/redis-kiln-<name>.conf` (Valkey: `/etc/valkey/valkey-kiln-<name>.conf`), 0640 `redis:redis`: `port`, `bind` (127.0.0.1 + the allowed addresses below), `protected-mode yes`, `requirepass`, `maxmemory`, `maxmemory-policy`, `save` / `appendonly`, `dir /var/lib/redis/kiln-<name>`, `rename-command` for `CONFIG`, `DEBUG`, `MODULE`, `SHUTDOWN` (users can't rewrite the config). Enables and starts `redis-server@kiln-<name>`; restarts only when the file changed; waits for `PING` with the password. Idempotent. |
| `db.redis.remove` | Stops and disables the unit, deletes the config and the data dir. |
| `db.backup` / `db.restore` with `engine: redis` | See Backups. |

Passwords never land on the command line: `redis-cli` gets them through `REDISCLI_AUTH`. The config file holds the
password (as in every Redis install) and is readable only by root and `redis`.

## Network and container access

- `bind` covers `127.0.0.1`, plus the server's private address when the project has services on other servers
  (the SQL engines' "remote" rule), plus the Docker bridge gateways when container access is on.
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
  `dump.rdb.kiln-<ts>`, put the snapshot in place, and, when the instance uses AOF, start it with `appendonly no`,
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
- Choosing "Kiln database" for such a service creates a Kiln Redis on the stack's server, removes the service from
  the compose file and rewrites references: `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_URL`, and
  `redis://<service>:6379` inside other values.
- The container's data is **not** migrated (same as SQL today). The service panel says so before you confirm.

## Phases

1. **Engine + instances:** enum, migration, inventory, facts versions, `caches_by_os`, agent `db.redis.apply` /
   `remove`, create / delete through `DatabaseProvisioner` (canvas), connections + references, Databases UI.
2. **Network:** bind addresses, per-instance firewall ports, container access, remote access.
3. **Backups + restore.**
4. **Compose apps.**

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
