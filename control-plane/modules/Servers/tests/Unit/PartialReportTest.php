<?php

use Kiln\Servers\Domain\MachineCheck\Decision;
use Kiln\Servers\Domain\MachineCheck\MachineCheck;
use Kiln\Servers\Domain\Stack\Stack;

require_once __DIR__.'/../Support/machine_reports.php';

/*
 * Report sections can be partial or missing (a detector failed, an older or newer agent): decide() must not touch a
 * key it did not check. Undefined array keys are errors in the test environment, so each case would throw.
 */
dataset('partial_reports', [
    'empty report' => [[]],
    'every section empty' => [['packages' => [], 'snaps' => [], 'apt_sources' => [], 'services' => [], 'listeners' => [], 'containers' => [], 'docker' => [], 'ssh' => [], 'firewall' => [], 'swap' => [], 'node' => [], 'php' => [], 'frankenphp' => [], 'unattended_upgrades' => [], 'fail2ban' => []]],
    'list items without their keys' => [[
        'packages' => [[], ['name' => 'nginx'], ['name' => 'postgresql-16'], ['name' => 'mariadb-server'], ['name' => 'redis-server'], ['version' => '1']],
        'apt_sources' => [[], ['file' => '/etc/apt/sources.list']],
        'services' => [[], ['unit' => 'caddy.service'], ['unit' => 'nginx.service', 'enabled' => 'enabled'], ['unit' => 'docker.service']],
        'listeners' => [[], ['port' => 80], ['port' => 443, 'container' => true], ['port' => 5432, 'process' => 'postgres'], ['port' => 6379, 'unit' => 'x.service'], ['port' => 2019]],
        'containers' => [[], ['ports' => [[], ['host_port' => 3306]]], ['name' => 'c']],
        'swap' => [[], ['size_bytes' => 1]],
        'node' => [[], ['path' => '/usr/local/bin/node'], ['source' => 'nvm'], ['version' => '20.1.0', 'source' => 'manual']],
        'php' => [[], ['path' => '/usr/local/bin/php'], ['version' => '8.4', 'package' => 'php8.4-cli']],
        'frankenphp' => [[], ['source' => 'manual']],
        'snaps' => [[], ['name' => 'docker']],
    ]],
    'partial docker' => [['docker' => ['compose' => [], 'buildx' => ['package' => 'docker-buildx'], 'daemon' => ['iptables' => false, 'userns_remap' => 'x', 'error' => 'bad', 'default_address_pools' => [[]]]]]],
    'docker with only a server version' => [['docker' => ['server_version' => '27.0.1', 'system_daemon' => true]]],
    'partial ssh' => [['ssh' => ['users' => [[], ['authorized_keys' => 2], ['name' => 'root', 'uid' => 0, 'authorized_keys' => 1]], 'drop_ins' => [[], ['file' => '/etc/ssh/sshd_config.d/10-x.conf'], ['settings' => ['port' => '22']], 'junk'], 'effective' => ['allowgroups' => 'ssh']]]],
    'partial firewall and services' => [['firewall' => ['ufw' => 'active'], 'unattended_upgrades' => ['periodic' => []], 'fail2ban' => ['installed' => true]]],
    'wrong types' => [['packages' => 'x', 'docker' => 'x', 'ssh' => ['users' => 'x', 'drop_ins' => 'x', 'effective' => 'x'], 'swap' => 'x', 'node' => [1, 'x'], 'firewall' => ['nft_tables' => 'x']]],
]);

it('decides on a partial report without failing', function (array $report) {
    $stacks = [
        new Stack('frankenphp', ['8.4'], '8.4', '22', 'postgresql', 'redis', true),
        new Stack('fpm', ['8.4'], '8.4', '22', 'mysql', 'valkey', false),
        new Stack(database: 'mariadb'),
    ];

    foreach ($stacks as $stack) {
        foreach ([true, false] as $custom) {
            $check = mc_decide($report, mc_wanted($stack, custom: $custom));

            expect($check)->toBeInstanceOf(MachineCheck::class)
                ->and($check->components)->not->toBeEmpty()
                ->and(MachineCheck::fromArray(json_decode(json_encode($check->toArray()), true))->toArray())->toBe($check->toArray());

            foreach ($check->components as $component) {
                expect($component->decision)->toBeInstanceOf(Decision::class);
            }
        }
    }
})->with('partial_reports');

it('names a Node install with no version', function () {
    $check = mc_decide(mc_report(['node' => [['path' => '/usr/bin/node', 'source' => 'unknown']]]), mc_wanted(new Stack(node: '22')));

    expect($check->for('node')->notes[0]->message)->toBe("Node at /usr/bin/node (unknown source) stays as it is; sites run Kiln's Node.");
});
