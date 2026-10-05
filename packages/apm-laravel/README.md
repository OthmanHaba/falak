# falak/apm-laravel

Falak APM for Laravel 12/13 (PHP 8.2+), comparable to Nightwatch. It captures:

- requests, with a timeline
- queries, with N+1 hints
- jobs and their attempts
- outgoing HTTP
- mail and notifications
- cache
- artisan commands and scheduled tasks
- exceptions, with the user
- logs

It writes them as **OTLP/HTTP JSON** to the local `falak-agent` over its unix socket, and only after the response has been sent.

It has no dependency on the OpenTelemetry SDK, `ext-grpc` or `ext-protobuf`. The hot path only appends small structs. Redaction, N+1 analysis and JSON encoding happen at flush time.

## Install

```bash
composer require falak/apm-laravel
php artisan vendor:publish --tag=falak-apm-config   # optional
```

The service provider is auto-discovered. With no configuration, it sends to `unix:/run/falak/otlp.sock` and falls back to `http://127.0.0.1:4318`.

## How it sends

- Each request, job, command or scheduled task is buffered in memory as one trace.
- Flushes happen:
  - in `terminating` callbacks, which run after the response is sent (after `fastcgi_finish_request` on FPM)
  - after every queue job (the worker job boundary)
  - after every top-level command and scheduled task
  - on Octane `RequestTerminated`
  - in a shutdown safety net
- Transport is HTTP/1.1 over `stream_socket_client`, with a **250 ms** budget for connect, write and read on each endpoint. If the send fails, the payload is **dropped silently**. The package never throws into the app, including when the agent is down.
- Octane: all state is reset on `RequestReceived`, `TaskReceived` and `TickReceived`.

## What is captured

Every span carries `falak.event.type` and the attributes the [telemetry contract](../../contracts/telemetry/README.md) requires.

| Type | Source | Attributes |
|---|---|---|
| `request` (SERVER) | global middleware prepended to the kernel | `http.request.method`, `http.route`, `http.response.status_code`, `url.path`, `enduser.id`, `falak.route.name`, `falak.route.action`; 5xx → ERROR. Child spans `bootstrap` → `middleware` → `controller` → `response` (`falak.timeline.phase`) |
| `query` (CLIENT) | `QueryExecuted` | `db.system.name`, `db.query.text` (placeholders only; bindings are never captured and quoted literals are stripped), `db.namespace`, `falak.query.connection`, `falak.query.repeat_count` (identical SQL in the same trace, for N+1 detection) |
| `job` (CONSUMER) | `JobProcessing` / `Processed` / `Failed` / `ReleasedAfterException` / `ExceptionOccurred` | `messaging.destination.name`, `falak.job.class`, `falak.job.attempt`, `falak.job.status` (`processed`\|`released`\|`failed`). Each async job is **its own trace**, linked to the dispatching trace through `falak.traceparent` in the job payload. `sync` jobs are child spans |
| `outgoing_request` (CLIENT) | global Guzzle middleware on the `Http` client | `http.request.method`, `url.full` (query redacted), `http.response.status_code`, `server.address`. Sends the W3C `traceparent` header |
| `mail` | `MessageSending` / `MessageSent` | `falak.mail.class`, `falak.mail.recipients_count`, `falak.mail.mailer` |
| `notification` | `NotificationSending` / `Sent` / `Failed` | `falak.notification.class`, `falak.notification.channel`, `falak.notification.status` (`sent`\|`failed`) |
| `cache` | `CacheHit` / `CacheMissed` / `KeyWritten` / `KeyForgotten` (timed via `RetrievingKey`/`WritingKey`/`ForgettingKey`) | `falak.cache.op` (`hit`\|`miss`\|`write`\|`forget`), `falak.cache.key`, `falak.cache.store` |
| `command` | `CommandStarting` / `CommandFinished` | `falak.command.name`, `process.exit.code`; non-zero → ERROR |
| `scheduled_task` | `ScheduledTaskStarting` / `Finished` / `Failed` / `Skipped` | `falak.schedule.name`, `falak.schedule.expression`, `falak.schedule.status` (`finished`\|`failed`\|`skipped`) |

