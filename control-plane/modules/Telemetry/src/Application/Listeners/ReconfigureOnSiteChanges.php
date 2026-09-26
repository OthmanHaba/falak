<?php

namespace Kiln\Telemetry\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Telemetry\Contracts\TelemetryConfigurator;

/**
 * telemetry.configure carries each server's site list (slug → site id); resend it when that list changes.
 */
final class ReconfigureOnSiteChanges implements ShouldQueue
{
    public function __construct(private readonly TelemetryConfigurator $configurator) {}

    public function handle(SiteCreated|SiteTargetsChanged|SiteDeleted $event): void
    {
        $serverIds = match (true) {
            $event instanceof SiteTargetsChanged => [...$event->added, ...$event->removed],
            default => $event->serverIds,
        };

        foreach (array_unique($serverIds) as $serverId) {
            $this->configurator->reconfigure($serverId);
        }
    }
}
