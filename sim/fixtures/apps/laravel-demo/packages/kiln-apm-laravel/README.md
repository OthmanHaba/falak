# kiln/apm-laravel

Kiln APM for Laravel 11/12 (PHP 8.2+), comparable to Nightwatch. It captures:

- requests, with a timeline
- queries, with N+1 hints
- jobs and their attempts
- outgoing HTTP
- mail and notifications
- cache
- artisan commands and scheduled tasks
- exceptions, with the user
- logs

It writes them as **OTLP/HTTP JSON** to the local `kiln-agent` over its unix socket, and only after the response has been sent.

It has no dependency on the OpenTelemetry SDK, `ext-grpc` or `ext-protobuf`. The hot path only appends small structs. Redaction, N+1 analysis and JSON encoding happen at flush time.

## Install

```bash
composer require kiln/apm-laravel
php artisan vendor:publish --tag=kiln-apm-config   # optional
```

The service provider is auto-discovered. With no configuration, it sends to `unix:/run/kiln/otlp.sock` and falls back to `http://127.0.0.1:4318`.

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

Every span carries `kiln.event.type` and the attributes the [telemetry contract](../../contracts/telemetry/README.md) requires.

| Type | Source | Attributes |
|---|---|---|
| `request` (SERVER) | global middleware prepended to the kernel | `http.request.method`, `http.route`, `http.response.status_code`, `url.path`, `enduser.id`, `kiln.route.name`, `kiln.route.action`; 5xx → ERROR. Child spans `bootstrap` → `middleware` → `controller` → `response` (`kiln.timeline.phase`) |
| `query` (CLIENT) | `QueryExecuted` | `db.system.name`, `db.query.text` (placeholders only; bindings are never captured and quoted literals are stripped), `db.namespace`, `kiln.query.connection`, `kiln.query.repeat_count` (identical SQL in the same trace, for N+1 detection) |
| `job` (CONSUMER) | `JobProcessing` / `Processed` / `Failed` / `ReleasedAfterException` / `ExceptionOccurred` | `messaging.destination.name`, `kiln.job.class`, `kiln.job.attempt`, `kiln.job.status` (`processed`\|`released`\|`failed`). Each async job is **its own trace**, linked to the dispatching trace through `kiln.traceparent` in the job payload. `sync` jobs are child spans |
| `outgoing_request` (CLIENT) | global Guzzle middleware on the `Http` client | `http.request.method`, `url.full` (query redacted), `http.response.status_code`, `server.address`. Sends the W3C `traceparent` header |
| `mail` | `MessageSending` / `MessageSent` | `kiln.mail.class`, `kiln.mail.recipients_count`, `kiln.mail.mailer` |
| `notification` | `NotificationSending` / `Sent` / `Failed` | `kiln.notification.class`, `kiln.notification.channel`, `kiln.notification.status` (`sent`\|`failed`) |
| `cache` | `CacheHit` / `CacheMissed` / `KeyWritten` / `KeyForgotten` (timed via `RetrievingKey`/`WritingKey`/`ForgettingKey`) | `kiln.cache.op` (`hit`\|`miss`\|`write`\|`forget`), `kiln.cache.key`, `kiln.cache.store` |
| `command` | `CommandStarting` / `CommandFinished` | `kiln.command.name`, `process.exit.code`; non-zero → ERROR |
| `scheduled_task` | `ScheduledTaskStarting` / `Finished` / `Failed` / `Skipped` | `kiln.schedule.name`, `kiln.schedule.expression`, `kiln.schedule.status` (`finished`\|`failed`\|`skipped`) |

- **Exceptions.** A `reportable()` callback adds an OTel `exception` span event to the current span with `exception.type`, `exception.message`, `exception.stacktrace` and `kiln.exception.handled=true`. An exception that escapes the application is flipped to `handled=false` and its span is set to ERROR. That covers exceptions the kernel renders into the response, failed and released jobs, failed scheduled tasks, and a non-zero command exit. PHP fatals are captured in the shutdown handler.
- **Logs.** Everything that goes through Laravel's logger is sent as OTLP logs with `traceId`/`spanId` from the active span. Context is redacted and stored as `kiln.context.*`. If you want to wire only some channels, set `logs_via=handler` and add the Monolog handler:

  ```php
  'channels' => ['kiln' => ['driver' => 'monolog', 'handler' => Kiln\Apm\Logging\OtlpHandler::class]],
  ```

- **Trace context.** An incoming `traceparent` is continued. Queued jobs carry the dispatcher's context. Outgoing HTTP propagates it.

## Configuration (`config/kiln-apm.php`)

| Key | Env | Default |
|---|---|---|
| `enabled` | `KILN_APM_ENABLED` | `true` |
| `service_name` | `KILN_SERVICE_NAME` / `OTEL_SERVICE_NAME` | slug of `app.name` |
| `socket` | `KILN_OTLP_SOCKET` | `unix:/run/kiln/otlp.sock` |
| `fallback_endpoint` | `KILN_OTLP_ENDPOINT` | `http://127.0.0.1:4318` (`null` disables it) |
| `timeout` | `KILN_APM_TIMEOUT` | `0.25` s |
| `sample_rates.{request,job,command,scheduled_task}` | `KILN_APM_*_SAMPLE_RATE` | `1.0`. Decides whether the whole trace is kept |
| `sample_rates.{query,outgoing_request,cache,mail,notification}` | | `1.0`, sampled per span |
| `sample_rates.logs` | `KILN_APM_LOG_SAMPLE_RATE` | `1.0` |
| `events.*` | | all `true`. A disabled type registers no listeners |
| `timeline` | | `true` |
| `max_spans_per_trace` | `KILN_APM_MAX_SPANS` | `1000` (extra spans are counted in `kiln.trace.dropped_spans`) |
| `max_logs`, `log_level`, `logs_via` | `KILN_APM_LOG_LEVEL`, `KILN_APM_LOGS_VIA` | `1000`, `debug`, `listener` |
| `redaction.keys` | | `password, token, secret, authorization, cookie, api_key` |
| `redaction.cache_keys` | | `[]` (`Str::is` patterns) |
| `redaction.query_literals` | | `true` |
| `redaction.callbacks` | | `[]`: `fn (array $attributes, string $eventType): array` |
| `ignore.paths` / `ignore.commands` / `ignore.jobs` | | `up`, `telescope*`, `horizon*`… / `queue:work`, `horizon`, `octane:*`… / `[]` |

To register a redaction callback at runtime:

```php
app(Kiln\Apm\Recorder::class)->redactUsing(function (array $attributes, string $type) {
    unset($attributes['client.address']);
    return $attributes;
});
```

Resource attributes are read from `KILN_ORG_ID`, `KILN_SITE_ID`, `KILN_SERVER_ID`, `KILN_DEPLOYMENT_ID` and `KILN_RELEASE_ID`. Deployments inject them, and the agent also sets them authoritatively.

## Tests

```bash
composer install
vendor/bin/pest
```

The suite includes a fake OTLP receiver (`tests/Fixtures/fake-otlp-server.php`). It runs as a real process, listens on a unix socket or TCP, and the tests assert on the exact HTTP bytes it receives.
