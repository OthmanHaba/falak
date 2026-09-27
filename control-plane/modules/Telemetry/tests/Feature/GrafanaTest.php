<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationCreated;
use Kiln\Telemetry\Application\Actions\ProvisionGrafana;
use Kiln\Telemetry\Contracts\Annotations;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Contracts\TelemetryLinks;
use Kiln\Telemetry\Domain\Models\DeploymentAnnotation;
use Kiln\Telemetry\Domain\Models\GrafanaState;
use Kiln\Telemetry\Infrastructure\Grafana\GrafanaClient;
use Kiln\Telemetry\Infrastructure\Grafana\GrafanaNames;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'telemetry.grafana.url' => 'http://grafana:3000',
        'telemetry.grafana.public_url' => 'https://grafana.example.com',
        'telemetry.grafana.token' => 'glsa_test',
        'telemetry.metrics.backend' => 'mimir',
    ]);
});

function grafana_dashboard_files(): array
{
    return glob(config('telemetry.grafana.dashboards_path').'/*.json');
}

it('provisions datasources, the organization folder and every dashboard with per-organization uids', function () {
    Http::fake([
        'grafana:3000/api/datasources/uid/kiln-metrics' => Http::response(['id' => 7, 'version' => 3, 'uid' => 'kiln-metrics']),
        'grafana:3000/api/datasources/uid/*' => Http::response(['message' => 'Data source not found'], 404),
        'grafana:3000/api/datasources' => Http::response(['id' => 8]),
        'grafana:3000/api/folders/*' => Http::response(['message' => 'folder not found'], 404),
        'grafana:3000/api/folders' => Http::response(['uid' => 'x']),
        'grafana:3000/api/dashboards/db' => Http::response(['status' => 'success']),
    ]);
    Event::fake([OrganizationCreated::class]);
    [, $organization] = actingAsMember(Role::Owner);

    $imported = app(ProvisionGrafana::class)($organization->id);

    $files = grafana_dashboard_files();
    expect($files)->not->toBeEmpty()->and($imported)->toHaveCount(count($files))
        ->and($imported['kiln-server'])->toBe(GrafanaNames::dashboardUid('kiln-server', $organization->id))
        ->and(strlen($imported['kiln-deployments']))->toBeLessThanOrEqual(40);

    $folderUid = GrafanaNames::folderUid($organization->id);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'http://grafana:3000/api/datasources/uid/kiln-metrics'
        && $r['id'] === 7 && $r['version'] === 3 && $r['jsonData']['prometheusType'] === 'Mimir'
        && $r->header('Authorization') === ['Bearer glsa_test']);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://grafana:3000/api/datasources' && $r['uid'] === 'kiln-loki'
        && $r['jsonData']['derivedFields'][0]['datasourceUid'] === 'kiln-tempo');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://grafana:3000/api/datasources' && $r['uid'] === 'kiln-tempo'
        && $r['jsonData']['tracesToLogsV2']['datasourceUid'] === 'kiln-loki');
    Http::assertSent(fn (Request $r) => $r->url() === 'http://grafana:3000/api/folders' && $r['uid'] === $folderUid && $r['title'] === $organization->name);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://grafana:3000/api/dashboards/db'
        && $r['folderUid'] === $folderUid && $r['overwrite'] === true && $r['dashboard']['id'] === null
        && $r['dashboard']['uid'] === $imported['kiln-server'] && $r['dashboard']['title'] === $organization->name.' · Kiln / Server');

    $state = GrafanaState::query()->findOrFail($organization->id);
    expect($state->folder_uid)->toBe($folderUid)->and($state->provisioned_at)->not->toBeNull()->and($state->last_error)->toBeNull();
});

it('renames an existing folder and records provisioning failures', function () {
    Http::fake([
        'grafana:3000/api/datasources/uid/*' => Http::response(['id' => 1, 'version' => 1]),
        'grafana:3000/api/folders/*' => Http::sequence()->push(['uid' => 'x', 'title' => 'Old name'])->push(['uid' => 'x']),
        'grafana:3000/api/dashboards/db' => Http::response(['message' => 'Dashboard title cannot be empty'], 400),
    ]);
    Event::fake([OrganizationCreated::class]);
    [, $organization] = actingAsMember(Role::Owner);

    expect(fn () => app(ProvisionGrafana::class)($organization->id))->toThrow(TelemetryQueryFailed::class);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/api/folders/kiln-org-') && $r['title'] === $organization->name);
    expect(GrafanaState::query()->findOrFail($organization->id)->last_error)->toContain('Dashboard title cannot be empty');
});

