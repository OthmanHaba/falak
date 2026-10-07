<?php

use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Servers\Application\MachineChecks;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\MachineCheck\MachineCheck;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Servers\Infrastructure\ProvisioningPlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Support/machine_reports.php';

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $report
 * @return array{0: array<string, mixed>, 1: MachineCheck}
 */
function decidedPlan(array $report, ?Stack $stack = null, string $provider = 'custom'): array
{
    $server = Server::factory()->type(ServerType::App)->create(['name' => 'App 1', 'provider' => $provider, 'memory_bytes' => 2 * 1024 ** 3, ...($stack ? ['stack' => $stack] : [])])->refresh();
    $check = app(MachineChecks::class)->decide($server, $report);

    return [app(ProvisioningPlanBuilder::class)->build($server, $check), $check];
}

it('installs no Docker package for an adopted Docker (the incident) and tells the agent', function () {
    [$plan] = decidedPlan(mc_docker_ce(mc_report()), new Stack('frankenphp', ['8.4'], '8.4', '22'));

    expect($plan['apt']['packages'])->not->toContain('docker.io', 'docker-compose-v2', 'docker-buildx', 'docker-ce')
        ->and(array_column($plan['services'], 'name'))->toContain('docker')
        // The agent leaves an adopted Docker alone (no engine step).
        ->and($plan['docker'])->toBe(['live_restore' => true])
        ->and(collect($plan['components'])->firstWhere('name', 'docker'))->toBe(['name' => 'docker', 'decision' => 'adopt', 'packages' => ['docker-ce', 'docker-compose-plugin', 'docker-buildx-plugin'], 'service' => 'docker'])
        ->and(app(ProtocolSchemas::class)->validateCommand('provision.apply', ProtocolSchemas::toJson($plan)))->toBe([]);
});

it('installs only the missing piece from the engine\'s family', function () {
    [$plan] = decidedPlan(mc_docker_ce(mc_report(), ['buildx']), new Stack);

    expect(array_values(array_filter($plan['apt']['packages'], fn ($p) => str_starts_with($p, 'docker'))))->toBe(['docker-compose-plugin'])
        ->and(collect($plan['components'])->firstWhere('name', 'docker'))->toMatchArray(['decision' => 'complete', 'packages' => ['docker-compose-plugin']]);
});

it('adopts swap and hostname without changing them, and leaves an engine on the machine alone', function () {
    $report = mc_package(mc_report(['swap' => [['name' => '/swap.img', 'type' => 'file', 'size_bytes' => 1 << 30]]]), 'postgresql-17', '17.5-1.pgdg24.04+1', 'vendor', 'http://apt.postgresql.org/pub/repos/apt');
    [$plan] = decidedPlan($report);

    expect($plan)->not->toHaveKeys(['swap_mb', 'hostname'])
        ->and($plan['apt']['packages'])->not->toContain('postgresql', 'postgresql-contrib', 'redis-server')
        ->and(array_column($plan['services'], 'name'))->toBe(['fail2ban', 'docker'])
        ->and(collect($plan['components'])->pluck('decision', 'name')->all())->toMatchArray(['swap' => 'adopt', 'hostname' => 'adopt', 'docker' => 'install'])
        ->and(collect($plan['components'])->pluck('name')->all())->not->toContain('database', 'cache')
        ->and(app(ProtocolSchemas::class)->validateCommand('provision.apply', ProtocolSchemas::toJson($plan)))->toBe([]);
});

it('names provider servers and plans no blocked component', function () {
    $report = mc_listen(mc_report(), 443, 'docker-proxy', 'docker.service', container: true);
    [$plan, $check] = decidedPlan($report, provider: 'hetzner');

    expect($check->blocking())->toBeTrue()
        ->and($plan['hostname'])->toBe('app-1')
        ->and(collect($plan['components'])->pluck('name')->all())->not->toContain('edge');
});

it('builds today\'s plan without a machine check', function () {
    $server = Server::factory()->type(ServerType::App)->create(['name' => 'App 1', 'provider' => 'custom']);
    $plan = app(ProvisioningPlanBuilder::class)->build($server);

    expect($plan)->not->toHaveKey('components')
        ->and($plan['hostname'])->toBe('app-1')
        ->and($plan['docker'])->toBe(['live_restore' => true, 'min_version' => '28'])
        ->and($plan['apt']['packages'])->not->toContain('postgresql', 'redis-server', 'docker.io');
});
