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

        $handle = $this->commands->dispatch(
            $database->server_id,
            'db.drop',
            CommandPayloads::drop($server, $database),
            (int) config('databases.timeouts.ddl', 300),
            "db.drop:{$database->id}:".Str::ulid(),
            'database',
        );

        $database->forceFill(['status' => ResourceStatus::Deleting, 'command_id' => $handle->id, 'status_message' => null])->save();

        $this->audit->record('databases.database_delete_requested', 'database', $database->id, ['name' => $database->name, 'server_id' => $database->server_id], $database->organization_id);
    }
}
