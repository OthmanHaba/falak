<?php

namespace Falak\Telemetry\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Telemetry\Contracts\TelemetryConfigurator;
use Falak\Telemetry\Domain\Models\PendingConfiguration;

final class ConfigureTelemetryOnProvisioned implements ShouldQueue
{
    public function __construct(private readonly TelemetryConfigurator $configurator) {}

    public function handle(ServerProvisioned $event): void
    {
        PendingConfiguration::query()->whereKey($event->serverId)->delete();
        $this->configurator->reconfigure($event->serverId);
    }
}
