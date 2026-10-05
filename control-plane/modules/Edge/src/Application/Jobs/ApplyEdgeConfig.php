<?php

namespace Falak\Edge\Application\Jobs;

use Falak\Edge\Application\CloudflareTunnels;
use Falak\Edge\Contracts\EdgeRoutes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Debounced edge apply for one server. Unique per server until it starts processing, so a burst of
 * changes queues one job; changes arriving while it runs queue the next one. The job compiles the
 * state at run time, so it always ships the latest config.
 */
final class ApplyEdgeConfig implements ShouldBeUnique, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public int $uniqueFor = 300;

    public function __construct(public readonly string $serverId) {}

    public function uniqueId(): string
    {
        return $this->serverId;
    }

    public function handle(EdgeRoutes $routes, CloudflareTunnels $tunnels): void
    {
        $routes->apply($this->serverId);
        $tunnels->syncIngress($this->serverId); // the tunnel routes the names the server now serves
    }
}
