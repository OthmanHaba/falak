<?php

namespace Kiln\Telemetry\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Servers\Events\ServerProvisioned;
use Kiln\Telemetry\Contracts\TelemetryConfigurator;
use Kiln\Telemetry\Domain\Models\PendingConfiguration;

final class ConfigureTelemetryOnProvisioned implements ShouldQueue
{
    public function __construct(private readonly TelemetryConfigurator $configurator) {}

    public function handle(ServerProvisioned $event): void
    {
        PendingConfiguration::query()->whereKey($event->serverId)->delete();
        $this->configurator->reconfigure($event->serverId);
    }
}
