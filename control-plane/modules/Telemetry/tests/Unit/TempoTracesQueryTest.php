<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kiln\Telemetry\Application\Queries\TraceQueryBuilder;
use Kiln\Telemetry\Contracts\Exceptions\TelemetryUnavailable;
use Kiln\Telemetry\Contracts\TracesQuery;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['telemetry.tempo.url' => 'http://tempo:3200']);
});

const TRACE_HEX = '0af7651916cd43dd8448eb211c80319c';

function tempo_resource_spans(bool $base64 = true): array
{
    $id = fn (string $hex) => $base64 ? base64_encode(hex2bin($hex)) : $hex;

    return [[
        'resource' => ['attributes' => [
            ['key' => 'service.name', 'value' => ['stringValue' => 'shop']],
            ['key' => 'kiln.org.id', 'value' => ['stringValue' => '01JORG0000000000000000000A']],
        ]],
        'scopeSpans' => [['spans' => [
            [
                'traceId' => $id(TRACE_HEX), 'spanId' => $id('b7ad6b7169203331'), 'parentSpanId' => $id('00f067aa0ba902b7'),
                'name' => 'select orders', 'kind' => 'SPAN_KIND_CLIENT',
                'startTimeUnixNano' => '1700000000050000000', 'endTimeUnixNano' => '1700000000070000000',
                'attributes' => [
                    ['key' => 'kiln.event.type', 'value' => ['stringValue' => 'query']],
                    ['key' => 'db.rows', 'value' => ['intValue' => '12']],
                    ['key' => 'ratio', 'value' => ['doubleValue' => 0.5]],
                    ['key' => 'cached', 'value' => ['boolValue' => false]],
                    ['key' => 'tags', 'value' => ['arrayValue' => ['values' => [['stringValue' => 'a'], ['intValue' => '2']]]]],
                ],
                'status' => ['code' => 'STATUS_CODE_ERROR', 'message' => 'deadlock'],
                'events' => [['name' => 'exception', 'timeUnixNano' => '1700000000060000000', 'attributes' => [
                    ['key' => 'exception.type', 'value' => ['stringValue' => 'PDOException']],
                ]]],
            ],
            [
                'traceId' => $id(TRACE_HEX), 'spanId' => $id('00f067aa0ba902b7'),
                'name' => 'GET /orders', 'kind' => 2,
                'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000100000000',
                'attributes' => [], 'status' => ['code' => 1],
            ],
        ]]],
    ]];
}

it('fetches a trace from the v2 API and decodes base64 ids, attributes, kinds and status', function () {
    Http::fake(['tempo:3200/api/v2/traces/*' => Http::response(['trace' => ['resourceSpans' => tempo_resource_spans()]])]);

    $trace = app(TracesQuery::class)->trace(strtoupper(TRACE_HEX));

    expect($trace->traceId)->toBe(TRACE_HEX)
        ->and($trace->organizationId())->toBe('01JORG0000000000000000000A')
        ->and($trace->spans)->toHaveCount(2)
        ->and($trace->root()->name)->toBe('GET /orders')
        ->and($trace->root()->kind)->toBe('server')
        ->and($trace->root()->status)->toBe('ok')
        ->and($trace->root()->durationMs())->toBe(100.0);

    $query = $trace->spans[1];
    expect($query->spanId)->toBe('b7ad6b7169203331')
        ->and($query->parentSpanId)->toBe('00f067aa0ba902b7')
        ->and($query->kind)->toBe('client')
        ->and($query->status)->toBe('error')
        ->and($query->statusMessage)->toBe('deadlock')
        ->and($query->service)->toBe('shop')
        ->and($query->attributes)->toBe(['kiln.event.type' => 'query', 'db.rows' => 12, 'ratio' => 0.5, 'cached' => false, 'tags' => '["a",2]'])
        ->and($query->events[0]['attributes'])->toBe(['exception.type' => 'PDOException'])
        ->and($query->toArray()['duration_ms'])->toBe(20.0);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://tempo:3200/api/v2/traces/'.TRACE_HEX);
});

it('falls back to the v1 API (batches, hex ids) and returns null for unknown traces', function () {
    Http::fake([
        'tempo:3200/api/v2/traces/*' => Http::response('404 page not found', 404),
        'tempo:3200/api/traces/'.TRACE_HEX => Http::response(['batches' => tempo_resource_spans(false)]),
        'tempo:3200/api/traces/*' => Http::response('trace not found', 404),
    ]);

    expect(app(TracesQuery::class)->trace(TRACE_HEX)->spans)->toHaveCount(2)
        ->and(app(TracesQuery::class)->trace('ffffffffffffffffffffffffffffffff'))->toBeNull();
});

it('returns null when Tempo v2 reports trace not found', function () {
    Http::fake(['tempo:3200/api/v2/traces/*' => Http::response('trace not found', 404)]);

    expect(app(TracesQuery::class)->trace(TRACE_HEX))->toBeNull();
    Http::assertSentCount(1);
});

it('searches with TraceQL', function () {
    Http::fake(['tempo:3200/api/search*' => Http::response(['traces' => [[
        'traceID' => '2f3e0cee77ae5dc9c17ade3689eb2e54'.'', 'rootServiceName' => 'shop', 'rootTraceName' => 'GET /orders',
        'startTimeUnixNano' => '1700000000000000000', 'durationMs' => 1234,
        'spanSets' => [['spans' => [['spanID' => 'a'], ['spanID' => 'b']], 'matched' => 3]],
    ], [
        'traceID' => 'e0cee77ae5dc9c17ade3689eb2e54', 'startTimeUnixNano' => '1', 'spanSet' => ['spans' => [['spanID' => 'c']]],
    ]]])]);

    $results = app(TracesQuery::class)->search('{ status = error }', CarbonImmutable::createFromTimestamp(100), CarbonImmutable::createFromTimestamp(200), 5);

    expect($results)->toHaveCount(2)
        ->and($results[0]->toArray())->toBe([
            'trace_id' => '2f3e0cee77ae5dc9c17ade3689eb2e54', 'root_service' => 'shop', 'root_name' => 'GET /orders',
            'start_unix_nano' => '1700000000000000000', 'duration_ms' => 1234.0, 'matched_spans' => 3,
        ])
        ->and($results[1]->traceId)->toBe('000e0cee77ae5dc9c17ade3689eb2e54')
        ->and($results[1]->matchedSpans)->toBe(1);

    Http::assertSent(fn (Request $request) => $request['q'] === '{ status = error }' && (int) $request['start'] === 100 && (int) $request['end'] === 200 && (int) $request['limit'] === 5);
});

it('requires Tempo to be configured', function () {
    config(['telemetry.tempo.url' => '']);

    expect(fn () => app(TracesQuery::class)->trace(TRACE_HEX))->toThrow(TelemetryUnavailable::class);
});

it('builds org-scoped TraceQL from filters', function () {
    expect(TraceQueryBuilder::build('01jorg0000000000000000000a', [
        'site_id' => '01jsite000000000000000000a',
        'service' => 'shop',
        'name' => 'GET "x"',
        'min_duration_ms' => '500',
        'status' => 'error',
    ]))->toBe('{ resource.kiln.org.id = "01JORG0000000000000000000A" && resource.kiln.site.id = "01JSITE000000000000000000A" && resource.service.name = "shop" && name = "GET \"x\"" && duration >= 500ms && status = error }')
        ->and(TraceQueryBuilder::build('o', []))->toBe('{ resource.kiln.org.id = "O" }');
});
