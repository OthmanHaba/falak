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
    // Cache engines each release's archive has, keyed by the server's reported OS ("<id> <version>"); releases not
    // listed get Redis only. valkey-server is in Ubuntu's archive from 26.04 (8.1) and in Debian from 13.
    'caches_by_os' => [
        'ubuntu 22.04' => ['redis'],
        'ubuntu 24.04' => ['redis'],
        'ubuntu 26.04' => ['redis', 'valkey'],
        'debian 12' => ['redis'],
        'debian 13' => ['redis', 'valkey'],
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

    // Machine check before provisioning (docs/plans/MACHINE_CHECK.md): provision.inspect reports what is on the machine,
    // Servers\Domain\MachineCheck\DecisionEngine decides per component with these rules.
    'machine_check' => [
        'timeout' => 180,

        // Lowest versions Kiln adopts or completes; anything older blocks. Compared with version_compare on the
        // upstream version (Debian epoch and revision stripped; PostgreSQL by major from postgresql-NN). Each is what
        // Kiln itself installs on the oldest supported release, so a server Kiln provisioned never blocks: Ubuntu 22.04
        // ships PostgreSQL 14, MySQL 8.0, MariaDB 10.6, Redis 6.0 and docker.io 20.10 (24.0 / 26.1 in jammy-updates);
        // Valkey first ships with 26.04 (8.1), 7.2 is its first release.
        'minimum_versions' => [
            'docker' => '20.10',
            'postgresql' => '14',
            'mysql' => '8.0',
            'mariadb' => '10.6',
            'redis' => '6.0',
            'valkey' => '7.2',
        ],

        // Docker package families: a missing piece is completed from the engine's own family (never mixed: Ubuntu's
        // docker-buildx overwrites the files of Docker's docker-buildx-plugin). `repo` must be an apt source for the
        // family's packages to be installable.
        'docker_families' => [
            'docker-ce' => ['label' => "Docker's repository", 'compose' => 'docker-compose-plugin', 'buildx' => 'docker-buildx-plugin', 'repo' => 'download.docker.com'],
            'docker.io' => ['label' => "Ubuntu's archive", 'compose' => 'docker-compose-v2', 'buildx' => 'docker-buildx', 'repo' => null],
        ],

        // Engines Kiln can install or adopt, and the ones that conflict with them (same kind, same port). `packages`
        // are regular expressions over installed package names; `processes` may hold the engine's port.
        'engines' => [
            'postgresql' => ['label' => 'PostgreSQL', 'kind' => 'database', 'packages' => ['/^postgresql-\d+$/'], 'processes' => ['postgres'], 'ports' => [5432]],
            'mysql' => ['label' => 'MySQL', 'kind' => 'database', 'packages' => ['/^mysql-server-core-\d/', '/^mysql-server-\d/', '/^mysql-community-server$/', '/^mysql-server$/'], 'processes' => ['mysqld'], 'ports' => [3306]],
            'mariadb' => ['label' => 'MariaDB', 'kind' => 'database', 'packages' => ['/^mariadb-server-core/', '/^mariadb-server-\d/', '/^mariadb-server$/'], 'processes' => ['mariadbd', 'mysqld'], 'ports' => [3306]],
            'percona' => ['label' => 'Percona Server', 'kind' => 'database', 'packages' => ['/^percona-server-server/'], 'processes' => ['mysqld'], 'ports' => [3306]],
            'redis' => ['label' => 'Redis', 'kind' => 'cache', 'packages' => ['/^redis-server$/'], 'processes' => ['redis-server'], 'ports' => [6379]],
            'valkey' => ['label' => 'Valkey', 'kind' => 'cache', 'packages' => ['/^valkey-server$/'], 'processes' => ['valkey-server'], 'ports' => [6379]],
        ],

        // Ports the edge needs on servers that serve HTTP (Caddy's admin API on 2019 is bound to localhost).
        'edge_ports' => [80, 443, 2019],

        // Web servers that would take the edge's ports.
        'web_servers' => ['nginx' => 'nginx', 'apache2' => 'Apache'],
    ],
];
