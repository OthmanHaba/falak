<?php

namespace Falak\Telemetry\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Telemetry\Contracts\TelemetryConfigurator;

/**
 * A new agent version may understand log source fields its predecessor had stripped (kind, multiline): resend.
 */
final class ReconfigureAfterAgentUpgrade implements ShouldQueue
{
    public function __construct(private readonly TelemetryConfigurator $configurator) {}

    public function handle(AgentVersionChanged $event): void
    {
        if ($event->serverId !== null) {
            $this->configurator->reconfigure($event->serverId);
        }
    }
}
