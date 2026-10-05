<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Falak APM
    |--------------------------------------------------------------------------
    |
    | Telemetry is buffered in memory for the duration of a request / job /
    | command and sent as OTLP/HTTP JSON to the local falak-agent *after* the
    | response has been sent. Failures are dropped silently.
    |
    */

    'enabled' => (bool) env('FALAK_APM_ENABLED', true),

    // Defaults to a slug of app.name when empty. The agent overrides it with the site slug.
    'service_name' => env('FALAK_SERVICE_NAME', env('OTEL_SERVICE_NAME')),

    // Primary transport: OTLP/HTTP over the agent's unix socket.
    'socket' => env('FALAK_OTLP_SOCKET', 'unix:/run/falak/otlp.sock'),

    // Used when the socket is missing / unreachable. Set to null to disable.
    'fallback_endpoint' => env('FALAK_OTLP_ENDPOINT', 'http://127.0.0.1:4318'),

    // Connect + write + read budget per endpoint, in seconds.
    'timeout' => (float) env('FALAK_APM_TIMEOUT', 0.25),

    /*
    | Sample rates (0.0 – 1.0). Root types (request, job, command, scheduled_task)
    | decide whether a whole trace is kept. Child types are sampled per span
    | inside a kept trace. Logs are sampled per record.
    */
    'sample_rates' => [
        'request' => (float) env('FALAK_APM_REQUEST_SAMPLE_RATE', 1.0),
        'job' => (float) env('FALAK_APM_JOB_SAMPLE_RATE', 1.0),
        'command' => (float) env('FALAK_APM_COMMAND_SAMPLE_RATE', 1.0),
        'scheduled_task' => (float) env('FALAK_APM_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
        'query' => 1.0,
        'outgoing_request' => 1.0,
        'cache' => 1.0,
        'mail' => 1.0,
        'notification' => 1.0,
        'logs' => (float) env('FALAK_APM_LOG_SAMPLE_RATE', 1.0),
    ],

    // Per event type toggles. Disabled types register no listeners at all.
    'events' => [
        'request' => true,
        'query' => true,
        'job' => true,
        'outgoing_request' => true,
        'mail' => true,
        'notification' => true,
        'cache' => true,
        'command' => true,
        'scheduled_task' => true,
        'exceptions' => true,
        'logs' => true,
    ],

    // Record bootstrap / middleware / controller / response phases as child spans of requests.
    'timeline' => true,

    // Hard caps to bound memory in long requests and workers.
    'max_spans_per_trace' => (int) env('FALAK_APM_MAX_SPANS', 1000),
    'max_logs' => 1000,

    // Minimum PSR-3 level captured as OTLP logs.
    'log_level' => env('FALAK_APM_LOG_LEVEL', 'debug'),

    // 'listener': capture every log written through Laravel's logger (all channels).
    // 'handler': capture only channels that use Falak\Apm\Logging\OtlpHandler.
    'logs_via' => env('FALAK_APM_LOGS_VIA', 'listener'),

    'redaction' => [
        // Attribute keys, header names, query-string params and log context keys containing
        // any of these words (case-insensitive) are replaced.
        'keys' => ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key'],

        'replacement' => '[redacted]',

        // Cache keys matching these patterns (Str::is) are replaced. Keys containing a
        // denylisted word are always replaced.
        'cache_keys' => [],

        // Replace quoted string literals in SQL with `?` (bindings are never sent).
        'query_literals' => true,

        // Callables `fn (array $attributes, string $eventType): array` run at flush time,
        // e.g. [App\Support\ApmRedactor::class, 'redact'] or 'App\Support\ApmRedactor@redact'.
        'callbacks' => [],
    ],

    'ignore' => [
        // Request paths (Str::is patterns, without leading slash).
        'paths' => ['up', 'telescope*', 'horizon*', '_debugbar*', 'pulse*'],

        // Commands that are long-running processes or noise. Workers get one trace per job instead.
        'commands' => [
            'queue:work', 'queue:listen', 'horizon', 'horizon:*', 'octane:*', 'reverb:start',
            'schedule:work', 'schedule:finish', 'serve', 'tinker', 'pail', 'list', 'help',
            'package:discover', 'vendor:publish',
        ],

        // Job classes (Str::is patterns).
        'jobs' => [],
    ],

];
