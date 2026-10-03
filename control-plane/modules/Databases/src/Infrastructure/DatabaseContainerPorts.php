<?php

namespace Kiln\Databases\Infrastructure;

use Kiln\Databases\Domain\Enums\EngineKind;
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
            ->whereIn('engine', EngineKind::Sql->values())
            ->where('dedicated', false)
            ->where('container_access', true)
            ->orderBy('engine')
            ->get()
            ->map(fn (DatabaseServer $engine) => [
                'id' => $engine->engine->value,
                'protocol' => 'tcp',
                'ports' => [(string) $engine->port],
                // Only the Docker ranges, on the bridges; the agent drops the port for everyone else (private-network
                // peers and broad user rules included), so the app-server engine stays local to the server.
                'sources' => array_values((array) config('databases.container_networks', [])),
                'comment' => "{$engine->engine->label()} for containers",
            ])
            ->values()
            ->all();
    }
}
