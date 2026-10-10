<?php

return [
    // Agent command timeouts (seconds) per step.
    'timeouts' => [
        'fetch' => 900,
        'prepare' => 300,
        'hook' => (int) env('FALAK_DEPLOY_HOOK_TIMEOUT', 1800),
        'activate' => 120,
        // docker compose up --wait (added to compose.wait_timeout).
        'compose_up' => 600,
        'restart' => 300,
        'rollback' => 120,
        'swap' => 900,
        'prune' => 300,
        // fn.release.apply: write the release, install its dependencies, boot it and switch the gateway.
        'function' => 600,
    ],

    // Presigned artifact URL lifetime handed to deploy.fetch.
    'artifact_url_ttl' => 3600,

    // Defaults for new sites (Deployments → Settings).
    'defaults' => [
        'keep_releases' => 5,
        'batch_size' => 1,
        'health' => [
            'enabled' => true,
            'status' => 200,
            'timeout_s' => 10,
            'retries' => 3,
            'retry_delay_s' => 5,
        ],
        'watch' => [
            'minutes' => 5,
            'health_failures' => 3,
        ],
    ],

    // Rollback after a release goes live (Settings → Deploy → "Watch after deploy", opt-in per site): the watch
    // window's health checks run every 30 s; the 5xx trigger fires above max(baseline × factor, error_rate) once the
    // release served at least min_requests; at most one automatic rollback per site per cooldown_minutes.
    'watch' => [
        'min_requests' => (int) env('FALAK_WATCH_MIN_REQUESTS', 20),
        'error_rate' => 0.05,
        'baseline_factor' => 3,
        'baseline_minutes' => 60,
        'cooldown_minutes' => 60,
    ],

    // A deployment triggered while some of the site's servers are still being prepared waits for them (status
    // `waiting`), and fails if they are not ready after this many minutes.
    'waiting' => [
        'timeout_minutes' => (int) env('FALAK_DEPLOY_WAIT_TIMEOUT_MINUTES', 30),
    ],

    // A deployment step still running after its timeout + this grace is reconciled from the agent.
    'reconcile_after_seconds' => 120,

    // Docker Compose sites: `docker compose up --wait --wait-timeout` (seconds; healthchecks with long start periods need more).
    'compose' => [
        'wait_timeout' => (int) env('FALAK_COMPOSE_WAIT_TIMEOUT', 300),
    ],

    // Blue/green: the "green" host port is the site's app port + this offset.
    'green_port_offset' => 1000,

    'output_page_size' => 1000,

    // Seconds a trigger waits for another trigger of the same site to finish queueing (then 409, retry).
    'trigger_lock_wait' => 15,
];
