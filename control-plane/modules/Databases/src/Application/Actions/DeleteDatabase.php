<?php

namespace Kiln\Databases\Application\Actions;

use Illuminate\Support\Str;
use Kiln\Databases\Application\AgentCommands;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Infrastructure\CommandPayloads;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Drops the database on the server; the row (and its grants) is removed once db.drop converges.
 */
final class DeleteDatabase
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Database $database): void
    {
        $server = $database->databaseServer;
        // Redis / Valkey: the instance goes (unit, config, data); its `default` user with it.
        [$type, $payload] = $server->engine->isKeyValue()
            ? ['db.redis.remove', CommandPayloads::redisRemove($server, $database)]
            : ['db.drop', CommandPayloads::drop($server, $database)];

        $handle = $this->commands->dispatch(
            $database->server_id,
            $type,
            $payload,
            (int) config('databases.timeouts.ddl', 300),
            "{$type}:{$database->id}:".Str::ulid(),
            'database',
        );

        $database->forceFill(['status' => ResourceStatus::Deleting, 'command_id' => $handle->id, 'status_message' => null])->save();

        $this->audit->record('databases.database_delete_requested', 'database', $database->id, ['name' => $database->name, 'server_id' => $database->server_id], $database->organization_id);
    }
}
