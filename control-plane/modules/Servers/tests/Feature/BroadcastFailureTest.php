<?php

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Broadcast;
use Kiln\Servers\Events\ServerUpdated;

it('reports but never throws when the realtime server is unreachable', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a',
        'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http'],
    ]]);
    app()->forgetInstance(BroadcastManager::class);
    app()->forgetInstance(Factory::class);
    Broadcast::clearResolvedInstances();

    $reported = [];
    $handler = Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing();
    $handler->shouldReceive('report')->andReturnUsing(function (Throwable $e) use (&$reported) {
        $reported[] = $e;
    });
    app()->instance(ExceptionHandler::class, $handler);

    ServerUpdated::dispatch('01K5S1M0RG0000000000000000', 'provisioning');

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(BroadcastException::class);
});
