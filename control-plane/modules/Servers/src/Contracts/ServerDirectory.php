<?php

namespace Falak\Servers\Contracts;

use Falak\Servers\Contracts\Data\PhpSettings;
use Falak\Servers\Contracts\Data\ServerData;

/**
 * Read-only server lookups for other modules (Sites, Deployments, Databases, Network, ...).
 */
interface ServerDirectory
{
    public function find(string $serverId): ?ServerData;

    /**
     * @param  list<ServerType>|null  $types
     * @return list<ServerData>
     */
    public function forOrganization(string $organizationId, ?array $types = null, bool $activeOnly = false): array;

    /**
     * php.ini overrides and FPM pool defaults for an installed PHP version (used when creating site pools).
     */
    public function phpSettings(string $serverId, string $version): ?PhpSettings;

    /**
     * TCP ports the server's latest machine check saw in use (listeners and ports published by containers); empty
     * when it was never checked. Used to pick free ports (database containers' host ports) before the agent re-checks.
     *
     * @return list<int>
     */
    public function takenPorts(string $serverId): array;
}
