<?php

use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Servers\Infrastructure\ProvisioningPlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planFor(ServerType $type, ?Stack $stack = null, array $attributes = []): array
{
    $server = Server::factory()->type($type)->create(['name' => 'Web 01', ...$attributes, ...($stack ? ['stack' => $stack] : [])]);

    foreach ($server->stack->phpVersions as $version) {
        $server->phpVersions()->create([
            'version' => $version,
            'status' => PhpVersionStatus::Installing,
            'is_default' => $version === $server->stack->phpDefault,
            'ini' => PhpVersion::DEFAULT_INI,
            'fpm' => PhpVersion::defaultFpm(null),
        ]);
    }

    return app(ProvisioningPlanBuilder::class)->build($server->refresh());
}

function provisionSchemaErrors(array $plan): array
{
    return app(ProtocolSchemas::class)->validateCommand('provision.apply', ProtocolSchemas::toJson($plan));
}

it('produces a provision.apply payload valid against the contract for every server type', function (ServerType $type) {
    expect(provisionSchemaErrors(planFor($type)))->toBe([]);
})->with(ServerType::cases());

it('builds an all-in-one app server plan', function () {
    $plan = planFor(ServerType::App, attributes: ['memory_bytes' => 4 * 1024 ** 3, 'timezone' => 'Europe/Amsterdam', 'ssh_port' => 2222]);

    expect($plan['hostname'])->toBe('web-01')
        ->and($plan['timezone'])->toBe('Europe/Amsterdam')
        ->and($plan['swap_mb'])->toBe(4096)
        ->and($plan['apt']['packages'])->toContain('docker.io', 'docker-compose-v2', 'git', 'fail2ban')
        ->and($plan['apt']['packages'])->not->toContain('postgresql', 'redis-server')
        ->and($plan['runtimes']['php'])->toMatchArray(['versions' => ['8.4'], 'default' => '8.4', 'fpm' => false])
        ->and($plan['runtimes']['php']['extensions'])->toContain('mbstring', 'pgsql', 'redis')
        ->and($plan['runtimes']['frankenphp']['version'])->toBe(config('servers.frankenphp.version'))
        ->and($plan['runtimes']['node'])->toBe(['versions' => [config('servers.node_versions.22')], 'default' => config('servers.node_versions.22')])
        ->and($plan['runtimes']['caddy'])->toBe(['enabled' => false])
        ->and(array_column($plan['services'], 'name'))->toBe(['fail2ban', 'docker'])
        ->and($plan['docker'])->toBe(['live_restore' => true])
        ->and($plan['users'][0])->toMatchArray(['name' => 'falak', 'groups' => ['www-data'], 'sudo' => 'none'])
        ->and($plan['ssh'])->toBe(['port' => 2222, 'permit_root_login' => 'prohibit-password', 'password_authentication' => false])
        ->and($plan['unattended_upgrades']['enabled'])->toBeTrue();
});

it('uses php-fpm + Caddy for the FPM runtime with multiple PHP versions', function () {
    $plan = planFor(ServerType::Web, new Stack('fpm', ['8.4', '8.2', '8.3'], '8.3', null));

    expect($plan['runtimes']['php'])->toMatchArray(['versions' => ['8.2', '8.3', '8.4'], 'default' => '8.3', 'fpm' => true])
        ->and($plan['runtimes'])->not->toHaveKey('frankenphp')
        ->and($plan['runtimes'])->not->toHaveKey('node')
        ->and($plan['runtimes']['caddy'])->toBe(['enabled' => true])
        ->and(provisionSchemaErrors($plan))->toBe([]);
});

