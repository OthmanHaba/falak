<?php

use Falak\Databases\Application\ContainerNetworks;

$dockerNetworks = ContainerNetworks::parse(env('FALAK_DOCKER_NETWORKS', implode(',', ContainerNetworks::DEFAULT)));

return [
    // Supported engine versions (shown in the UI; detected versions outside this list are kept but flagged).
    'versions' => [
        'mysql' => ['8.0', '8.4'],
        'mariadb' => ['10.11', '11.4', '11.8'],
        'postgresql' => ['16', '17', '18'],
        'redis' => ['6.0', '7.0', '7.2', '7.4', '8.0'],
        'valkey' => ['7.2', '8.0', '8.1', '9.0'],
    ],

    // Version installed by the distro packages provisioning uses, when the agent does not report one
    // (facts.runtimes.<engine>). Keyed by "<os id> <os version>"; an engine missing for a release falls back to
    // Ubuntu 24.04's. Valkey (servers.caches_by_os): noble-updates 7.2, resolute 9.0, trixie 8.1; resolute (26.04):
    // PostgreSQL 18, MySQL 8.4, MariaDB 11.8, Redis 8.0 (packages.ubuntu.com, packages.debian.org, 2026-10).
    'distro_versions' => [
        'ubuntu 24.04' => ['mysql' => '8.0', 'mariadb' => '10.11', 'postgresql' => '16', 'redis' => '7.0', 'valkey' => '7.2'],
        'ubuntu 22.04' => ['mysql' => '8.0', 'mariadb' => '10.6', 'postgresql' => '14', 'redis' => '6.0'],
        'ubuntu 26.04' => ['mysql' => '8.4', 'mariadb' => '11.8', 'postgresql' => '18', 'redis' => '8.0', 'valkey' => '9.0'],
        'debian 12' => ['mysql' => '8.0', 'mariadb' => '10.11', 'postgresql' => '15', 'redis' => '7.0'],
        'debian 13' => ['redis' => '8.0', 'valkey' => '8.1'],
    ],

    // Redis / Valkey instances (one process each, redis-server@falak-<name>): ports Falak allocates (the stock
    // instance keeps 6379), and the defaults of a new instance.
    'key_value' => [
        'ports' => [6380, 6479],
        'maxmemory_mb' => 128,
        'eviction' => 'noeviction',
        'persistence' => 'rdb',
        'evictions' => ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl'],
        'persistences' => ['rdb', 'aof', 'none'],
        // Providers whose servers of one account and region share a private network by default, used when no Falak
        // private network connects an instance's (or a dedicated SQL database server's) server with a site's:
        // DigitalOcean (each region's default VPC) and Lightsail (instances of a region reach each other's private IP).
        // Not Hetzner, Vultr, Linode: their private networks are opt-in and can differ per server, so a private IPv4
        // says nothing about who shares it. Servers must be created by Falak with the same provider credential, in the
        // same region.
        'provider_private_networks' => ['digitalocean', 'lightsail'],
        // Custom servers (Redis / Valkey and dedicated SQL database servers alike): their private IPv4s are taken as
        // one network (never in production — they may be NATed or in different networks). The sim's fleet network
        // uses it.
        'custom_private_network' => (bool) env('FALAK_REDIS_CUSTOM_PRIVATE_NETWORK', false),
    ],

    'password_length' => 32,

    // Docker address ranges containers connect from (Docker's default address pools). Engines on app/worker servers
    // accept these ranges, on the Docker bridges only (firewall), so compose stacks, Docker sites and functions on
    // the same server reach them on the host address. Change them if the Docker daemon uses other pools. IPv4 CIDRs,
    // /8–/30; invalid entries are dropped (and logged), Docker's defaults apply when none is valid.
    'container_networks' => $dockerNetworks['networks'],
    'container_networks_invalid' => $dockerNetworks['invalid'],

    // The Docker default bridge's address (docker0) that containers on a server use to reach its PostgreSQL / MySQL /
    // MariaDB engines, when no Redis / Valkey instance there has reported the real one. Docker's default; change it
    // when the daemon sets another `bip`.
    'docker_bridge_host' => env('FALAK_DOCKER_BRIDGE_HOST', '172.17.0.1'),

    // Agent command timeouts (seconds).
    'timeouts' => [
        'ddl' => 300,
        // db.redis.apply: longer than the agent's own waits (a start loading a big dataset up to 20 min, an AOF
        // rewrite up to 15 min); the agent ends its waits before this deadline.
        'redis_apply' => (int) env('FALAK_REDIS_APPLY_TIMEOUT', 3600),
        'backup' => 3600,
        'restore' => 3600,
    ],

    // Presigned URL lifetimes (seconds). The upload URL must outlive queueing + the dump itself.
    'upload_url_ttl' => (int) env('FALAK_BACKUP_UPLOAD_URL_TTL', 12 * 3600),
    'download_url_ttl' => (int) env('FALAK_BACKUP_DOWNLOAD_URL_TTL', 6 * 3600),
    // "Download" links of the panel: a short-lived presigned GET, opened right away by the browser.
    'download_link_ttl' => (int) env('FALAK_BACKUP_DOWNLOAD_LINK_TTL', 300),

    // Control-plane → object storage requests (verification, pruning).
    'storage_timeout' => 30,

    // Allow storage endpoints on private / loopback / link-local addresses (self-hosted MinIO on a LAN).
    // Off by default: otherwise any storage admin could make the control plane probe internal services.
    'allow_private_endpoints' => (bool) env('FALAK_STORAGE_ALLOW_PRIVATE_ENDPOINTS', false),
];
