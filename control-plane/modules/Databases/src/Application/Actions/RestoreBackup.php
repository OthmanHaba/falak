<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restores a backup into an existing database of a running instance of the organization: PostgreSQL dumps into
 * PostgreSQL (same or newer major), MySQL / MariaDB dumps into either, Redis / Valkey snapshots into a Redis / Valkey
 * instance (the engine refuses an RDB version it can't load; the agent stops the container for it). The agent
 * downloads the dump with a presigned GET URL and verifies its SHA-256 before `falak-db restore logical` loads it.
 */
final class RestoreBackup
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Backup $backup, DatabaseInstance $target, string $databaseName, ?string $actorId = null): Restore
    {
        if (! $backup->isRestorable() || ! $backup->storageProvider) {
            throw ValidationException::withMessages(['backup' => 'Only successful backups whose storage provider still exists can be restored.']);
        }

        if ($target->organization_id !== $backup->organization_id) {
            throw ValidationException::withMessages(['database_instance_id' => 'Choose a database of this organization.']);
        }

        if (! self::compatible($backup, $target)) {
            throw ValidationException::withMessages(['database_instance_id' => "A {$backup->engine->label()} backup cannot be restored into {$target->label()}."]);
        }

        if (! $target->isRunning()) {
            throw ValidationException::withMessages(['database_instance_id' => "{$target->name} is {$target->status->value}."]);
        }

        $database = $target->databases()->where('name', $databaseName)->first();

        if ($database === null || $database->status !== ResourceStatus::Active) {
            throw ValidationException::withMessages(['database' => $database === null
                ? "{$target->name} has no database named \"{$databaseName}\": create it first."
                : "\"{$databaseName}\" is {$database->status->value}."]);
        }

        $running = Restore::query()->where('database_instance_id', $target->id)->where('database_name', $databaseName)
            ->whereIn('status', [RestoreStatus::Pending, RestoreStatus::Running])->exists();

        if ($running) {
            throw ValidationException::withMessages(['database' => "A restore into \"{$databaseName}\" is already running."]);
        }

        $url = $this->stores->for($backup->storageProvider)->presignGet($backup->object_key, (int) config('databases.download_url_ttl', 21600));

        $restore = DB::transaction(function () use ($backup, $target, $databaseName, $actorId, $url) {
            $restore = Restore::query()->create([
                'organization_id' => $backup->organization_id,
                'backup_id' => $backup->id,
                'database_instance_id' => $target->id,
                'server_id' => $target->server_id,
                'database_name' => $databaseName,
                'status' => RestoreStatus::Pending,
                'requested_by' => $actorId,
            ]);

            $handle = $this->commands->dispatch(
                $target->server_id,
                'db.restore',
                CommandPayloads::restore($target, $databaseName, $backup->compression, $url, $backup->sha256),
                (int) config('databases.timeouts.restore', 3600),
                "db.restore:{$restore->id}",
                'database_instance_id',
            );

            $restore->forceFill(['command_id' => $handle->id])->save();

            return $restore;
        });

        $this->audit->record('databases.restore_requested', 'backup', $backup->id, [
            'restore_id' => $restore->id,
            'source_database' => $backup->database_name,
            'target_instance_id' => $target->id,
            'target_database' => $databaseName,
        ], $backup->organization_id);

        return $restore;
    }

    public static function compatible(Backup $backup, DatabaseInstance $target): bool
    {
        return match (true) {
            $backup->engine->isKeyValue() => $target->engine->isKeyValue(),
            $backup->engine->isMysqlFamily() => $target->engine->isMysqlFamily(),
            default => $target->engine === $backup->engine
                && ($backup->engine_version === null || version_compare($target->version, $backup->engine_version, '>=')),
        };
    }
}
