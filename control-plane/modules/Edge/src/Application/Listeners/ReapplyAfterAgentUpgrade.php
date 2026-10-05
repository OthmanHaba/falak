<?php

namespace Falak\Edge\Application\Listeners;

use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Fleet\Events\AgentVersionChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A new agent version may understand edge fields its predecessor had stripped (e.g. per-site access logs), while
 * the compiled payload — and so its hash — is unchanged: force one apply.
 */
final class ReapplyAfterAgentUpgrade implements ShouldQueue
{
    public function __construct(private readonly EdgeRoutes $routes) {}

    public function handle(AgentVersionChanged $event): void
    {
        if ($event->serverId !== null) {
            $this->routes->apply($event->serverId, force: true);
        }
    }
}
