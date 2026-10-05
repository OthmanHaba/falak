<?php

namespace Falak\Network\Infrastructure;

use Falak\Network\Contracts\ContainerHostPorts;

/** Default: containers reach no host port. */
final class NoContainerHostPorts implements ContainerHostPorts
{
    public function for(string $serverId): array
    {
        return [];
    }
}
