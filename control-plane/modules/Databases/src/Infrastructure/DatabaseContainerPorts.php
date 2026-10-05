<?php

namespace Kiln\Databases\Infrastructure;

use Kiln\Databases\Application\KeyValue\KeyValueNetwork;
use Kiln\Databases\Domain\Enums\EngineKind;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Network\Contracts\ContainerHostPorts;

/**
 * Engines on app/worker servers with container access: the firewall lets the server's containers (Docker bridges)
 * reach their ports. Dedicated database servers are reached over the network instead.
 *
 * Redis / Valkey instances (agents with db.redis.network): each instance's own port, for the containers and for the
 * private addresses of the servers whose sites use it (peers), dropped for everyone else — public access stays
 * closed, and other members of a private network don't reach it either.
 */
final class DatabaseContainerPorts implements ContainerHostPorts
{
    public function __construct(private readonly KeyValueNetwork $network) {}

    public function for(string $serverId): array
    {
        $serverId = strtolower($serverId);

        $sql = DatabaseServer::query()
            ->where('server_id', $serverId)
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

        $instances = Database::query()
            ->with('databaseServer')
            ->where('server_id', $serverId)
            ->whereNotNull('port')
            ->whereIn('status', [ResourceStatus::Active, ResourceStatus::Pending])
            ->whereHas('databaseServer', fn ($q) => $q->whereIn('engine', EngineKind::KeyValue->values())->where('container_access', true))
            ->orderBy('port')
            ->get();

        $keyValue = [];

        foreach ($instances as $instance) {
            $desired = $this->network->desired($instance);
            $sources = $desired['containers'] ? KeyValueNetwork::containerRanges() : [];

            if ($sources === [] && $desired['peers'] === []) {
                continue;
            }

            $keyValue[] = array_filter([
                'id' => "{$instance->databaseServer->engine->value}-{$instance->name}",
                'protocol' => 'tcp',
                'ports' => [(string) $instance->port],
                'sources' => $sources,
                'peers' => $desired['peers'] ?: null,
                // The WireGuard interface of each peer that arrives on one: its accept rule names it (iifname).
                'peer_interfaces' => $desired['peer_interfaces'] ?: null,
                'comment' => mb_substr("{$instance->databaseServer->engine->label()} {$instance->name}", 0, 120),
            ], fn ($value) => $value !== null);
        }

        return [...$sql, ...$keyValue];
    }
}
