# Agent protocol v1

Shared contract between the control plane (Fleet module, PHP) and `kiln-agent` (Go).
Both sides validate against these schemas in their test suites.

## Authentication
1. **Enroll** (`POST /agent/v1/enroll`): plain TLS + one-time token. Agent sends a CSR; private key never leaves the host.
2. **Everything else**: mTLS. The edge (Caddy/FrankenPHP) verifies the client cert against the Kiln CA and forwards
   `X-Kiln-Client-Cert-Fingerprint` (SHA-256 of the DER cert, lowercase hex). Fleet matches it to an enrolled, non-revoked agent.
   Requests without a matching fingerprint get `401` with `{ "message": "...", "error": "<reason>" }`. Reasons:
   `agent_revoked` (the agent's server was deleted; the machine needs a new install command), `certificate_revoked`,
   `certificate_expired`, `unknown_certificate`, `missing_certificate`, `untrusted_peer`. Agents log `agent_revoked`
   as one clear, rate-limited error; control planes before it send no `error` (a plain `401`).
3. Certs are valid 90 days; the agent renews via `POST /agent/v1/renew` (new CSR, authenticated by the current cert) when < 30 days remain.

## Endpoints
| Method | Path | Body | Response |
|---|---|---|---|
| POST | `/agent/v1/enroll` | `enroll-request` | `enroll-response` |
| GET  | `/agent/v1/ping` | — | `{ "agent_id": "...", "time": "<ISO 8601>" }` (no-op for `kiln-agent check`; records nothing) |
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
Current features: `edge.access_log`, `telemetry.log_kind`, `system.upgrade_agent.v2`.

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
`net.firewall.apply`, `net.wireguard.apply`, `db.user.apply`, `system.ssh_key.sync`), read-only commands
(`proc.status`, `system.facts`, `docker.compose.ps`) and `system.upgrade_agent` (a no-op once installed). Any
other type fails instead, so the deployment waiting on it fails fast: `failed` with "The agent restarted before
running the command" when it was never started, `timed_out` otherwise (a late result still overrides a
`timed_out`).

On shutdown the agent stops long-polling first and does not start commands from a response that arrives while it
stops; running commands get 20 s to finish (heartbeats keep reporting them) before they are cancelled.
