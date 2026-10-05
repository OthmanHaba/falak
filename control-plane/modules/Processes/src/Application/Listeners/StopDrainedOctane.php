<?php

namespace Falak\Processes\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Edge\Events\EdgeApplied;
use Falak\Processes\Application\OctaneRoutes;
use Falak\Processes\Application\ServerConverger;

/**
 * Disable ordering: a draining Octane stops only once the server's edge runs a config that no longer
 * proxies the site to it (and no pending one does).
 */
final class StopDrainedOctane implements ShouldQueue
{
    public function __construct(
        private readonly OctaneRoutes $octane,
        private readonly ServerConverger $converger,
    ) {}

    public function handle(EdgeApplied $event): void
    {
        if ($this->octane->drained($event->serverId)) {
            $this->converger->schedule($event->serverId);
        }
    }
}
