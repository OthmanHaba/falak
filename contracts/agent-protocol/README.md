# Agent protocol v1

Shared contract between the control plane (Fleet module, PHP) and `kiln-agent` (Go).
Both sides validate against these schemas in their test suites.

## Authentication
1. **Enroll** (`POST /agent/v1/enroll`): plain TLS + one-time token. Agent sends a CSR; private key never leaves the host.
2. **Everything else**: mTLS. The edge (Caddy/FrankenPHP) verifies the client cert against the Kiln CA and forwards
   `X-Kiln-Client-Cert-Fingerprint` (SHA-256 of the DER cert, lowercase hex). Fleet matches it to an enrolled, non-revoked agent.
   Requests without a matching fingerprint get `401` with `{ "message": "...", "error": "<reason>" }`. Reasons:
   `agent_revoked` (this agent was revoked or its server was removed from Kiln; the machine needs a new install
   command), `certificate_revoked`, `certificate_expired`, `unknown_certificate`, and two that point at the edge or
   proxy setup rather than the agent: `missing_certificate` (no fingerprint forwarded) and `untrusted_peer` (the
   request did not come from a trusted proxy). Agents log `agent_revoked` as one clear, rate-limited error and back
   off; control planes before it send no `error` (a plain `401`).
3. Certs are valid 90 days; the agent renews via `POST /agent/v1/renew` (new CSR, authenticated by the current cert) when < 30 days remain.

## Endpoints
| Method | Path | Body | Response |
|---|---|---|---|
| POST | `/agent/v1/enroll` | `enroll-request` | `enroll-response` |
| GET  | `/agent/v1/ping` | — | `{ "agent_id": "...", "time": "<ISO 8601>" }` (for `kiln-agent check`: no heartbeat, no session; like every mTLS request, a certificate's first use sets `first_used_at` and retires the certificates it superseded) |
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
`db.redis`.

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
`kiln-agent features` prints the build's features (the installer only points at the machine check for
`provision.v2`). Login users carry their groups, and the effective `AllowUsers` / `DenyUsers` /
`AllowGroups` / `DenyGroups` are reported for the lockout rule.

The control plane decides per component (`install`, `adopt`, `complete`, `block`; see `docs/plans/MACHINE_CHECK.md`)
and sends no `provision.apply` while anything blocks. The plan already reflects the decisions; `components` tells the
agent which components were adopted: their `packages` are never installed (removed from the apt step, verified in an
`adopt:<name>` step that fails when one is gone), an adopted `swap` / `hostname` is kept, and an adopted
`unattended_upgrades` gets no Kiln config. `components` is stripped for agents without `provision.v2`, which also
never get `provision.inspect` and keep today's plan.

## Redis and Valkey instances (`db.redis`)
`db.redis.apply` / `db.redis.remove` (`engine`: `redis` | `valkey`) manage one instance per Kiln service, run by the
distribution's template unit: `redis-server@kiln-<name>` reads `/etc/redis/redis-kiln-<name>.conf`,
`valkey-server@kiln-<name>` reads `/etc/valkey/valkey-kiln-<name>.conf` (Debian/Ubuntu ship both templates; Valkey is
in the archive from Ubuntu 26.04 / Debian 13). The file is 0640, owned by the engine user, holds `requirepass`, and
disables `CONFIG`, `DEBUG`, `MODULE` and `SHUTDOWN`; data lives in `/var/lib/<engine>/kiln-<name>`. Apply restarts
only when the file changed, refuses a port another process listens on (`port 6381 is in use by <process>`), and waits
for `PING` (password through `REDISCLI_AUTH`). `bind` lists extra listen addresses (127.0.0.1 is always included).
The stock instance on 6379 is never touched. The control plane only queues these commands for agents that list
`db.redis`; such agents also report `facts.runtimes.redis` / `.valkey` (`<engine>-server --version`).

## Agent sessions and lost deliveries
Every `kiln-agent` process sends a random session id (`X-Kiln-Agent-Session: s-<32 hex>`, 8-64 characters of
`[A-Za-z0-9._:-]`) on every mTLS request. Agents from before sessions send none; that is accepted.

- A command records the session it was delivered to. When a request arrives with a **new** session (the agent
  restarted: upgrade, crash, reboot), commands still `delivered` or `running` under an older session were lost.
- Only the current session claims commands: a long-poll the old process abandoned keeps waiting on the server, and
  it no longer takes commands meant for the new process.
- A `delivered` command the agent does not report as started, running (heartbeat `running_commands`) or finished
  within the lease (`KILN_AGENT_COMMAND_LEASE`, default 90 s) was lost too.

A lost command whose schema has `"x-kiln-redeliverable": true` at its root is queued again (up to 5 deliveries);
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
