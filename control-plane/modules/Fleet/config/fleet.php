<?php

return [
    /*
     * Internal CA. The CA certificate (never the key) is written to <ca_path>/ca.pem so the edge
     * (Caddy/FrankenPHP) can verify agent client certificates.
     */
    'ca_path' => env('KILN_CA_PATH', storage_path('kiln/ca')),
    'ca_validity_years' => 10,
    'cert_validity_days' => 90,

    /*
     * mTLS is terminated at the edge, which forwards the client-certificate fingerprint. The header is
     * only trusted when the TCP peer (REMOTE_ADDR) is one of these proxies (comma-separated CIDRs).
     */
    'fingerprint_header' => 'X-Kiln-Client-Cert-Fingerprint',
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('KILN_AGENT_TRUSTED_PROXIES', '127.0.0.1/32,::1/128'))))),

    // Public URLs handed to agents. Defaults derive from APP_URL.
    'panel_url' => env('KILN_PANEL_URL'),
    'api_url' => env('KILN_AGENT_API_URL'),
    'otlp_endpoint' => env('KILN_OTLP_ENDPOINT', 'http://localhost:4318'),

    'install_token_ttl_minutes' => (int) env('KILN_INSTALL_TOKEN_TTL', 60 * 24),

    'agent' => [
        // Where installers download kiln-agent from. "{arch}" is replaced with amd64|arm64.
        // Defaults to this control plane serving binaries from `binaries_path`.
        'download_url' => env('KILN_AGENT_DOWNLOAD_URL'),
        'binaries_path' => env('KILN_AGENT_BINARIES_PATH', storage_path('kiln/agent')),
        // Optional pinned checksums when downloading from an external URL: ['amd64' => '<sha256>', ...]
        'checksums' => array_filter([
            'amd64' => env('KILN_AGENT_SHA256_AMD64'),
            'arm64' => env('KILN_AGENT_SHA256_ARM64'),
        ]),
    ],

    // Heartbeats arrive every 15s; an agent is offline after this many seconds of silence.
    'offline_after_seconds' => (int) env('KILN_AGENT_OFFLINE_AFTER', 60),

    // Long-poll wake-up: "redis" (BLPOP) or "database" (polls fleet_commands; works everywhere).
    'wake_driver' => env('KILN_AGENT_WAKE_DRIVER', 'database'),
    'wake_redis_connection' => env('KILN_AGENT_WAKE_REDIS', 'default'),
    'database_poll_interval_ms' => 500,
    'long_poll_max_seconds' => 60,

    'commands' => [
        // Delivered-but-not-started commands are re-queued after this long (agents dedupe by id).
        'redeliver_after_seconds' => 90,
        'max_attempts' => 5,
        // Queued commands the agent never picks up expire after this long.
        'queue_ttl_seconds' => 3600,
        // Extra time beyond timeout_s before a running command is marked timed out.
        'grace_seconds' => 60,
        'max_event_batch_bytes' => 8 * 1024 * 1024,
    ],

    'metrics_retention_hours' => 24,

    // JSON Schemas shared with the agent (contracts/agent-protocol).
    'schemas_path' => env('KILN_AGENT_SCHEMAS_PATH', base_path('../contracts/agent-protocol')),
];
