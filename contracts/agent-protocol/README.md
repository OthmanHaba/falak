# Agent protocol v1

Shared contract between the control plane (Fleet module, PHP) and `falak-agent` (Go).
Both sides validate against these schemas in their test suites.

## Authentication
1. **Enroll** (`POST /agent/v1/enroll`): plain TLS + one-time token. Agent sends a CSR; private key never leaves the host.
2. **Everything else**: mTLS. The edge (Caddy/FrankenPHP) verifies the client cert against the Falak CA and forwards
   `X-Falak-Client-Cert-Fingerprint` (SHA-256 of the DER cert, lowercase hex). Fleet matches it to an enrolled, non-revoked agent.
   Requests without a matching fingerprint get `401` with `{ "message": "...", "error": "<reason>" }`. Reasons:
   `agent_revoked` (this agent was revoked or its server was removed from Falak; the machine needs a new install
   command), `certificate_revoked`, `certificate_expired`, `unknown_certificate`, and two that point at the edge or
   proxy setup rather than the agent: `missing_certificate` (no fingerprint forwarded) and `untrusted_peer` (the
   request did not come from a trusted proxy). Agents log `agent_revoked` as one clear, rate-limited error and back
   off; control planes before it send no `error` (a plain `401`).
3. Certs are valid 90 days; the agent renews via `POST /agent/v1/renew` (new CSR, authenticated by the current cert) when < 30 days remain.

