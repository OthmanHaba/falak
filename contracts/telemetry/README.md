# Telemetry contract v1

Shared by `kiln-agent` (receiver/relay), `packages/apm-laravel`, `packages/apm-node`, and the control-plane
Telemetry + Insights modules.

## Transport (app → agent)
The agent exposes a standard **OTLP/HTTP** receiver (both `application/x-protobuf` and `application/json`) on:
- `unix:/run/kiln/otlp.sock` (preferred for PHP; `POST /v1/traces`, `/v1/logs`, `/v1/metrics`)
- `http://127.0.0.1:4318` (standard OTLP port; used by Node/Bun/Deno exporters)

Apps must **never block a request** on telemetry: the Laravel package flushes after the response is sent
(`terminating` callbacks), with a 250 ms socket timeout and silent drop on failure.

The agent batches, disk-buffers (bounded, default 64 MB) and exports to the observability endpoint from enrollment.

## Resource attributes (set by the agent on every signal; apps may omit)
| Attribute | Example |
|---|---|
| `service.name` | site slug, e.g. `shop-example-com` |
| `deployment.environment.name` | `production` |
| `kiln.org.id`, `kiln.server.id`, `kiln.site.id` | ULIDs |
| `kiln.deployment.id`, `kiln.release.id` | ULIDs of the active release |
| `host.name` | server hostname |

Apps learn their ids from env vars injected by Deployments: `KILN_SITE_ID`, `KILN_SERVER_ID`, `KILN_DEPLOYMENT_ID`, `KILN_RELEASE_ID`.

## Event taxonomy (Nightwatch parity)
Every APM span carries `kiln.event.type`:

| `kiln.event.type` | Span kind | Required attributes |
|---|---|---|
| `request` | SERVER | `http.request.method`, `http.route`, `http.response.status_code`, `url.path` |
| `query` | CLIENT | `db.system.name`, `db.query.text` (redacted bindings), `db.namespace`, `kiln.query.connection` |
| `job` | CONSUMER | `messaging.destination.name` (queue), `kiln.job.class`, `kiln.job.attempt`, `kiln.job.status` (`processed`\|`released`\|`failed`) |
| `outgoing_request` | CLIENT | `http.request.method`, `url.full` (query redacted), `http.response.status_code` |
| `mail` | INTERNAL | `kiln.mail.class`, `kiln.mail.recipients_count`, `kiln.mail.mailer` |
| `notification` | INTERNAL | `kiln.notification.class`, `kiln.notification.channel`, `kiln.notification.status` |
| `cache` | INTERNAL | `kiln.cache.op` (`hit`\|`miss`\|`write`\|`forget`), `kiln.cache.key`, `kiln.cache.store` |
| `command` | INTERNAL | `kiln.command.name`, `process.exit.code` |
| `scheduled_task` | INTERNAL | `kiln.schedule.name`, `kiln.schedule.expression`, `kiln.schedule.status` (`finished`\|`failed`\|`skipped`) |

- **User context:** `enduser.id` on the root span when authenticated.
- **Exceptions:** standard OTel span event `exception` (`exception.type`, `exception.message`, `exception.stacktrace`)
  plus `kiln.exception.handled` (bool). The span status is `ERROR` for unhandled exceptions.
- **Logs:** OTLP logs, correlated with `trace_id`/`span_id`.
- **Redaction:** apps redact before sending (headers, query bindings, cache keys by pattern). Default denylist:
  `password`, `token`, `secret`, `authorization`, `cookie`, `api_key`.

## Insights tee (agent → control plane)
The agent inspects spans it relays and forwards compact summaries to `POST /agent/v1/insights` (NDJSON):
- every span with an `exception` event → `{ "kind": "exception", "trace_id", "span_id", "site_id", "type", "message", "stacktrace", "handled", "user_id", "event_type", "route_or_name", "at" }`
- per-minute per-route/job/query-shape aggregates → `{ "kind": "aggregate", "site_id", "event_type", "name", "count", "p50_ms", "p95_ms", "max_ms", "errors", "minute" }`

Thresholds, grouping into issues, and alerts live in the control plane (Insights module).
