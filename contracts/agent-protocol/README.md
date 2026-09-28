# Agent protocol v1

Shared contract between the control plane (Fleet module, PHP) and `kiln-agent` (Go).
Both sides validate against these schemas in their test suites.

## Authentication
1. **Enroll** (`POST /agent/v1/enroll`): plain TLS + one-time token. Agent sends a CSR; private key never leaves the host.
2. **Everything else**: mTLS. The edge (Caddy/FrankenPHP) verifies the client cert against the Kiln CA and forwards
   `X-Kiln-Client-Cert-Fingerprint` (SHA-256 of the DER cert, lowercase hex). Fleet matches it to an enrolled, non-revoked agent.
   Requests without a matching fingerprint get `401`.
3. Certs are valid 90 days; the agent renews via `POST /agent/v1/renew` (new CSR, authenticated by the current cert) when < 30 days remain.

## Endpoints
| Method | Path | Body | Response |
|---|---|---|---|
| POST | `/agent/v1/enroll` | `enroll-request` | `enroll-response` |
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