## Endpoints
| Method | Path | Body | Response |
|---|---|---|---|
| POST | `/agent/v1/enroll` | `enroll-request` | `enroll-response` |
| GET  | `/agent/v1/ping` | — | `{ "agent_id": "...", "time": "<ISO 8601>" }` (for `falak-agent check`: no heartbeat, no session; like every mTLS request, a certificate's first use sets `first_used_at` and retires the certificates it superseded) |
| POST | `/agent/v1/renew` | `{ "csr_pem": "..." }` | `{ "cert_pem": "..." }` |
| POST | `/agent/v1/heartbeat` | `heartbeat` | `204` |
| GET  | `/agent/v1/commands?wait=30` | — | `{ "commands": [envelope...] }` (long-poll, returns early when a command is queued) |
| POST | `/agent/v1/commands/{id}/events` | NDJSON of `event` | `204` (idempotent on `(command_id, seq)`) |
| POST | `/agent/v1/insights` | NDJSON of insight events | `204` |

## Command payloads
`commands/<type>.schema.json` — one schema per command type in the catalogue in `ARCHITECTURE.md` §3.
Owned by the agent implementation; the control plane builds payloads against them.

## Evolving payloads (features)
Agents decode payloads strictly: an unknown field fails the command. A new **optional** field therefore needs a
feature name: the agent lists it in `facts.features` (`agent/internal/version.Features`) and the control plane
removes the field for agents that do not (`Fleet\Application\PayloadCompatibility::FIELDS`). When an agent reports
a new version (`Fleet\Events\AgentVersionChanged`), modules re-send state they would otherwise deduplicate.
Current features: `edge.access_log`, `telemetry.log_kind`, `system.upgrade_agent.v2`, `fn.v1`, `fn.v2`, `fn.v3`,
`db.containers`, `compose.v2`, `docker.networks`, `docker.networks.create`, `compose.up.services`, `provision.v2`,
`db.redis`, `db.redis.network`, `net.firewall.peer_interfaces`.

A feature can also gate a whole **command**: the control plane only queues it for agents that list the feature
(older agents would fail it as an unknown type). `provision.v2` adds `provision.inspect` and `provision.apply`
`components`.

## Machine check (`provision.v2`)
`provision.inspect` is read-only: it reports the software already on the machine and where it came from
(`$defs.result`: packages with their origin — `archive`, `vendor` with the repository URL, `manual` —, snaps, apt
sources, systemd units, TCP listeners with process, unit and container proxies, containers' published ports,
Docker, sshd, firewalls, swap, Node / PHP / FrankenPHP binaries, unattended-upgrades, fail2ban). A detector that
fails leaves its part empty and adds an `errors` entry; the command only fails when cancelled. Extra package
patterns (the plan's base packages) can be passed in `packages`. The inspector never executes a file root does not
own (it and every directory on its path must be root-owned and not group/world-writable): versions of user installs
come from directory names and package metadata. Repository URLs and errors carry no URL credentials. A package whose
installed version no repository offers takes the origin of the repository offering the package; with no package
lists at all the origin is `unknown`. It runs a file by its symlink-resolved path, checked component by component; authorized_keys
files are opened without following symlinks (O_NOFOLLOW|O_NONBLOCK, regular files only, first MiB, key lines only).
`falak-agent features` prints the build's features (the installer only points at the machine check for
`provision.v2`). Login users carry their groups, and the effective `AllowUsers` / `DenyUsers` /
`AllowGroups` / `DenyGroups` are reported for the lockout rule.

The control plane decides per component (`install`, `adopt`, `complete`, `block`; see `docs/plans/MACHINE_CHECK.md`)
and sends no `provision.apply` while anything blocks. The plan already reflects the decisions; `components` tells the
agent which components were adopted: their `packages` are never installed (removed from the apt step, verified in an
`adopt:<name>` step that fails when one is gone), an adopted `swap` / `hostname` is kept, and an adopted
`unattended_upgrades` gets no Falak config. `components` is stripped for agents without `provision.v2`, which also
never get `provision.inspect` and keep today's plan.

## Redis and Valkey instances (`db.redis`)
`db.redis.apply` / `db.redis.remove` (`engine`: `redis` | `valkey`) manage one instance per Falak service, run by the
distribution's template unit `redis-server@falak-<name>` / `valkey-server@falak-<name>` (Debian/Ubuntu ship both
templates: `Type=notify`, `RuntimeDirectory`, `ProtectSystem=strict`). Each instance runs as its own system user
`falak-<engine>-<name>` (past 32 characters `falak-rh-` / `falak-vh-` + a hash, which no plain name produces; the agent
only adopts or deletes a user carrying its GECOS `Falak <Engine> instance <name>`, home `/nonexistent` and a nologin
shell, and refuses to use any other user of that name): a drop-in `/etc/systemd/system/<unit>.d/50-falak.conf` sets `User=`/`Group=`, resets
`ReadWritePaths=` to the instance's data directory `/var/lib/falak-<engine>/<name>` (0700) and its runtime directory,
sets `TimeoutStartSec=20min` (`Type=notify` waits for the dataset to load) and points `ExecStart` at `/etc/falak-<engine>/<name>.conf` (the template's `/etc/redis` is 0770 `redis:redis`, which
the instance user must not join), so the stock instance on 6379 and other instances can neither read nor write its data. The
config holds `requirepass`, is 0640 `root:<instance group>`, renames `CONFIG` to a random name only the agent knows
(root-only state in `/var/lib/falak/db/redis/`), and disables `DEBUG`, `MODULE`, `SHUTDOWN`, `REPLICAOF`, `SLAVEOF`,
`MIGRATE`, `ACL`, `MONITOR`, `SLOWLOG` and, on Valkey 8.1+, `COMMANDLOG` (the last three would show the agent's commands;
the version comes from `<engine>-server --version`) (`SYNC`/`PSYNC`/`REPLCONF` stay for `redis-cli --rdb`, `EVAL`/`FUNCTION` for Laravel).
redis-cli gets every command on stdin and the password in `REDISCLI_AUTH`: neither reaches a command line.

