<?php

return [
    // Alert history and in-app notifications older than this are pruned daily.
    'retention_days' => (int) env('FALAK_ALERTING_RETENTION_DAYS', 90),

    // Generic webhooks may not target loopback / private / link-local addresses unless enabled.
    'allow_private_webhooks' => (bool) env('FALAK_ALERTING_ALLOW_PRIVATE_WEBHOOKS', false),

    // HTTP timeout (seconds) for channel deliveries.
    'http_timeout' => 10,

    // Queue used for routing and delivery jobs.
    'queue' => env('FALAK_ALERTING_QUEUE', 'default'),

    'telegram_api' => env('FALAK_TELEGRAM_API', 'https://api.telegram.org'),
];
