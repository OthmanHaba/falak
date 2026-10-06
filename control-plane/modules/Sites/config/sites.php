<?php

return [
    // Site directories live under <root>/<slug> (releases/, shared/, current).
    'root' => env('FALAK_SITES_ROOT', '/srv/falak/sites'),

    // Every site gets <slug>.<test_domain> when set (wildcard DNS pointing at your servers / load balancers).
    'test_domain' => env('FALAK_TEST_DOMAIN'),

    // Linux user owning non-isolated sites (matches the servers' provisioned user).
    'unix_user' => 'falak',

    // PHP versions a site can pin (runtime.fpm.pool.schema.json enum) and Node majors.
    'php_versions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],
    'node_versions' => ['18', '20', '22', '24'],
    'default_node' => '22',

    // Installed on a server when a Bun / Deno site first targets it (official release binaries).
    'bun_version' => env('FALAK_BUN_VERSION', '1.4.2'),
    'deno_version' => env('FALAK_DENO_VERSION', '2.9.7'),
    // Optional release download mirrors (unset = GitHub releases): FALAK_BUN_MIRROR replaces
    // https://github.com/oven-sh/bun/releases/download, FALAK_DENO_MIRROR https://github.com/denoland/deno/releases/download.
    'bun_mirror' => env('FALAK_BUN_MIRROR'),
    'deno_mirror' => env('FALAK_DENO_MIRROR'),

    // Ports handed out to node/bun/deno/docker sites (unique per server).
    'app_port_range' => [3000, 3999],
    // Docker sites: the in-container port when the create form / API gives none.
    'default_container_port' => (int) env('FALAK_DEFAULT_CONTAINER_PORT', 3000),

    // Laravel Octane listens on 127.0.0.1:<port> from base..base+span-1 (unique per server, persisted per site);
    // its admin / RPC port is port + 10000 (LaravelSettings::OCTANE_AUX_PORT_OFFSET).
    'octane_port_base' => 8000,
    'octane_port_span' => 1000,

    // Docker Compose sites (docs/COMPOSE_TEMPLATES.md §1).
    'compose' => [
        // cap_add values allowed without "Allow privileged compose" (Docker's default capability set).
        'safe_capabilities' => ['AUDIT_WRITE', 'CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID', 'KILL', 'MKNOD', 'NET_BIND_SERVICE', 'NET_RAW', 'SETFCAP', 'SETGID', 'SETPCAP', 'SETUID', 'SYS_CHROOT'],
        'max_bytes' => 262144,
        // Inline compose versions kept per site (the newest N; older ones are pruned on save).
        'keep_versions' => 50,
        // Refresh docker.compose.ps for the Services tab at most this often (seconds).
        'status_refresh_seconds' => 10,
    ],

    // The agents' tmpfs directory for env files and Laravel's config cache (agent: <run dir>/env).
    'env_dir' => '/run/falak/env',

    // Site commands (system.exec).
    'command_timeout' => (int) env('FALAK_SITE_COMMAND_TIMEOUT', 600),
    'command_history' => 50,

    // Which site variables hold secrets (SecretVariables): masked in deploy, hook, build, command and process output, and
    // passed as files in the secrets mode `files`. Matched against the upper-cased name; `except` wins unless the name
    // also matches `always`. A variable whose value carries credentials (scheme://user:password@…) or references a
    // secret of another service (`${{ db.DB_PASSWORD }}`) is secret too. The secret store (v0.10.0 step 1b) replaces
    // these patterns with each secret's `sensitive` flag.
    'secret_variables' => [
        'patterns' => [
            '/PASSWORD/', '/(^|_)PASS(WD)?($|_)/', '/(^|_)PWD$/', '/SECRET/', '/TOKEN/', '/PRIVATE/', '/CREDENTIAL/', '/APIKEY/',
            '/(^|_)KEYS?$/', '/(^|_)AUTH($|_)/', '/(^|_)DSN$/', '/(^|_)SALT$/', '/PASSPHRASE/', '/CONNECTION_STRING/',
            '/^(DATABASE|DB|REDIS|MONGO(DB)?|POSTGRES(QL)?|PG|MYSQL|MARIADB|AMQP|RABBITMQ|BROKER|CACHE|QUEUE|ELASTICSEARCH|CLICKHOUSE)_UR[IL]S?$/',
            '/_(DATABASE|DB|REDIS|MONGO(DB)?|POSTGRES(QL)?|MYSQL)_UR[IL]S?$/',
        ],
        // Identifiers and values meant for browsers are not secrets: AWS_ACCESS_KEY_ID (only with its secret key),
        // publishable keys, and anything a frontend build inlines.
        'except' => [
            '/^FALAK_/', '/_KEY_ID$/', '/(^|_)PUBLIC(_|$)/', '/PUBLISHABLE/', '/^(VITE|MIX|NEXT_PUBLIC|NUXT_PUBLIC|EXPO_PUBLIC|REACT_APP|GATSBY|PUBLIC)_/',
        ],
        // Names that stay secret whatever `except` says (VITE_API_SECRET is a secret wrongly sent to the browser).
        'always' => ['/SECRET/', '/PASSWORD/', '/TOKEN/', '/PRIVATE/'],
    ],
];
