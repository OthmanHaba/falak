<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/users/{id}', fn ($id) => response()->json(['id' => $id]))->name('users.show');
    Route::get('/boom', fn () => throw new RuntimeException('kaboom'));
    Route::get('/reported', function () {
        report(new LogicException('handled one'));

        return 'ok';
    });
    Route::get('/up', fn () => 'up');
});

it('records a request span with contract attributes, user and timeline phases', function () {
    $this->actingAs(new GenericUser(['id' => 42]))->get('/users/7?page=2')->assertOk();

    $requests = $this->transport->spansOfType('request');
    expect($requests)->toHaveCount(1);

    $root = $requests[0];
    expect($root['kind'])->toBe(2)
        ->and($root['name'])->toBe('GET /users/{id}')
        ->and($root['attrs'])->toMatchArray([
            'falak.event.type' => 'request',
            'http.request.method' => 'GET',
            'http.route' => '/users/{id}',
            'http.response.status_code' => 200,
            'url.path' => '/users/7',
            'enduser.id' => '42',
            'falak.route.name' => 'users.show',
        ])
        ->and($root)->not->toHaveKey('parentSpanId');

    $phases = collect($this->transport->spans())
        ->filter(fn ($s) => isset($s['attrs']['falak.timeline.phase']))
        ->each(fn ($s) => expect($s['parentSpanId'])->toBe($root['spanId']) && expect($s['traceId'])->toBe($root['traceId']))
        ->pluck('name')->all();

    expect($phases)->toContain('middleware', 'controller', 'response');
});

it('marks unhandled exceptions as ERROR with an exception event handled=false', function () {
    $this->get('/boom')->assertStatus(500);

    $root = $this->transport->spansOfType('request')[0];
    expect($root['status']['code'])->toBe(2)
        ->and($root['attrs']['http.response.status_code'])->toBe(500);

    $event = collect($root['events'])->firstWhere('name', 'exception');
    expect($event['attrs'])->toMatchArray([
        'exception.type' => RuntimeException::class,
        'exception.message' => 'kaboom',
        'falak.exception.handled' => false,
    ])->and($event['attrs']['exception.stacktrace'])->toContain('RuntimeException: kaboom');
});

it('records reported exceptions as handled without failing the span', function () {
    $this->get('/reported')->assertOk();

    $root = $this->transport->spansOfType('request')[0];
    expect($root['status']['code'])->toBe(0);

    $event = collect($root['events'])->firstWhere('name', 'exception');
    expect($event['attrs']['exception.type'])->toBe(LogicException::class)
        ->and($event['attrs']['falak.exception.handled'])->toBeTrue();
});

it('continues an incoming W3C trace context', function () {
    $this->get('/users/1', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);

    $root = $this->transport->spansOfType('request')[0];
    expect($root['traceId'])->toBe('0af7651916cd43dd8448eb211c80319c')
        ->and($root['parentSpanId'])->toBe('b7ad6b7169203331');
});

it('skips ignored paths', function () {
    $this->get('/up')->assertOk();

    expect($this->transport->spansOfType('request'))->toBe([]);
});

it('does not add the controller marker twice on repeated requests', function () {
    $this->get('/users/1');
    $this->get('/users/2');

    $route = Route::getRoutes()->getByName('users.show');
    expect(array_count_values($route->middleware())[\Falak\Apm\Http\ControllerMarker::class])->toBe(1)
        ->and($this->transport->spansOfType('request'))->toHaveCount(2);
});
