<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Falak\Apm\Recorder;
use Falak\Apm\Span;
use Falak\Apm\Transport\SocketTransport;

beforeEach(function () {
    Route::get('/work', function () {
        DB::select('select 1');
        Cache::get('password_reset:42');
        Cache::get('users:42');

        return 'ok';
    });
});

it('redacts cache keys, sensitive attributes and applies custom callbacks', function () {
    $this->recorder()->redactUsing(function (array $attributes, string $type) {
        if ($type === 'request') {
            $attributes['client.address'] = '0.0.0.0';
        }

        return $attributes;
    });

    $this->get('/work', ['X-Api-Token' => 'secret-value']);

    $keys = collect($this->transport->spansOfType('cache'))->map(fn ($s) => $s['attrs']['falak.cache.key'])->all();
    expect($keys)->toBe(['[redacted]', 'users:42'])
        ->and($this->transport->spansOfType('request')[0]['attrs']['client.address'])->toBe('0.0.0.0');
});

it('samples whole traces by root type rate', function () {
    config()->set('falak-apm.sample_rates.request', 0.0);
    $this->app->forgetInstance(Recorder::class);
    $recorder = $this->app->make(Recorder::class);
    $recorder->beginTrace('request', 'GET /', Span::KIND_SERVER);
    $recorder->record('query', 'sqlite', Span::KIND_CLIENT, 1, 2, ['db.query.text' => 'select 1']);
    $recorder->endTrace();
    $recorder->flush();

    expect($this->transport->sent)->toBe([]);
});

it('samples child spans by event type rate', function () {
    $recorder = new Recorder(
        ['sample_rates' => ['query' => 0.0]] ,
        $this->transport,
        new \Falak\Apm\Otlp\Encoder([]),
        new \Falak\Apm\Redactor,
    );

    $recorder->beginTrace('request', 'GET /', Span::KIND_SERVER);
    $recorder->record('query', 'sqlite', Span::KIND_CLIENT, 1, 2, ['db.query.text' => 'select 1']);
    $recorder->record('cache', 'cache hit', Span::KIND_INTERNAL, 1, 2, ['falak.cache.op' => 'hit']);
    $recorder->endTrace();
    $recorder->flush();

    expect($this->transport->spansOfType('query'))->toBe([])
        ->and($this->transport->spansOfType('cache'))->toHaveCount(1)
        ->and($this->transport->spansOfType('request'))->toHaveCount(1);
});

it('respects per-type toggles and max spans per trace', function () {
    $recorder = new Recorder(
        ['events' => ['cache' => false], 'max_spans_per_trace' => 3],
        $this->transport,
        new \Falak\Apm\Otlp\Encoder([]),
        new \Falak\Apm\Redactor,
    );

    $recorder->beginTrace('request', 'GET /', Span::KIND_SERVER);
    $recorder->record('cache', 'cache hit', Span::KIND_INTERNAL, 1, 2, []);

    for ($i = 0; $i < 10; $i++) {
        $recorder->record('query', 'sqlite', Span::KIND_CLIENT, 1, 2, ['db.query.text' => 'select 1']);
    }

    $recorder->endTrace();
    $recorder->flush();

    $root = $this->transport->spansOfType('request')[0];
    expect($this->transport->spansOfType('cache'))->toBe([])
        ->and($this->transport->spansOfType('query'))->toHaveCount(3)
        ->and($root['attrs']['falak.trace.dropped_spans'])->toBe(7);
});

it('never throws when the agent is unreachable', function () {
    $this->app->instance(\Falak\Apm\Transport\Transport::class, $transport = new SocketTransport(['unix:/nonexistent/falak.sock', 'http://127.0.0.1:1'], 0.25));
    $recorder = new Recorder([], $transport, new \Falak\Apm\Otlp\Encoder([]), new \Falak\Apm\Redactor);

    $recorder->beginTrace('request', 'GET /', Span::KIND_SERVER);
    $recorder->recordLog('info', 'hello');
    $recorder->endTrace();

    $start = microtime(true);
    $recorder->flush();
    $elapsed = microtime(true) - $start;

    expect($transport->send('/v1/traces', '{}'))->toBeFalse()
        ->and($elapsed)->toBeLessThan(1.1)
        ->and($recorder->pending()['spans'])->toBe([]);
});

it('keeps serving requests when the transport is unreachable', function () {
    // The default test env points at a missing socket with no fallback; swap in the real transport.
    $recorder = $this->recorder();
    $prop = new ReflectionProperty($recorder, 'transport');
    $prop->setValue($recorder, new SocketTransport(['unix:/nonexistent/falak.sock'], 0.25));

    $this->get('/work')->assertOk()->assertSee('ok');
});

it('resets all state on Octane request boundaries', function () {
    $recorder = $this->recorder();
    $recorder->beginTrace('request', 'GET /leak', Span::KIND_SERVER);
    $recorder->record('query', 'sqlite', Span::KIND_CLIENT, 1, 2, ['db.query.text' => 'select 1']);
    $recorder->recordLog('info', 'leaky');

    event('Laravel\Octane\Events\RequestReceived');

    expect($recorder->active())->toBeFalse()
        ->and($recorder->pending())->toBe(['spans' => [], 'logs' => 0]);

    $this->get('/work')->assertOk();
    expect(collect($this->transport->spans())->pluck('name'))->not->toContain('GET /leak')
        ->and(collect($this->transport->logRecords())->pluck('body.stringValue'))->not->toContain('leaky');
});

it('flushes on Octane request termination', function () {
    $recorder = $this->recorder();
    $recorder->beginTrace('command', 'x', Span::KIND_INTERNAL);
    $recorder->endTrace();

    event('Laravel\Octane\Events\RequestTerminated');

    expect($this->transport->spansOfType('command'))->toHaveCount(1);
});

it('does nothing when disabled', function () {
    $recorder = new Recorder(['enabled' => false], $this->transport, new \Falak\Apm\Otlp\Encoder([]), new \Falak\Apm\Redactor);
    $recorder->beginTrace('request', 'GET /', Span::KIND_SERVER);
    $recorder->record('query', 'q', Span::KIND_CLIENT, 1, 2, []);
    $recorder->recordLog('error', 'x');
    $recorder->endTrace();
    $recorder->flush();

    expect($this->transport->sent)->toBe([]);
});
