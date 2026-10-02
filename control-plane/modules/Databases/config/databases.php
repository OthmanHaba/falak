<?php

use Kiln\Databases\Application\ContainerNetworks;

$dockerNetworks = ContainerNetworks::parse(env('KILN_DOCKER_NETWORKS', implode(',', ContainerNetworks::DEFAULT)));

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

    // Docker address ranges containers connect from (Docker's default address pools). Engines on app/worker servers
    // accept these ranges, on the Docker bridges only (firewall), so compose stacks, Docker sites and functions on
    // the same server reach them on the host address. Change them if the Docker daemon uses other pools. IPv4 CIDRs,
    // /8–/30; invalid entries are dropped (and logged), Docker's defaults apply when none is valid.
    'container_networks' => $dockerNetworks['networks'],
    'container_networks_invalid' => $dockerNetworks['invalid'],

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

    // Allow storage endpoints on private / loopback / link-local addresses (self-hosted MinIO on a LAN).
    // Off by default: otherwise any storage admin could make the control plane probe internal services.
    'allow_private_endpoints' => (bool) env('KILN_STORAGE_ALLOW_PRIVATE_ENDPOINTS', false),
];