Memory limit, eviction, password and persistence change on the running instance (renamed `CONFIG SET`; AOF on: the
rewrite is awaited through `INFO persistence`; AOF off or rdb from none: `SAVE` first). The current mode always comes
from the running process (`INFO persistence`, `CONFIG GET save`; the config file when it is down), never from the
agent's state, so an AOF the process uses is never moved. A new port, bind address, drop-in or set of disabled
commands restarts the instance: save points are set live and `SAVE`d (with `none`: snapshots and AOF off) so the stop
keeps (or drops) the data as wanted, then stop, move aside what the next start must not load
(`appendonlydir.falak-<UTC time>`), start; a restart that turns AOF on starts from `dump.rdb` with snapshots and switches
AOF on live. An AOF whose first rewrite is running, scheduled or failed (stopped: no manifest) is never loaded: AOF is
switched off before the restart and the start runs from the snapshot. A wait that runs out, or a command with under 2
minutes left, never restarts the instance (the apply fails; the redelivery waits again). Applies and removes of one
instance are serialized (waiting ends with the command's context), every local account change globally (site users
included). Errors never
carry the secret `CONFIG` name, passwords or command arguments. With
`none` the data is in memory only: files from earlier modes are moved aside and every restart starts empty. The agent
records what the running process uses only after a successful (re)start and `PING`, so a redelivered apply after a
failure converges. Apply refuses a new port another process listens on (`port 6381 is in use by <process>`) and
waits for `PING` (`LOADING` extends the wait to 15 minutes). The stock instance is never touched. The control plane
only queues these commands for agents that list `db.redis`; such agents also report `facts.runtimes.redis` /
`.valkey` (`<engine>-server --version`).

**Network access (`db.redis.network`).** `bind` only accepts loopback, private (RFC 1918, CGNAT `100.64.0.0/10`, IPv6
ULA `fc00::/7`) and WireGuard interface addresses (a private network whose range is public: the interface's sysfs
`DEVTYPE=wireguard`, without sysfs the `wg` name prefix); anything else, `0.0.0.0` / `::` and link-local included,
fails the command before anything changes. An accepted address the host does not have (yet) is left out and reported
in the result's `skipped` (Redis would refuse to start); the control plane applies again when it appears.
`containers: true` adds the Docker default bridge's IPv4 (`docker0`, when it exists and is private): containers on
any bridge network of the server reach it through their gateway. The result reports `bind` (what the instance
listens on) and `container_host`. A changed bind list restarts the instance the usual way (data kept).
`net.firewall.apply` `container_ports[].peers` (same feature) are other servers' addresses accepted for the ports on
the interface they arrive on — `container_ports[].peer_interfaces` (address → interface) when the control plane names
it (a Falak WireGuard network's, also before its config reaches the server), else the agent's: a Falak WireGuard network
whose `Address` range holds the peer, a local subnet; none: any interface — after the Docker-bridge accepts and before
the port's drop: `sources` may then be empty. `peer_interfaces` needs feature `net.firewall.peer_interfaces`
(stripped otherwise). Both fields
are stripped for agents without the feature (the control plane never sends them non-loopback binds either).

## Agent sessions and lost deliveries
Every `falak-agent` process sends a random session id (`X-Falak-Agent-Session: s-<32 hex>`, 8-64 characters of
`[A-Za-z0-9._:-]`) on every mTLS request. Agents from before sessions send none; that is accepted.

- A command records the session it was delivered to. When a request arrives with a **new** session (the agent
  restarted: upgrade, crash, reboot), commands still `delivered` or `running` under an older session were lost.
- Only the current session claims commands: a long-poll the old process abandoned keeps waiting on the server, and
  it no longer takes commands meant for the new process.
- A `delivered` command the agent does not report as started, running (heartbeat `running_commands`) or finished
  within the lease (`FALAK_AGENT_COMMAND_LEASE`, default 90 s) was lost too.

A lost command whose schema has `"x-falak-redeliverable": true` at its root is queued again (up to 5 deliveries);
the agent answers a command id it already finished from its journal, so nothing runs twice. Redeliverable:
declarative state (`edge.caddy.apply`, `edge.cert.install`, `telemetry.configure`, `proc.apply`, `cron.apply`,
`net.firewall.apply`, `net.wireguard.apply`, `db.user.apply`, `db.redis.apply`, `db.redis.remove`,
`system.ssh_key.sync`), read-only commands
(`proc.status`, `system.facts`, `docker.compose.ps`, `provision.inspect`) and `system.upgrade_agent` (a no-op once
installed). Any other type fails instead, so the deployment waiting on it fails fast: `failed` with "The agent
restarted before running the command" when it was never started, `timed_out` otherwise (a late result still
overrides a `timed_out`).

On shutdown the agent stops long-polling first and does not start commands from a response that arrives while it
stops; running commands get 20 s to finish (heartbeats keep reporting them) before they are cancelled.
