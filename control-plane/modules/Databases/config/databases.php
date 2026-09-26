<?php

return [
    // Supported engine versions (shown in the UI; detected versions outside this list are kept but flagged).
    'versions' => [
        'mysql' => ['8.0', '8.4'],
        'mariadb' => ['10.11', '11.4'],
        'postgresql' => ['16', '17'],
    ],

    // Version installed by the distro packages provisioning uses, when the agent does not report one
    // (facts.runtimes.<engine>). Keyed by "<os id> <os version>".
    'distro_versions' => [
        'ubuntu 24.04' => ['mysql' => '8.0', 'mariadb' => '10.11', 'postgresql' => '16'],
        'ubuntu 22.04' => ['mysql' => '8.0', 'mariadb' => '10.6', 'postgresql' => '14'],
        'debian 12' => ['mysql' => '8.0', 'mariadb' => '10.11', 'postgresql' => '15'],
    ],

    'password_length' => 32,

    // Agent command timeouts (seconds).
    'timeouts' => [
        'ddl' => 300,
        'backup' => 3600,
        'restore' => 3600,
    ],

    // Presigned URL lifetimes (seconds). The upload URL must outlive queueing + the dump itself.
    'upload_url_ttl' => (int) env('KILN_BACKUP_UPLOAD_URL_TTL', 12 * 3600),
    'download_url_ttl' => (int) env('KILN_BACKUP_DOWNLOAD_URL_TTL', 6 * 3600),

    // Control-plane → object storage requests (verification, pruning).
    'storage_timeout' => 30,
];
