<?php

use Kiln\Servers\Domain\MachineCheck\DecisionEngine;
use Kiln\Servers\Domain\MachineCheck\MachineCheck;
use Kiln\Servers\Domain\MachineCheck\MachineReport;
use Kiln\Servers\Domain\MachineCheck\Wanted;
use Kiln\Servers\Domain\Stack\Stack;

/**
 * provision.inspect reports for tests: a fresh Ubuntu 24.04 machine (only openssh-server, root with a key), changed with
 * the mc_* helpers below.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mc_report(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'hostname' => 'ubuntu-s-1vcpu',
        'os' => ['id' => 'ubuntu', 'version' => '24.04', 'codename' => 'noble'],
        'in_container' => false,
        'packages' => [
            ['name' => 'openssh-server', 'version' => '1:9.6p1-3ubuntu13.5', 'origin' => 'archive', 'repo' => 'http://archive.ubuntu.com/ubuntu', 'label' => 'Ubuntu'],
        ],
        'snaps' => [],
        'apt_sources' => [['file' => '/etc/apt/sources.list.d/ubuntu.sources', 'uris' => ['http://archive.ubuntu.com/ubuntu/']]],
        'services' => [['unit' => 'ssh.service', 'active' => 'active', 'enabled' => 'enabled']],
        'listeners' => [['port' => 22, 'address' => '0.0.0.0', 'process' => 'sshd', 'pid' => 800, 'unit' => 'ssh.service']],
        'containers' => [],
        'docker' => null,
        'ssh' => [
            'drop_ins' => [],
            'effective' => ['passwordauthentication' => 'yes', 'permitrootlogin' => 'prohibit-password', 'port' => '22', 'pubkeyauthentication' => 'yes'],
            'effective_source' => 'sshd -T',
            'users' => [['name' => 'root', 'uid' => 0, 'authorized_keys' => 1]],
        ],
        'firewall' => ['ufw' => 'inactive', 'firewalld' => 'absent', 'nft_tables' => []],
        'swap' => [],
        'node' => [],
        'php' => [],
        'frankenphp' => [],
        'unattended_upgrades' => ['installed' => false, 'periodic' => null, 'managed_by_kiln' => false],
        'fail2ban' => ['installed' => false, 'active' => false, 'jails' => []],
        'errors' => [],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $report
 * @param  string  $origin  archive, vendor or manual
 * @return array<string, mixed>
 */
