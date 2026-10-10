<?php

namespace Falak\Network\Contracts;

/**
 * Lets other modules ask for a server's firewall to be re-applied after something it depends on changed
 * (e.g. its {@see WebOriginPolicy}), read what it lets in, and close a port (Security's baseline fixes). Firewall
 * rules stay owned by Network: other modules never write them directly.
 */
interface Firewalls
{
    public function converge(string $serverId): void;

    /**
     * Re-send the full ruleset even when Network believes it is applied (the host lost or changed its table).
     */
    public function reapply(string $serverId): void;

    /**
     * The ports the server's firewall accepts, as "tcp/443", "udp/51820", "tcp/8000-8100" or "tcp/*" (an all-ports
     * rule), the SSH port included. Interface-only rules (private networks) are left out.
     *
     * @return list<string>
     */
    public function expectedPorts(string $serverId): array;

    /**
     * Ports closed to everyone by a deny rule ("tcp/8080"; an any-protocol rule counts for both).
     *
     * @return list<string>
     */
    public function deniedPorts(string $serverId): array;

    /**
     * Add a deny rule for a port from anywhere (kept if one exists already) and converge. Returns the rule id.
     */
    public function denyPort(string $serverId, string $protocol, int $port, string $name): string;

    /**
     * Delete a rule created by {@see denyPort()} and converge; false when it is gone already.
     */
    public function deleteRule(string $serverId, string $ruleId): bool;
}
