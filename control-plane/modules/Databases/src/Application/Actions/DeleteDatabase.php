<?php

namespace Falak\Databases\Application\Actions;

use Illuminate\Support\Str;
use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Identity\Contracts\AuditLog;

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
            // db.redis.remove may wait for an apply of the instance (1 h timeout) before it runs.
            (int) ($server->engine->isKeyValue() ? config('databases.timeouts.redis_apply', 3600) : config('databases.timeouts.ddl', 300)),
            "{$type}:{$database->id}:".Str::ulid(),
            'database',
        );

        $database->forceFill(['status' => ResourceStatus::Deleting, 'command_id' => $handle->id, 'status_message' => null])->save();

        $this->audit->record('databases.database_delete_requested', 'database', $database->id, ['name' => $database->name, 'server_id' => $database->server_id], $database->organization_id);
    }
}
