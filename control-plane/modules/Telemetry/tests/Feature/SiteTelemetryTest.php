<?php

use Falak\Identity\Contracts\Role;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Telemetry\Contracts\AccessLogCounts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
        'deploy_script' => '$FALAK_FETCH',
        'laravel' => new LaravelSettings,
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
        ['metric' => ['falak_server_id' => strtoupper($this->servers[0]->id)], 'values' => [[1_700_000_000, '42']]],
    ]]])]);

    $response = $this->getJson("/telemetry/sites/{$this->site->id}/metrics/data?range=24h")->assertOk()
        ->assertJsonPath('range', '24h')
        ->assertJsonPath('step', 300)
        ->assertJsonCount(2, 'servers')
        ->assertJsonPath('charts.cpu.0.points.0', [1_700_000_000, 42]);
    expect(array_keys($response->json('charts')))->toBe(['requests', 'errors', 'p95', 'cpu', 'memory']);

    $site = 'falak_site_id="'.strtoupper($this->site->id).'"';
    $servers = 'falak_server_id=~"'.strtoupper($this->servers[0]->id).'|'.strtoupper($this->servers[1]->id).'"';
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

it('serves the site access log of one release for the deployment panel', function () {
    Http::fake(['loki:3100/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
        ['stream' => ['service_name' => 'shop', 'falak_log_kind' => 'access', 'falak_server_id' => strtoupper($this->servers[0]->id)], 'values' => [
            ['1790000000000000002', 'GET /cart 503 4.0ms', ['http_request_method' => 'GET', 'url_path' => '/cart', 'http_response_status_code' => '503', 'http_server_duration_ms' => '4.000', 'falak_release_id' => '01JRE00000000000000000000A']],
        ]],
    ]]])]);

    $this->getJson("/telemetry/sites/{$this->site->id}/access-logs/data?release=01jre00000000000000000000a&status=5xx&since=2026-09-28T10:00:00Z")->assertOk()
        ->assertJsonPath('configured', true)
        ->assertJsonPath('entries.0.method', 'GET')
        ->assertJsonPath('entries.0.status', 503)
        ->assertJsonPath('entries.0.release_id', '01jre00000000000000000000a')
        ->assertJsonPath('entries.0.server', $this->servers[0]->name)
        ->assertJsonPath('cursor', null);

    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'service_name="shop", falak_log_kind="access"')
        && str_contains($r['query'], 'falak_release_id="01JRE00000000000000000000A"') && str_contains($r['query'], 'http_response_status_code=~"5.."'));

    config(['telemetry.loki.url' => '']);
    $this->getJson("/telemetry/sites/{$this->site->id}/access-logs/data")->assertOk()->assertJsonPath('configured', false);
});

it('counts the requests and 5xx answers of one release with Loki metric queries', function () {
    Http::fake(['loki:3100/loki/api/v1/query*' => function (Request $request) {
        $errors = str_contains($request['query'], 'http_response_status_code=~"5.."');

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => [], 'value' => [1_790_000_000, $errors ? '7' : '140']],
        ]]]);
    }]);

    $counts = app(AccessLogCounts::class)->forRelease($this->organization->id, $this->site->id, '01jre00000000000000000000a', now()->subMinutes(5), now());

    expect($counts->total)->toBe(140)->and($counts->errors)->toBe(7)->and($counts->errorRate())->toBe(0.05);
    Http::assertSent(fn (Request $r) => str_starts_with($r['query'], 'sum(count_over_time({')
        && str_contains($r['query'], 'falak_release_id="01JRE00000000000000000000A"') && str_ends_with($r['query'], '[300s]))')
        // The control plane's own health checks are left out.
        && str_contains($r['query'], '| user_agent_original!~"Falak-HealthCheck/.*"'));

    // Another organization's site counts nothing (and asks Loki nothing).
    expect(app(AccessLogCounts::class)->forRelease(strtolower((string) Str::ulid()), $this->site->id, '01jre00000000000000000000a', now()->subMinutes(5), now())->total)->toBe(0);
    Http::assertSentCount(2);
});
