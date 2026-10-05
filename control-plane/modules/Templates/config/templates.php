<?php

return [
    // Curated catalog: templates/<slug>/{template.yaml, compose.yaml, icon.svg?} (docs/COMPOSE_TEMPLATES.md §2).
    // The production image bakes it into /opt/falak/templates (FALAK_TEMPLATES_PATH).
    'catalog_path' => env('FALAK_TEMPLATES_PATH', base_path('../templates')),

    // Parsed catalog cache; the key includes the files' modification times, so edits are picked up.
    'cache_ttl' => (int) env('FALAK_TEMPLATES_CACHE_TTL', 3600),

    // Custom (organization) templates.
    'max_bytes' => 256 * 1024,          // template.yaml + compose.yaml, each
    'max_custom_per_organization' => 200,

    // Import from URL: https only, public addresses only (SSRF guard), no redirects to anywhere else.
    'fetch' => [
        'timeout' => 10,
        'max_redirects' => 3,
    ],
];
