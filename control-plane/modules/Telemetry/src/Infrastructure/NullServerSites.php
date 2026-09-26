<?php

namespace Kiln\Telemetry\Infrastructure;

use Kiln\Telemetry\Contracts\ServerSites;

/**
 * Default until the Sites module binds its own ServerSites implementation.
 */
final class NullServerSites implements ServerSites
{
    public function forServer(string $serverId): array
    {
        return [];
    }
}
