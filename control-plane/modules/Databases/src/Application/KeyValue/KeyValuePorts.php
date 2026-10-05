<?php

namespace Falak\Databases\Application\KeyValue;

use Illuminate\Validation\ValidationException;
use Falak\Databases\Domain\Models\Database;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * Ports of Redis / Valkey instances: the lowest free port of databases.key_value.ports (6380–6479) on the server.
 * Taken: the server's other instances (both engines) and what its latest machine check saw listening. The agent
 * re-checks before it starts an instance ("port 6381 is in use by …").
 */
final class KeyValuePorts
{
    public function __construct(private readonly ServerDirectory $servers) {}

    /**
     * Call inside a transaction that locked the server's engine rows, so two creations never pick the same port.
     *
     * @param  list<int>  $avoid  ports the agent found taken
     *
     * @throws ValidationException when every port is taken
     */
    public function allocate(string $serverId, array $avoid = []): int
    {
        [$from, $to] = array_map('intval', (array) config('databases.key_value.ports', [6380, 6479]));

        $taken = array_flip([
            ...Database::query()->where('server_id', $serverId)->whereNotNull('port')->pluck('port')->map(fn ($port) => (int) $port)->all(),
            ...$this->servers->takenPorts($serverId),
            ...$avoid,
        ]);

        for ($port = $from; $port <= $to; $port++) {
            if (! isset($taken[$port])) {
                return $port;
            }
        }

        throw ValidationException::withMessages(['server_id' => "No free port left for another instance ({$from}–{$to})."]);
    }
}
