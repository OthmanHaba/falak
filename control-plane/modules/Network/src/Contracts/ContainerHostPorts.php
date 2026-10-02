<?php

namespace Kiln\Network\Contracts;

/**
 * Host ports the containers on a server may reach, decided by another module (Databases: engines on app/worker
 * servers used by compose stacks, Docker sites and functions). The firewall accepts them on the Docker bridges from the
 * sources only, and drops them for everyone else.
 */
interface ContainerHostPorts
{
    /**
     * @return list<array{id: string, protocol: 'tcp'|'udp', ports: list<string>, sources: list<string>, comment: string}>
     */
    public function for(string $serverId): array;
}
