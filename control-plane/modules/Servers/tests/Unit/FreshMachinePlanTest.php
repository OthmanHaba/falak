<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kiln\Servers\Application\MachineChecks;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Stack\Stack;
use Kiln\Servers\Infrastructure\ProvisioningPlanBuilder;

require_once __DIR__.'/../Support/machine_reports.php';

uses(RefreshDatabase::class);

/**
 * A fresh cloud image (mc_report: stock unattended-upgrades, inactive ufw, keys, no swap) gets the plan Kiln sent before
 * v0.6.0, apart from the documented differences: `components`, and a custom server keeps its own hostname.
 */
it('plans a fresh machine exactly as before the machine check', function (string $provider, ServerType $type, ?Stack $stack) {
    $server = Server::factory()->type($type)->create(['name' => 'App 1', 'provider' => $provider, 'memory_bytes' => 2 * 1024 ** 3, ...($stack ? ['stack' => $stack] : [])])->refresh();
    $plans = app(ProvisioningPlanBuilder::class);

    $before = $plans->build($server);
    $after = $plans->build($server, app(MachineChecks::class)->decide($server, mc_report()));

    expect(collect($after['components'])->reject(fn (array $c) => $c['name'] === 'hostname')->pluck('decision')->unique()->sort()->values()->all())->toBe(['complete', 'install'])
        ->and(array_diff_key($after, ['components' => true, 'hostname' => true]))->toBe(array_diff_key($before, ['hostname' => true]))
        ->and($after['swap_mb'])->toBe(4096)
        ->and($after['unattended_upgrades'])->toBe($before['unattended_upgrades']);

    if ($provider === 'custom') {
        expect($after)->not->toHaveKey('hostname');
    } else {
        expect($after['hostname'])->toBe($before['hostname']);
    }
})->with([
    'custom app server with Docker' => ['custom', ServerType::App, new Stack('frankenphp', ['8.4'], '8.4', '22', 'postgresql', 'redis', true)],
    'provider web server' => ['hetzner', ServerType::Web, null],
    'custom database server' => ['custom', ServerType::Database, null],
    'custom cache server (Valkey)' => ['custom', ServerType::Cache, new Stack(cache: 'valkey')],
]);
