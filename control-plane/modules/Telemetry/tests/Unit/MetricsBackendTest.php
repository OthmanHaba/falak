<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryQueryFailed;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Contracts\MetricsBackend;
use Kiln\Telemetry\Infrastructure\Metrics\MimirBackend;
use Kiln\Telemetry\Infrastructure\Metrics\VictoriaMetricsBackend;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'telemetry.metrics.backend' => 'victoriametrics',
        'telemetry.metrics.query_url' => 'http://victoriametrics:8428',
        'telemetry.metrics.tenant' => null,
        'telemetry.metrics.token' => null,
    ]);
});

it('binds the adapter selected in config', function () {
    expect(app(MetricsBackend::class))->toBeInstanceOf(VictoriaMetricsBackend::class)
        ->and(app(MetricsBackend::class)->name())->toBe('victoriametrics');

    config(['telemetry.metrics.backend' => 'mimir']);

    expect(app(MetricsBackend::class))->toBeInstanceOf(MimirBackend::class)
        ->and(app(MetricsBackend::class)->name())->toBe('mimir');
});

it('runs VictoriaMetrics range queries and parses matrices (NaN → null)', function () {
    Http::fake(['victoriametrics:8428/api/v1/query_range' => Http::response([
        'status' => 'success',
        'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['system_filesystem_mountpoint' => '/'], 'values' => [[1_700_000_000, '0.5'], [1_700_000_030, 'NaN'], [1_700_000_060, '+Inf']]],
        ]],
    ])]);

    $series = app(MetricsBackend::class)->queryRange('up', CarbonImmutable::createFromTimestamp(1_700_000_000), CarbonImmutable::createFromTimestamp(1_700_000_060), 30);

    expect($series)->toHaveCount(1)
        ->and($series[0]->labels)->toBe(['system_filesystem_mountpoint' => '/'])
        ->and($series[0]->points)->toBe([[1_700_000_000, 0.5], [1_700_000_030, null], [1_700_000_060, null]]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['query'] === 'up'
        && (int) $request['start'] === 1_700_000_000
        && (int) $request['end'] === 1_700_000_060
        && (int) $request['step'] === 30
        && ! $request->hasHeader('X-Scope-OrgID'));
});

it('parses instant vectors and scalars', function () {
    Http::fake(['*/api/v1/query' => Http::sequence()
        ->push(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => ['host_name' => 'web-1'], 'value' => [1_700_000_000.123, '42']]]]])
        ->push(['status' => 'success', 'data' => ['resultType' => 'scalar', 'result' => [1_700_000_000, '7']]])]);

    $vector = app(MetricsBackend::class)->query('sum(x) by (host_name)');
    $scalar = app(MetricsBackend::class)->query('scalar(x)');

    expect($vector[0]->labels)->toBe(['host_name' => 'web-1'])
        ->and($vector[0]->last())->toBe(42.0)
        ->and($vector[0]->points[0][0])->toBe(1_700_000_000)
        ->and($scalar[0]->last())->toBe(7.0);
});

it('sends the Mimir tenant header and bearer token', function () {
    config(['telemetry.metrics.backend' => 'mimir', 'telemetry.metrics.query_url' => 'http://mimir:9009/prometheus', 'telemetry.metrics.tenant' => 'kiln', 'telemetry.metrics.token' => 'secret']);
    Http::fake(['mimir:9009/prometheus/api/v1/query' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    expect(app(MetricsBackend::class)->query('up'))->toBe([]);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://mimir:9009/prometheus/api/v1/query'
        && $request->header('X-Scope-OrgID') === ['kiln']
        && $request->header('Authorization') === ['Bearer secret']);
});

it('maps backend errors onto the contract exceptions', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['status' => 'error', 'errorType' => 'bad_data', 'error' => 'parse error at char 3'], 400)
        ->push('upstream down', 503)]);

    expect(fn () => app(MetricsBackend::class)->query('su('))->toThrow(TelemetryQueryFailed::class, 'parse error at char 3')
        ->and(fn () => app(MetricsBackend::class)->query('up'))->toThrow(TelemetryUnavailable::class);
});

it('reports an unconfigured or unreachable backend as unavailable', function () {
    config(['telemetry.metrics.query_url' => null]);
    expect(fn () => app(MetricsBackend::class)->query('up'))->toThrow(TelemetryUnavailable::class, 'not configured');

    config(['telemetry.metrics.query_url' => 'http://victoriametrics:8428']);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    expect(fn () => app(MetricsBackend::class)->query('up'))->toThrow(TelemetryUnavailable::class, 'Connection refused');
});
