<?php

return [
    // Sized volumes: 16 MiB to 16 TiB (the agent's limits).
    'min_size_bytes' => 16 * 1024 * 1024,
    'max_size_bytes' => 16 * 1024 ** 4,

    // Host paths bind volumes may use (colon-separated). The agent checks its own FALAK_VOLUME_BIND_ALLOW too.
    'bind_allow' => array_values(array_filter(explode(':', (string) env('FALAK_VOLUME_BIND_ALLOW', '')))),

    // How often servers report their volumes' usage (volume.inventory), in minutes.
    'usage_refresh_minutes' => (int) env('FALAK_VOLUME_USAGE_MINUTES', 15),

    // File browser: downloads are capped (before compression) and their objects removed after a day.
    'download_max_bytes' => (int) env('FALAK_VOLUME_DOWNLOAD_MAX_BYTES', 1024 ** 3),
    'download_link_ttl' => 300,
    'downloads_keep_hours' => 24,

    // A volume deleted with its service waits this long for the service's containers to go.
    'delete_wait_s' => 120,

    // Agent command timeouts, in seconds (browse_wait: how long a browse request waits for the answer).
    'timeouts' => [
        'create' => 600,
        'resize' => 600,
        'delete' => 900,
        'inventory' => 300,
        'archive' => 4 * 3600,
        'restore' => 4 * 3600,
        'clone' => 4 * 3600,
        'browse' => 60,
        'download' => 3600,
        'drill' => 4 * 3600,
        'browse_wait' => 15,
    ],

    // Restore drills: how far the restored file count may be from the archive's.
    'drills' => [
        'tolerance_percent' => (float) env('FALAK_DRILL_TOLERANCE_PERCENT', 10),
    ],
];