- **Exceptions.** A `reportable()` callback adds an OTel `exception` span event to the current span with `exception.type`, `exception.message`, `exception.stacktrace` and `falak.exception.handled=true`. An exception that escapes the application is flipped to `handled=false` and its span is set to ERROR. That covers exceptions the kernel renders into the response, failed and released jobs, failed scheduled tasks, and a non-zero command exit. PHP fatals are captured in the shutdown handler.
- **Logs.** Everything that goes through Laravel's logger is sent as OTLP logs with `traceId`/`spanId` from the active span. Context is redacted and stored as `falak.context.*`. If you want to wire only some channels, set `logs_via=handler` and add the Monolog handler:

  ```php
  'channels' => ['falak' => ['driver' => 'monolog', 'handler' => Falak\Apm\Logging\OtlpHandler::class]],
  ```

- **Trace context.** An incoming `traceparent` is continued. Queued jobs carry the dispatcher's context. Outgoing HTTP propagates it.

## Configuration (`config/falak-apm.php`)

| Key | Env | Default |
|---|---|---|
| `enabled` | `FALAK_APM_ENABLED` | `true` |
| `service_name` | `FALAK_SERVICE_NAME` / `OTEL_SERVICE_NAME` | slug of `app.name` |
| `socket` | `FALAK_OTLP_SOCKET` | `unix:/run/falak/otlp.sock` |
| `fallback_endpoint` | `FALAK_OTLP_ENDPOINT` | `http://127.0.0.1:4318` (`null` disables it) |
| `timeout` | `FALAK_APM_TIMEOUT` | `0.25` s |
| `sample_rates.{request,job,command,scheduled_task}` | `FALAK_APM_*_SAMPLE_RATE` | `1.0`. Decides whether the whole trace is kept |
| `sample_rates.{query,outgoing_request,cache,mail,notification}` | | `1.0`, sampled per span |
| `sample_rates.logs` | `FALAK_APM_LOG_SAMPLE_RATE` | `1.0` |
| `events.*` | | all `true`. A disabled type registers no listeners |
| `timeline` | | `true` |
| `max_spans_per_trace` | `FALAK_APM_MAX_SPANS` | `1000` (extra spans are counted in `falak.trace.dropped_spans`) |
| `max_logs`, `log_level`, `logs_via` | `FALAK_APM_LOG_LEVEL`, `FALAK_APM_LOGS_VIA` | `1000`, `debug`, `listener` |
| `redaction.keys` | | `password, token, secret, authorization, cookie, api_key` |
| `redaction.cache_keys` | | `[]` (`Str::is` patterns) |
| `redaction.query_literals` | | `true` |
| `redaction.callbacks` | | `[]`: `fn (array $attributes, string $eventType): array` |
| `ignore.paths` / `ignore.commands` / `ignore.jobs` | | `up`, `telescope*`, `horizon*`… / `queue:work`, `horizon`, `octane:*`… / `[]` |

To register a redaction callback at runtime:

```php
app(Falak\Apm\Recorder::class)->redactUsing(function (array $attributes, string $type) {
    unset($attributes['client.address']);
    return $attributes;
});
```

Resource attributes are read from `FALAK_ORG_ID`, `FALAK_SITE_ID`, `FALAK_SERVER_ID`, `FALAK_DEPLOYMENT_ID` and `FALAK_RELEASE_ID`. Deployments inject them, and the agent also sets them authoritatively.

## Tests

```bash
composer install
vendor/bin/pest
```

The suite includes a fake OTLP receiver (`tests/Fixtures/fake-otlp-server.php`). It runs as a real process, listens on a unix socket or TCP, and the tests assert on the exact HTTP bytes it receives.
