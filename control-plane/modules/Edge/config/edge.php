<?php

return [
    // ACME account e-mail / directory passed to every edge.caddy.apply (Caddy defaults when empty).
    'acme_email' => env('KILN_ACME_EMAIL'),
    'acme_ca' => env('KILN_ACME_CA'),

    // TLS for hosted test domains (<slug>.<KILN_TEST_DOMAIN>): "acme" or "internal" (local / private setups).
    'test_domain_tls' => env('KILN_TEST_DOMAIN_TLS', 'acme'),

    // Debounce window: bursts of changes within this many seconds collapse into one apply per server.
    'apply_delay_seconds' => (int) env('KILN_EDGE_APPLY_DELAY', 2),

    // Octane sites: how long the edge retries connecting to Octane (it restarts on every deploy) before a 502.
    'octane_try_duration_seconds' => 30,

    'apply_timeout_seconds' => 120,
    'cert_install_timeout_seconds' => 60,
];
