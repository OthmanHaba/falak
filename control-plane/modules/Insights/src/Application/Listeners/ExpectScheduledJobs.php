<?php

namespace Kiln\Insights\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Insights\Application\HeartbeatTracker;
use Kiln\Processes\Events\SchedulesApplied;

/**
 * Keeps heartbeat monitors in step with the schedule sets Processes applies to servers.
 */
final class ExpectScheduledJobs implements ShouldQueue
{
    public function __construct(private readonly HeartbeatTracker $heartbeats) {}

    public function handle(SchedulesApplied $event): void
    {
        $this->heartbeats->expect($event->organizationId, $event->serverId, $event->jobs);
    }
}