function mc_package(array $report, string $name, string $version, string $origin = 'archive', ?string $repo = null, ?string $label = null): array
{
    $repo ??= $origin === 'archive' ? 'http://archive.ubuntu.com/ubuntu' : null;
    $label ??= $origin === 'archive' ? 'Ubuntu' : null;
    $report['packages'][] = array_filter(['name' => $name, 'version' => $version, 'origin' => $origin, 'repo' => $repo, 'label' => $label], fn ($v) => $v !== null);

    return $report;
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function mc_service(array $report, string $unit, string $active = 'active', string $enabled = 'enabled'): array
{
    $report['services'][] = ['unit' => $unit, 'active' => $active, 'enabled' => $enabled];

    return $report;
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function mc_listen(array $report, int $port, string $process, ?string $unit = null, bool $container = false, string $address = '0.0.0.0'): array
{
    $report['listeners'][] = array_filter(['port' => $port, 'address' => $address, 'process' => $process, 'pid' => 4242, 'unit' => $unit, 'container' => $container ?: null], fn ($v) => $v !== null);

    return $report;
}

/**
 * Docker from Docker's repository (docker-ce), with the given plugins installed from it.
 *
 * @param  array<string, mixed>  $report
 * @param  list<string>  $plugins  compose, buildx
 * @return array<string, mixed>
 */
function mc_docker_ce(array $report, array $plugins = ['compose', 'buildx'], bool $repoConfigured = true): array
{
    $repo = 'https://download.docker.com/linux/ubuntu';
    $origin = $repoConfigured ? 'vendor' : 'manual';
    $report = mc_package($report, 'docker-ce', '5:28.1.1-1~ubuntu.24.04~noble', $origin, $repoConfigured ? $repo : null, $repoConfigured ? 'Docker' : null);
    $report = mc_package($report, 'containerd.io', '1.7.27-1', $origin, $repoConfigured ? $repo : null, $repoConfigured ? 'Docker' : null);

    if ($repoConfigured) {
        $report['apt_sources'][] = ['file' => '/etc/apt/sources.list.d/docker.list', 'uris' => [$repo]];
    }

    $docker = ['engine_package' => 'docker-ce', 'client_version' => '28.1.1', 'server_version' => '28.1.1', 'compose' => null, 'buildx' => null, 'snap' => false, 'rootless' => false, 'system_daemon' => true, 'daemon' => null];

    if (in_array('compose', $plugins, true)) {
        $report = mc_package($report, 'docker-compose-plugin', '2.35.1-1~ubuntu.24.04~noble', $origin, $repoConfigured ? $repo : null, $repoConfigured ? 'Docker' : null);
        $docker['compose'] = ['version' => '2.35.1', 'package' => 'docker-compose-plugin', 'path' => '/usr/libexec/docker/cli-plugins/docker-compose'];
    }

    if (in_array('buildx', $plugins, true)) {
        $report = mc_package($report, 'docker-buildx-plugin', '0.23.0-1~ubuntu.24.04~noble', $origin, $repoConfigured ? $repo : null, $repoConfigured ? 'Docker' : null);
        $docker['buildx'] = ['version' => '0.23.0', 'package' => 'docker-buildx-plugin', 'path' => '/usr/libexec/docker/cli-plugins/docker-buildx'];
    }

    $report['docker'] = $docker;

    return mc_service($report, 'docker.service');
}

/**
 * Ubuntu's docker.io with the given plugins from the archive.
 *
 * @param  array<string, mixed>  $report
 * @param  list<string>  $plugins
 * @return array<string, mixed>
 */
function mc_docker_io(array $report, array $plugins = []): array
{
    $report = mc_package($report, 'docker.io', '27.5.1-0ubuntu3~24.04.2');
    $docker = ['engine_package' => 'docker.io', 'client_version' => '27.5.1', 'server_version' => '27.5.1', 'compose' => null, 'buildx' => null, 'snap' => false, 'rootless' => false, 'system_daemon' => true, 'daemon' => null];

    if (in_array('compose', $plugins, true)) {
        $report = mc_package($report, 'docker-compose-v2', '2.33.1+ds1-0ubuntu1~24.04.1');
        $docker['compose'] = ['version' => '2.33.1', 'package' => 'docker-compose-v2', 'path' => '/usr/libexec/docker/cli-plugins/docker-compose'];
    }

    if (in_array('buildx', $plugins, true)) {
        $report = mc_package($report, 'docker-buildx', '0.21.3-0ubuntu1~24.04.1');
        $docker['buildx'] = ['version' => '0.21.3', 'package' => 'docker-buildx', 'path' => '/usr/libexec/docker/cli-plugins/docker-buildx'];
    }

    $report['docker'] = $docker;

    return mc_service($report, 'docker.service');
}

function mc_wanted(Stack $stack, bool $servesHttp = true, bool $custom = true, int $swapMb = 2048, array $base = []): Wanted
{
    return new Wanted(
        stack: $stack,
        servesHttp: $servesHttp,
        customServer: $custom,
        hostname: 'app-1',
        sshPort: 22,
        swapMb: $swapMb,
        phpVersions: $stack->phpVersions,
        nodeVersion: $stack->node !== null ? (string) config("servers.node_versions.{$stack->node}") : null,
        basePackages: $base,
    );
}

/**
 * @param  array<string, mixed>  $report
 */
function mc_decide(array $report, Wanted $wanted): MachineCheck
{
    return (new DecisionEngine((array) config('servers')))->decide(new MachineReport($report), $wanted);
}
