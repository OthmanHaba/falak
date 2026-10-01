<?php

namespace Kiln\Databases\Infrastructure;

use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Network\Contracts\ContainerHostPorts;

/**
 * Engines on app/worker servers with container access: the firewall lets the server's containers (Docker bridges)
 * reach their ports. Dedicated database servers are reached over the network instead.
 */
final class DatabaseContainerPorts implements ContainerHostPorts
{
    public function for(string $serverId): array
    {
        return DatabaseServer::query()
            ->where('server_id', strtolower($serverId))
            ->where('dedicated', false)
            ->where('container_access', true)
            ->orderBy('engine')
            ->get()
            ->map(fn (DatabaseServer $engine) => [
                'id' => $engine->engine->value,
                'protocol' => 'tcp',
                'ports' => [(string) $engine->port],
                'comment' => "{$engine->engine->label()} for containers",
            ])
            ->values()
            ->all();
    }
}
