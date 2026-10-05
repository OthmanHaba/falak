<?php

namespace Falak\Telemetry\Contracts;

/**
 * Pushes `telemetry.configure` to agents. Other modules call reconfigure() after changes
 * that affect a server's telemetry (e.g. a site was added or deployed).
 */
interface TelemetryConfigurator
{
    /**
     * Queue telemetry.configure for one server (no-op when it has no active agent).
     * Returns the command id, or null when nothing was dispatched.
     */
    public function reconfigure(string $serverId): ?string;

    /**
     * Queue telemetry.configure for every active server of the organization.
     *
     * @return int number of servers dispatched to
     */
    public function reconfigureOrganization(string $organizationId): int;
}
