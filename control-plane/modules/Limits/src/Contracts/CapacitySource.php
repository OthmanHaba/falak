<?php

namespace Falak\Limits\Contracts;

use Falak\Limits\Contracts\Data\CapacityItem;

/**
 * Something that runs services on servers and knows their limits (Sites, Processes, Databases, Functions). Register
 * an implementation with {@see CapacitySources::register()}; the capacity view of a server sums every source.
 */
interface CapacitySource
{
    /**
     * The services this source runs on the server (with or without limits), of the server's organization only.
     *
     * @return list<CapacityItem>
     */
    public function onServer(string $organizationId, string $serverId): array;
}
