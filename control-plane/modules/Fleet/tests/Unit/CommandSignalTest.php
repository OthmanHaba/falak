<?php

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Infrastructure\Signals\CommandSignal;
use Kiln\Fleet\Infrastructure\Signals\DatabaseCommandSignal;
use Kiln\Fleet\Infrastructure\Signals\RedisCommandSignal;

it('returns as soon as the check yields commands', function () {
    $calls = 0;
    $slept = 0;
    $signal = new DatabaseCommandSignal(250, function (int $us) use (&$slept) {
        $slept += $us;
    });

    $result = $signal->wait('agent', 30, function () use (&$calls) {
        return ++$calls >= 3 ? ['cmd'] : [];
    });

    expect($result)->toBe(['cmd'])->and($calls)->toBe(3)->and($slept)->toBe(500_000);
});

it('gives up after the wait window with an empty list', function () {
    $signal = new DatabaseCommandSignal(10);

    $started = microtime(true);
    expect($signal->wait('agent', 1, fn () => []))->toBe([])
        ->and(microtime(true) - $started)->toBeGreaterThanOrEqual(0.95)->toBeLessThan(2);
});

it('checks exactly once for wait=0', function () {
    $calls = 0;
    (new DatabaseCommandSignal)->wait('agent', 0, function () use (&$calls) {
        $calls++;

        return [];
    });

    expect($calls)->toBe(1);
});

it('wakes waiters through Redis lists (RPUSH on notify, BLPOP while waiting)', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->once()->with('rpush', ['kiln:fleet:wake:agent-1', '1']);
    $connection->shouldReceive('command')->once()->with('expire', ['kiln:fleet:wake:agent-1', 120]);
    $connection->shouldReceive('command')->once()->with('blpop', Mockery::on(fn ($args) => $args[0] === ['kiln:fleet:wake:agent-1'] && $args[1] >= 1 && $args[1] <= 30))->andReturn(['kiln:fleet:wake:agent-1', '1']);

    $redis = Mockery::mock(Factory::class);
    $redis->shouldReceive('connection')->with('default')->andReturn($connection);

    $signal = new RedisCommandSignal($redis);
    $signal->notify('agent-1');

    $checks = 0;
    $result = $signal->wait('agent-1', 30, function () use (&$checks) {
        return ++$checks === 2 ? ['cmd'] : [];
    });

    expect($result)->toBe(['cmd'])->and($checks)->toBe(2);
});

it('runs the before-block hook ahead of every BLPOP (releases the database connection while waiting)', function () {
    $events = [];
    $connection = Mockery::mock();
    $connection->shouldReceive('command')->with('blpop', Mockery::any())->twice()->andReturnUsing(function () use (&$events) {
        $events[] = 'blpop';

        return null;
    });

    $redis = Mockery::mock(Factory::class);
    $redis->shouldReceive('connection')->with('default')->andReturn($connection);

    $signal = new RedisCommandSignal($redis, 'default', function () use (&$events) {
        $events[] = 'release';
    });

    $checks = 0;
    $result = $signal->wait('agent-1', 30, function () use (&$checks, &$events) {
        $events[] = 'check';

        return ++$checks === 3 ? ['cmd'] : [];
    });

    expect($result)->toBe(['cmd'])
        ->and($events)->toBe(['check', 'release', 'blpop', 'check', 'release', 'blpop', 'check']);
});

it('binds the signal driver from configuration', function () {
    config(['fleet.wake_driver' => 'redis']);
    app()->forgetInstance(CommandSignal::class);
    expect(app(CommandSignal::class))->toBeInstanceOf(RedisCommandSignal::class);

    config(['fleet.wake_driver' => 'database']);
    app()->forgetInstance(CommandSignal::class);
    expect(app(CommandSignal::class))->toBeInstanceOf(DatabaseCommandSignal::class);
});

it('releases the idle database connection before blocking, but never inside a transaction', function () {
    config(['fleet.wake_driver' => 'redis']);
    app()->forgetInstance(CommandSignal::class);
    $hook = (new ReflectionProperty(RedisCommandSignal::class, 'beforeBlock'))->getValue(app(CommandSignal::class));
    $connected = fn () => DB::connection()->getRawPdo() !== null;

    DB::connection()->getPdo();
    DB::beginTransaction();
    $hook();
    expect($connected())->toBeTrue();
    DB::rollBack();

    $hook();
    expect($connected())->toBeFalse();

    $hook(); // already released: no-op
    expect($connected())->toBeFalse();
});
