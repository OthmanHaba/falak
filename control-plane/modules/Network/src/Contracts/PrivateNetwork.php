<?php

namespace Falak\Network\Contracts;

use Falak\Network\Contracts\Data\PrivateNetworkMembership;

/**
 * Private (WireGuard) network addresses of servers, for other modules — e.g. Edge pointing load
 * balancer upstreams or Databases showing a private connection host.
 */
interface PrivateNetwork
{
    /**
     * The server's bare private IPv4 address (e.g. "10.90.0.3") in $networkId, or in the oldest
     * network it belongs to when null. Null when the server is in no (matching) private network.
     */
    public function addressOf(string $serverId, ?string $networkId = null): ?string;

    /**
     * @return list<PrivateNetworkMembership> oldest network first
     */
    public function networksOf(string $serverId): array;
}
