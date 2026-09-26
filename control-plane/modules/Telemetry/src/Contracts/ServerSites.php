<?php

namespace Kiln\Telemetry\Contracts;

use Kiln\Telemetry\Contracts\Data\SiteTelemetryTarget;

/**
 * Sites hosted on a server, for the `sites` / `log_sources` part of telemetry.configure.
 *
 * Telemetry ships a default binding that returns no sites. The Sites module will bind its own
 * implementation (and call {@see TelemetryConfigurator::reconfigure()} when sites change).
 */
interface ServerSites
{
    /**
     * @return list<SiteTelemetryTarget>
     */
    public function forServer(string $serverId): array;
}
