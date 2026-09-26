<?php

use Kiln\Apm\Otlp\Encoder;
use Kiln\Apm\Recorder;
use Kiln\Apm\Redactor;
use Kiln\Apm\Span;
use Kiln\Apm\Tests\FakeTransport;

/** Structural validation against the OTLP/JSON mapping of opentelemetry-proto. */
function assertOtlpAttributes(array $attributes): void
{
    expect($attributes)->toBeList();

    foreach ($attributes as $attribute) {
        expect($attribute)->toHaveKeys(['key', 'value'])
            ->and($attribute['key'])->toBeString()
            ->and(array_keys($attribute['value']))->toHaveCount(1)
            ->and(array_key_first($attribute['value']))->toBeIn(['stringValue', 'intValue', 'doubleValue', 'boolValue', 'arrayValue', 'kvlistValue', 'bytesValue']);

        if (isset($attribute['value']['intValue'])) {
            expect($attribute['value']['intValue'])->toBeString()->toMatch('/^-?\d+$/');
        }
    }
}

it('produces valid OTLP/JSON trace payloads', function () {
    $transport = new FakeTransport;
    $recorder = new Recorder([], $transport, new Encoder(['service.name' => 'shop', 'kiln.site.id' => '01J']), new Redactor);

    $recorder->beginTrace('request', 'GET /x', Span::KIND_SERVER, ['http.request.method' => 'GET', 'ratio' => 0.5, 'flag' => true, 'list' => ['a', 'b'], 'map' => ['k' => 1]]);
    $recorder->record('query', 'sqlite', Span::KIND_CLIENT, $recorder->now(), $recorder->now() + 1000, ['db.query.text' => 'select 1']);
    $recorder->recordException(new RuntimeException('x'), false);
    $recorder->endTrace();
    $recorder->flush();

    $payload = $transport->payloads('/v1/traces')[0];

    expect($payload)->toHaveKey('resourceSpans')
        ->and($payload['resourceSpans'])->toHaveCount(1);

    $rs = $payload['resourceSpans'][0];
    assertOtlpAttributes($rs['resource']['attributes']);
    expect(FakeTransport::attrs($rs['resource']['attributes']))->toMatchArray(['service.name' => 'shop', 'kiln.site.id' => '01J'])
        ->and($rs['scopeSpans'][0]['scope'])->toBe(['name' => 'kiln/apm-laravel', 'version' => Encoder::VERSION]);

    $spans = $rs['scopeSpans'][0]['spans'];
    expect($spans)->toHaveCount(2);

    foreach ($spans as $span) {
        expect($span['traceId'])->toMatch('/^[0-9a-f]{32}$/')
            ->and($span['spanId'])->toMatch('/^[0-9a-f]{16}$/')
            ->and($span['name'])->toBeString()
            ->and($span['kind'])->toBeIn([1, 2, 3, 4, 5])
            ->and($span['startTimeUnixNano'])->toMatch('/^\d{19}$/')
            ->and($span['endTimeUnixNano'])->toMatch('/^\d{19}$/')
            ->and((int) $span['endTimeUnixNano'])->toBeGreaterThanOrEqual((int) $span['startTimeUnixNano'])
            ->and($span['status']['code'])->toBeIn([0, 1, 2]);

        assertOtlpAttributes($span['attributes']);

        foreach ($span['events'] ?? [] as $event) {
            expect($event['timeUnixNano'])->toMatch('/^\d{19}$/')->and($event['name'])->toBe('exception');
            assertOtlpAttributes($event['attributes']);
        }
    }

    [$query, $root] = $spans;
    expect($query['parentSpanId'])->toBe($root['spanId'])
        ->and($root)->not->toHaveKey('parentSpanId')
        ->and($root['status'])->toBe(['code' => 2, 'message' => 'x']);
});

it('produces valid OTLP/JSON log payloads', function () {
    $transport = new FakeTransport;
    $recorder = new Recorder([], $transport, new Encoder(['service.name' => 'shop']), new Redactor);

    $recorder->recordLog('error', 'outside trace', ['exception' => new LogicException('nope')]);
    $recorder->flush();

    $record = $transport->payloads('/v1/logs')[0]['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

    expect($record['timeUnixNano'])->toMatch('/^\d{19}$/')
        ->and($record['severityNumber'])->toBe(17)
        ->and($record['body'])->toBe(['stringValue' => 'outside trace'])
        ->and($record)->not->toHaveKey('traceId')
        ->and(FakeTransport::attrs($record['attributes'])['exception.type'])->toBe(LogicException::class);

    assertOtlpAttributes($record['attributes']);
});

it('parses traceparent headers strictly', function () {
    expect(Recorder::parseTraceparent('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'))
        ->toBe(['traceId' => '0af7651916cd43dd8448eb211c80319c', 'spanId' => 'b7ad6b7169203331', 'sampled' => true])
        ->and(Recorder::parseTraceparent('garbage'))->toBeNull()
        ->and(Recorder::parseTraceparent('00-00000000000000000000000000000000-b7ad6b7169203331-01'))->toBeNull();
});