it('refuses to provision without Grafana configuration', function () {
    config(['telemetry.grafana.token' => null]);

    expect(fn () => app(ProvisionGrafana::class)('01jorg0000000000000000000a'))->toThrow(TelemetryUnavailable::class);
});

it('provisions new organizations through a queued listener when Grafana is configured', function () {
    Http::fake(['grafana:3000/*' => Http::response(['id' => 1, 'version' => 1, 'uid' => 'x', 'title' => 'x'])]);

    [, $organization] = actingAsMember(Role::Owner);

    expect(GrafanaState::query()->find($organization->id)?->provisioned_at)->not->toBeNull();
});

it('creates a deployment annotation and updates it on completion', function () {
    Http::fake([
        'grafana:3000/api/annotations' => Http::response(['id' => 42, 'message' => 'Annotation added']),
        'grafana:3000/api/annotations/42' => Http::response(['message' => 'Annotation patched']),
    ]);
    $started = now()->subMinutes(2)->startOfSecond();
    $finished = now()->startOfSecond();

    $annotations = app(Annotations::class);
    $first = $annotations->deployment('01jorg0000000000000000000a', '01jdep0000000000000000000a', '01jsite000000000000000000a', 'started', 'Deploying abc123', $started, tags: ['commit:abc123']);
    $second = $annotations->deployment('01jorg0000000000000000000a', '01jdep0000000000000000000a', '01jsite000000000000000000a', 'succeeded', 'Deployed abc123', $started, $finished);

    expect($first)->toBe(42)->and($second)->toBe(42)
        ->and(DeploymentAnnotation::query()->findOrFail('01jdep0000000000000000000a')->status)->toBe('succeeded');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://grafana:3000/api/annotations'
        && $r['time'] === $started->getTimestampMs() && ! isset($r['timeEnd'])
        && $r['text'] === 'Deploying abc123'
        && array_slice($r['tags'], 0, 2) === ['kiln', 'deployment']
        && in_array('site:01jsite000000000000000000a', $r['tags'], true) && in_array('status:started', $r['tags'], true) && in_array('commit:abc123', $r['tags'], true));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'http://grafana:3000/api/annotations/42'
        && $r['timeEnd'] === $finished->getTimestampMs() && in_array('status:succeeded', $r['tags'], true));
});

it('never throws from annotations and returns null when Grafana is unavailable', function () {
    Log::spy();
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(app(Annotations::class)->deployment('o', 'd', 's', 'failed'))->toBeNull()
        ->and(DeploymentAnnotation::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();

    config(['telemetry.grafana.url' => null]);
    expect(app(Annotations::class)->deployment('o', 'd', 's', 'failed'))->toBeNull();
});

it('reports Grafana health', function () {
    Http::fake(['grafana:3000/api/health' => Http::response(['database' => 'ok'])]);

    expect(app(GrafanaClient::class)->health())->toBeTrue();
});

it('builds in-app and Grafana deep links', function () {
    $links = app(TelemetryLinks::class);
    $from = CarbonImmutable::parse('2026-09-27T10:00:00Z');

    expect($links->trace('ABCDEF0123456789ABCDEF0123456789'))->toBe('/observability/traces/abcdef0123456789abcdef0123456789')
        ->and($links->logs(['site_id' => 'S1', 'search' => 'error', 'bogus' => 'x'], $from))->toBe('/observability/logs?site_id=S1&search=error&from=2026-09-27T10%3A00%3A00%2B00%3A00')
        ->and($links->traceSearch(['site_id' => 'S1', 'min_duration_ms' => 1000]))->toBe('/observability/traces?site_id=S1&min_duration_ms=1000')
        ->and($links->grafanaTrace('abc'))->toStartWith('https://grafana.example.com/explore?schemaVersion=1&panes=')
        ->and($links->grafanaDashboard('01jorg0000000000000000000a', 'kiln-laravel', ['site' => 'S1']))
        ->toBe('https://grafana.example.com/d/kiln-laravel-000000000a?var-site=S1');

    config(['telemetry.grafana.url' => null, 'telemetry.grafana.public_url' => null]);
    expect($links->grafanaTrace('abc'))->toBeNull()->and($links->grafanaDashboard('o', 'kiln-server'))->toBeNull();
});
