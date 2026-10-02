<?php

return [
    // PHP versions offered in the UI (must be allowed by runtime.php.install.schema.json).
    'php_versions' => ['8.1', '8.2', '8.3', '8.4', '8.5'],
    'default_php' => '8.4',

    // Releases where only some of those can be installed, keyed by the server's reported OS ("<id> <version>").
    // ppa:ondrej/php has no packages for Ubuntu 26.04 yet, so PHP comes from Ubuntu's archive there: 8.5 only.
    'php_versions_by_os' => [
        'ubuntu 26.04' => ['8.5'],
    ],

    // Installed for every PHP version (php<v>-<ext>).
    'php_extensions' => ['bcmath', 'cli', 'curl', 'gd', 'igbinary', 'intl', 'mbstring', 'mysql', 'pgsql', 'readline', 'redis', 'soap', 'sqlite3', 'xml', 'zip'],

    // Node majors offered, pinned to exact releases (provision.apply wants full semver).
    'node_versions' => [
        '20' => env('KILN_NODE_20', '20.19.5'),
        '22' => env('KILN_NODE_22', '22.20.0'),
        '24' => env('KILN_NODE_24', '24.9.0'),
    ],
    'default_node' => '22',

    'frankenphp' => [
        'version' => env('KILN_FRANKENPHP_VERSION', '1.9.1'),
        'sha256' => env('KILN_FRANKENPHP_SHA256'),
    ],

    // Optional download mirrors for runtimes fetched over HTTPS during provisioning (air-gapped installs, a
    // caching proxy in front of GitHub / nodejs.org). Unset = the upstream release URLs.
    //   KILN_FRANKENPHP_MIRROR  replaces https://github.com/php/frankenphp/releases/download
    //   KILN_NODE_MIRROR        replaces https://nodejs.org/dist
    'mirrors' => [
        'frankenphp' => env('KILN_FRANKENPHP_MIRROR'),
        'node' => env('KILN_NODE_MIRROR'),
    ],

    // Engine => Ubuntu packages + systemd service.
    'databases' => [
        'postgresql' => ['label' => 'PostgreSQL', 'packages' => ['postgresql', 'postgresql-contrib'], 'service' => 'postgresql'],
        'mysql' => ['label' => 'MySQL', 'packages' => ['mysql-server'], 'service' => 'mysql'],
        'mariadb' => ['label' => 'MariaDB', 'packages' => ['mariadb-server'], 'service' => 'mariadb'],
    ],
    'caches' => [
        'redis' => ['label' => 'Redis', 'packages' => ['redis-server'], 'service' => 'redis-server'],
        'valkey' => ['label' => 'Valkey', 'packages' => ['valkey-server'], 'service' => 'valkey-server'],
    ],
    'docker' => ['packages' => ['docker.io', 'docker-compose-v2', 'docker-buildx'], 'service' => 'docker'],

    'base_packages' => ['acl', 'ca-certificates', 'curl', 'fail2ban', 'git', 'htop', 'jq', 'rsync', 'sqlite3', 'unattended-upgrades', 'unzip', 'zip'],

    // Unix user owning sites and receiving synced SSH keys.
    'unix_user' => 'kiln',

    // Swap by RAM: first threshold (bytes) the machine is below wins; null = no managed swapfile.
    'swap' => [
        [2 * 1024 ** 3, 2048],
        [8 * 1024 ** 3, 4096],
        [PHP_INT_MAX, 0],
    ],

    'provision_timeout' => 1800,
];
