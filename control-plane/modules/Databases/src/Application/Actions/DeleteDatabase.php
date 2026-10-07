<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Drops a database of a SQL instance (db.drop); the row (and its grants) goes once the agent confirms. A Redis / Valkey
 * keyspace, and an instance's last database, go with the instance ({@see InstanceLifecycle::delete()}).
 */
final class DeleteDatabase
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly InstanceLifecycle $lifecycle,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  bool  $withInstance  the database is the instance's only one (a canvas service): delete the instance
     */
    public function __invoke(Database $database, bool $withInstance = false, bool $deleteVolume = false): void
    {
        $instance = $database->instance;

        if ($instance->engine->isKeyValue() || ($withInstance && $instance->databases()->count() === 1)) {
            $this->lifecycle->delete($instance, $deleteVolume);

            return;
        }

        if (! $instance->isRunning()) {
            throw ValidationException::withMessages(['database' => "The database server is {$instance->status->value}."]);
        }

        $handle = $this->commands->dispatch(
            $database->server_id,
            'db.drop',
            CommandPayloads::drop($instance, $database),
            (int) config('databases.timeouts.ddl', 300),
            "db.drop:{$database->id}:".Str::ulid(),
            'database',
        );

        $database->forceFill(['status' => ResourceStatus::Deleting, 'command_id' => $handle->id, 'status_message' => null])->save();

        $this->audit->record('databases.database_delete_requested', 'database', $database->id, ['name' => $database->name, 'instance_id' => $instance->id], $database->organization_id);
    }
}
