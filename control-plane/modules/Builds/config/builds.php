<?php

return [
    // Build timeout handed to kiln-builder (job timeout_s) and enforced by the control plane watchdog.
    'timeout' => (int) env('KILN_BUILD_TIMEOUT', 1800),
    // Extra time after timeout_s before the watchdog marks a running build timed out.
    'grace_seconds' => 120,
    // A running build whose builder (one that reports run ids) sent no heartbeat or event for this long is failed.
    'heartbeat_timeout_seconds' => (int) env('KILN_BUILD_HEARTBEAT_TIMEOUT', 90),
    // Queued builds no builder picks up fail after this long.
    'queue_ttl_seconds' => (int) env('KILN_BUILD_QUEUE_TTL', 3600),
    // Assigned builds that never report `started` are re-queued after this long (builder died).
    'assign_timeout_seconds' => 180,
    'max_attempts' => 3,
    // Long-poll cap for GET /api/internal/builds/next?wait=.
    'long_poll_max_seconds' => 25,
    'long_poll_interval_ms' => 1000,
    // A builder counts as online when it polled within this window.
    'builder_online_seconds' => 120,

    // Site environment variables with these prefixes are passed to builds (public front-end config), as are
    // the variables exposed to the deploy script (the user's opt-in for other build-time settings).
    'env_prefixes' => ['VITE_', 'NEXT_PUBLIC_', 'NUXT_PUBLIC_', 'PUBLIC_', 'REACT_APP_'],

    // The builder on the control-plane host (`kiln-builder serve --token $KILN_LOCAL_BUILDER_TOKEN`).
    // It serves every organization. Leave empty to only use `builder` servers.
    'local_builder' => [
        'token' => env('KILN_LOCAL_BUILDER_TOKEN'),
        'name' => env('KILN_LOCAL_BUILDER_NAME', 'control-plane'),
        // Build modes the host builder accepts; add `docker` only when it can reach a Docker daemon (BuildKit).
        'modes' => array_values(array_filter(array_map('trim', explode(',', (string) env('KILN_LOCAL_BUILDER_MODES', 'native'))))),
    ],

    // Builder servers: kiln-builder is installed by the agent when a `builder` server finishes provisioning.
    'builder_binary' => [
        // "{arch}" is replaced with amd64|arm64. Defaults to this control plane (/install/builder/linux-{arch}).
        'download_url' => env('KILN_BUILDER_DOWNLOAD_URL'),
        'binaries_path' => env('KILN_BUILDER_BINARIES_PATH', storage_path('kiln/builder')),
    ],

    'artifacts' => [
        // local | s3
        'driver' => env('KILN_ARTIFACTS_DRIVER', 'local'),
        // Presigned URL lifetimes.
        'upload_ttl' => 3600,
        'download_ttl' => 3600,
        'max_upload_bytes' => (int) env('KILN_ARTIFACTS_MAX_BYTES', 4 * 1024 ** 3),
        'local' => [
            'root' => env('KILN_ARTIFACTS_PATH', storage_path('kiln/artifacts')),
            // Public https base URL agents and builders reach the control plane at (deploy.fetch requires https).
            'url' => env('KILN_ARTIFACTS_URL', env('KILN_PANEL_URL', env('APP_URL', 'http://localhost'))),
        ],
        's3' => [
            'endpoint' => env('KILN_ARTIFACTS_S3_ENDPOINT'),
            'region' => env('KILN_ARTIFACTS_S3_REGION', 'us-east-1'),
            'bucket' => env('KILN_ARTIFACTS_S3_BUCKET'),
            'key' => env('KILN_ARTIFACTS_S3_KEY'),
            'secret' => env('KILN_ARTIFACTS_S3_SECRET'),
            'prefix' => env('KILN_ARTIFACTS_S3_PREFIX', 'artifacts'),
            'path_style' => (bool) env('KILN_ARTIFACTS_S3_PATH_STYLE', false),
        ],
        // Keep the newest N successful artifacts per site; older ones are deleted by the daily prune.
        'keep_per_site' => (int) env('KILN_ARTIFACTS_KEEP', 10),
        'max_age_days' => (int) env('KILN_ARTIFACTS_MAX_AGE_DAYS', 90),
    ],

    // Build log lines are deleted after this many days (the build rows stay as history).
    'log_retention_days' => 30,

    // Built-in registry for docker builds (distribution registry). Images: <url>/<namespace>/<site-slug>:<build-id>.
    'registry' => [
        'url' => env('KILN_REGISTRY_URL', 'registry.kiln.local'),
        'namespace' => env('KILN_REGISTRY_NAMESPACE', 'kiln'),
        'username' => env('KILN_REGISTRY_USERNAME'),
        'password' => env('KILN_REGISTRY_PASSWORD'),
    ],
];