it('gives every server Docker with live-restore and no database or cache engine', function (ServerType $type) {
    $plan = planFor($type);

    expect($plan['apt']['packages'])->toContain('docker.io', 'docker-compose-v2', 'docker-buildx')
        ->and($plan['apt']['packages'])->not->toContain('postgresql', 'postgresql-contrib', 'mysql-server', 'mariadb-server', 'redis-server', 'valkey-server')
        ->and(array_column($plan['services'], 'name'))->toBe(['fail2ban', 'docker'])
        ->and($plan['docker'])->toBe(['live_restore' => true])
        ->and($plan['users'][0]['groups'])->toBe(['www-data'])
        // No site user ever gets the Docker socket (root on the server).
        ->and(collect($plan['users'])->pluck('groups')->flatten()->all())->not->toContain('docker');
})->with(ServerType::cases());

it('provisions dedicated servers with only their runtimes', function () {
    $db = planFor(ServerType::Database);
    $lb = planFor(ServerType::LoadBalancer);
    $builder = planFor(ServerType::Builder);

    expect($db)->not->toHaveKey('runtimes')
        ->and($lb['runtimes'])->toBe(['caddy' => ['enabled' => true]])
        ->and($builder['runtimes'])->toHaveKey('node')->not->toHaveKey('php');
});

it('excludes PHP versions being removed and includes ones being installed', function () {
    $server = Server::factory()->type(ServerType::Web)->create(['stack' => new Stack('frankenphp', ['8.3', '8.4'], '8.4')]);
    $server->phpVersions()->create(['version' => '8.4', 'status' => PhpVersionStatus::Installed, 'is_default' => true, 'ini' => [], 'fpm' => PhpVersion::defaultFpm(null)]);
    $server->phpVersions()->create(['version' => '8.3', 'status' => PhpVersionStatus::Removing, 'is_default' => false, 'ini' => [], 'fpm' => PhpVersion::defaultFpm(null)]);
    $server->phpVersions()->create(['version' => '8.5', 'status' => PhpVersionStatus::Installing, 'is_default' => false, 'ini' => [], 'fpm' => PhpVersion::defaultFpm(null)]);

    $plan = app(ProvisioningPlanBuilder::class)->build($server);

    expect($plan['runtimes']['php']['versions'])->toBe(['8.4', '8.5'])
        ->and($plan['runtimes']['php']['default'])->toBe('8.4');
});

it('derives RFC 1123 hostnames', function (string $name, string $hostname) {
    expect(app(ProvisioningPlanBuilder::class)->hostname($name))->toBe($hostname);
})->with([
    ['Web 01', 'web-01'],
    ['api.prod', 'api-prod'],
    ['--Ünïcode__box--', 'unicode-box'],
    ['___', 'falak-server'],
    [str_repeat('a', 80), str_repeat('a', 63)],
]);

it('sizes swap by memory', function (?int $memory, int $swap) {
    expect(app(ProvisioningPlanBuilder::class)->swapMb($memory))->toBe($swap);
})->with([
    [null, 2048],
    [1024 ** 3, 2048],
    [4 * 1024 ** 3, 4096],
    [32 * 1024 ** 3, 0],
]);

it('points FrankenPHP and Node downloads at configured mirrors', function () {
    config([
        'servers.mirrors.frankenphp' => 'https://mirror.example.test/github/php/frankenphp/releases/download/',
        'servers.mirrors.node' => 'https://mirror.example.test/nodejs',
    ]);

    $plan = planFor(ServerType::App);

    expect($plan['runtimes']['frankenphp']['mirror'])->toBe('https://mirror.example.test/github/php/frankenphp/releases/download')
        ->and($plan['runtimes']['node']['mirror'])->toBe('https://mirror.example.test/nodejs')
        ->and(provisionSchemaErrors($plan))->toBe([]);
});

it('uses the upstream download URLs when no mirror is configured', function () {
    config(['servers.mirrors' => ['frankenphp' => null, 'node' => '']]);

    $plan = planFor(ServerType::App);

    expect($plan['runtimes']['frankenphp'])->not->toHaveKey('mirror')
        ->and($plan['runtimes']['node'])->not->toHaveKey('mirror');
});
