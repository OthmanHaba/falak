<?php

return [
    // Site directories live under <root>/<slug> (releases/, shared/, current).
    'root' => env('KILN_SITES_ROOT', '/srv/kiln/sites'),

    // Every site gets <slug>.<test_domain> when set (wildcard DNS pointing at your servers / load balancers).
    'test_domain' => env('KILN_TEST_DOMAIN'),

    // Linux user owning non-isolated sites (matches the servers' provisioned user).
    'unix_user' => 'kiln',

    // PHP versions a site can pin (runtime.fpm.pool.schema.json enum) and Node majors.
    'php_versions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],
    'node_versions' => ['18', '20', '22', '24'],
    'default_node' => '22',

    // Installed on a server when a Bun / Deno site first targets it (official release binaries).
    'bun_version' => env('KILN_BUN_VERSION', '1.4.2'),
    'deno_version' => env('KILN_DENO_VERSION', '2.9.7'),

    // Ports handed out to node/bun/deno/docker sites (unique per server).
    'app_port_range' => [3000, 3999],

    // Site commands (system.exec).
    'command_timeout' => (int) env('KILN_SITE_COMMAND_TIMEOUT', 600),
    'command_history' => 50,
];
