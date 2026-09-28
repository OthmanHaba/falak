<?php

namespace Kiln\Telemetry\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Deployments\Events\ReleaseActivated;
use Kiln\Telemetry\Contracts\TelemetryConfigurator;

/**
 * telemetry.configure carries the release each site runs (its records are labelled with the deployment and release
 * ids): resend it on the servers a release was activated on.
 */
final class ReconfigureOnReleaseActivated implements ShouldQueue
{
    public function __construct(private readonly TelemetryConfigurator $configurator) {}

    public function handle(ReleaseActivated $event): void
    {
        foreach (array_unique($event->serverIds) as $serverId) {
            $this->configurator->reconfigure($serverId);
        }
    }
}
