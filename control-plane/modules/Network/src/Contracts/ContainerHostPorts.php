<?php

namespace Falak\Network\Contracts;

/**
 * Host ports the containers on a server may reach, decided by another module (Databases: engines on app/worker
 * servers used by compose stacks, Docker sites and functions; Redis / Valkey instances). The firewall accepts them on the
 * Docker bridges from the sources only, from the peers (other servers' addresses, any interface; agents with
 * db.redis.network), and drops them for everyone else.
 */
interface ContainerHostPorts
{
    /**
     * @return list<array{id: string, protocol: 'tcp'|'udp', ports: list<string>, sources: list<string>, peers?: list<string>, comment: string}>
     */
    public function for(string $serverId): array;
}
