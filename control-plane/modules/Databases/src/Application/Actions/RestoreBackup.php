<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\Identifiers;
use Falak\Databases\Application\KeyValue\KeyValueBackups;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restores a backup into a database (created by the agent when missing) on any database server of the
 * organization with the same wire engine. The agent downloads the dump with a presigned GET URL and
 * verifies its SHA-256 before loading it.
 *
 * Redis / Valkey: an RDB snapshot goes into an existing, active instance of either key-value engine (the agent checks
 * that the installed server loads the snapshot's RDB version, e.g. Valkey refuses Redis 7.4+ snapshots, before it
 * changes anything); SQL dumps never go into an instance and snapshots never into a SQL engine.
 */
final class RestoreBackup
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly AuditLog $audit,
        private readonly KeyValueBackups $keyValue,
    ) {}

    public function __invoke(Backup $backup, DatabaseServer $target, string $databaseName, ?string $actorId = null): Restore
    {
        if (! $backup->isRestorable() || ! $backup->storageProvider) {
            throw ValidationException::withMessages(['backup' => 'Only successful backups whose storage provider still exists can be restored.']);
        }

        if ($target->organization_id !== $backup->organization_id) {
            throw ValidationException::withMessages(['database_server_id' => 'Choose a database server of this organization.']);
        }

        if ($target->engine->isKeyValue() !== $backup->engine->isKeyValue()) {
            throw ValidationException::withMessages(['database_server_id' => $backup->engine->isKeyValue()
                ? "A {$backup->engine->label()} snapshot can only be restored into a Redis or Valkey instance, not into {$target->engine->label()}."
                : "A {$backup->engine->label()} dump cannot be restored into a {$target->engine->label()} instance."]);
        }

        if (! $target->engine->isKeyValue() && $target->engine->protocol() !== $backup->engine->protocol()) {
            throw ValidationException::withMessages(['database_server_id' => "A {$backup->engine->label()} dump cannot be restored into {$target->engine->label()}."]);
        }

        Identifiers::assertValid($target->engine, $databaseName, 'database');

        if ($target->engine->isKeyValue()) {
            // Instances are never created by a restore: their port, password and unit come from the instance.
            $instance = $target->databases()->where('name', $databaseName)->first();

            if (! $instance) {
                throw ValidationException::withMessages(['database' => "{$target->server_name} has no {$target->engine->label()} instance named \"{$databaseName}\"."]);
            }

            if ($instance->status !== ResourceStatus::Active) {
                throw ValidationException::withMessages(['database' => "Instance \"{$databaseName}\" is {$instance->status->value}."]);
            }

            $this->keyValue->assertSupported($target, 'database_server_id');
        }

        $running = Restore::query()->where('database_server_id', $target->id)->where('database_name', $databaseName)
            ->whereIn('status', [RestoreStatus::Pending, RestoreStatus::Running])->exists();

        if ($running) {
            throw ValidationException::withMessages(['database' => "A restore into \"{$databaseName}\" is already running."]);
        }

        $url = $this->stores->for($backup->storageProvider)->presignGet($backup->object_key, (int) config('databases.download_url_ttl', 21600));

        $restore = DB::transaction(function () use ($backup, $target, $databaseName, $actorId, $url) {
            $restore = Restore::query()->create([
                'organization_id' => $backup->organization_id,
                'backup_id' => $backup->id,
                'database_server_id' => $target->id,
                'server_id' => $target->server_id,
                'database_name' => $databaseName,
                'status' => RestoreStatus::Pending,
                'requested_by' => $actorId,
            ]);

            $handle = $this->commands->dispatch(
                $target->server_id,
                'db.restore',
                CommandPayloads::restore($target->engine, $databaseName, $backup->compression, $url, $backup->sha256, $backup->uncompressed_bytes),
                (int) config('databases.timeouts.restore', 3600),
                "db.restore:{$restore->id}",
                'database_server_id',
            );

            $restore->forceFill(['command_id' => $handle->id])->save();

            return $restore;
        });

        $this->audit->record('databases.restore_requested', 'backup', $backup->id, [
            'restore_id' => $restore->id,
            'source_database' => $backup->database_name,
            'target_server_id' => $target->server_id,
            'target_database' => $databaseName,
        ], $backup->organization_id);

        return $restore;
    }
}
