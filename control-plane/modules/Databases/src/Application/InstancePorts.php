<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Models\DatabaseInstance;
use Illuminate\Validation\ValidationException;

/**
 * Host ports of database containers (databases.host_ports): one per instance on its server, published on 127.0.0.1
 * for native sites and on private addresses for other servers. Callers allocate inside a transaction holding a lock on
 * the server's instances; the unique (server_id, host_port) index catches anything else.
 */
final class InstancePorts
{
    /**
     * @param  list<int>  $avoid
     *
     * @throws ValidationException when the range is exhausted
     */
    public function allocate(string $serverId, array $avoid = []): int
    {
        [$from, $to] = array_map('intval', (array) config('databases.host_ports', [20000, 29999]));
        $taken = array_flip([...DatabaseInstance::query()->where('server_id', $serverId)->whereNotNull('host_port')->pluck('host_port')->map(fn ($port) => (int) $port)->all(), ...$avoid]);

        for ($port = $from; $port <= $to; $port++) {
            if (! isset($taken[$port])) {
                return $port;
            }
        }

        throw ValidationException::withMessages(['server_id' => 'The server has no free port for another database.']);
    }
}
