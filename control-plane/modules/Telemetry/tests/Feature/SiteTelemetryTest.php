<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\LaravelSettings;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'telemetry.metrics.backend' => 'victoriametrics',
        'telemetry.metrics.query_url' => 'http://vm:8428',
        'telemetry.loki.url' => 'http://loki:3100',
        'telemetry.tempo.url' => null,
    ]);
    [$this->user, $this->organization] = actingAsMember(Role::Viewer);
    $this->servers = Server::factory()->count(2)->create(['organization_id' => $this->organization->id]);
    $this->site = Site::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'runtime' => SiteRuntime::FrankenPhp,
        'build_mode' => BuildMode::Native,
        'framework' => Framework::Laravel,
        'php_version' => '8.4',
        'web_directory' => 'public',
        'unix_user' => 'shop',
        'isolated' => true,
        'deploy_script' => '$KILN_FETCH',
        'laravel' => new LaravelSettings,
        'shared_paths' => [],
    ]);

    foreach ($this->servers as $index => $server) {
        SiteTarget::query()->create(['site_id' => $this->site->id, 'server_id' => $server->id, 'role' => $index === 0 ? TargetRole::Leader : TargetRole::Member, 'status' => TargetStatus::Ready]);
    }
});

it('describes the site for the panel tabs', function () {
    $this->getJson("/telemetry/sites/{$this->site->id}")->assertOk()
        ->assertJsonPath('site.name', 'Shop')
        ->assertJsonCount(2, 'servers')
        ->assertJsonPath('configured.logs', true)
        ->assertJsonPath('configured.traces', false)
        ->assertJsonPath('configured.metrics', true)
        ->assertJsonPath('links.logs', "/observability/logs?site_id={$this->site->id}");
});

it('serves site metrics scoped to the site and its servers', function () {
    Http::fake(['vm:8428/api/v1/query_range' => Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
        ['metric' => ['kiln_server_id' => strtoupper($this->servers[0]->id)], 'values' => [[1_700_000_000, '42']]],
    ]]])]);

    $response = $this->getJson("/telemetry/sites/{$this->site->id}/metrics/data?range=24h")->assertOk()
        ->assertJsonPath('range', '24h')
        ->assertJsonPath('step', 300)
        ->assertJsonCount(2, 'servers')
        ->assertJsonPath('charts.cpu.0.points.0', [1_700_000_000, 42]);
    expect(array_keys($response->json('charts')))->toBe(['requests', 'errors', 'p95', 'cpu', 'memory']);

    $site = 'kiln_site_id="'.strtoupper($this->site->id).'"';
    $servers = 'kiln_server_id=~"'.strtoupper($this->servers[0]->id).'|'.strtoupper($this->servers[1]->id).'"';
    Http::assertNotSent(fn (Request $r) => ! str_contains($r['query'], $site) && ! str_contains($r['query'], $servers));

    $this->getJson("/telemetry/sites/{$this->site->id}/metrics/data?range=1y")->assertUnprocessable();
});

it('reports an unconfigured metrics backend as 503', function () {
    config(['telemetry.metrics.query_url' => null]);

    $this->getJson("/telemetry/sites/{$this->site->id}/metrics/data")->assertStatus(503);
});

it('hides other organizations\' sites', function () {
    actingAsMember(Role::Owner);

    $this->getJson("/telemetry/sites/{$this->site->id}")->assertNotFound();
    $this->getJson("/telemetry/sites/{$this->site->id}/metrics/data")->assertNotFound();
    $this->getJson('/telemetry/sites/not-a-site')->assertNotFound();
});
