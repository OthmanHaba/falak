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
use Falak\Kernel\Security\BackupKeys;
use Falak\Kernel\Security\DecryptionFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restores a backup into an existing database of a running instance of the organization: PostgreSQL dumps into
 * PostgreSQL (same or newer major), MySQL / MariaDB dumps into either, Redis / Valkey snapshots into a Redis / Valkey
 * instance (the engine refuses an RDB version it can't load; the agent stops the container for it). The agent
 * downloads the file with a presigned GET URL, verifies its SHA-256, decrypts it (FKB1) and checks the dump's SHA-256
 * while `falak-db restore logical` loads it.
 *
 * The key: cp backups' is unwrapped here and travels in the payload; customer-held ones need the customer's age
 * identity, given for this restore only (the payload forgets it once the command settled, nothing else keeps it).
 */
final class RestoreBackup
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly BackupKeys $keys,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Backup $backup, DatabaseInstance $target, string $databaseName, ?string $actorId = null, #[\SensitiveParameter] ?string $identity = null): Restore
    {
        if (! $backup->isRestorable() || ! $backup->storageProvider) {
            throw ValidationException::withMessages(['backup' => 'Only successful, encrypted backups whose storage provider still exists can be restored.']);
        }

        if ($backup->isCustomerHeld() && ! BackupKeys::validIdentity(trim((string) $identity))) {
            throw ValidationException::withMessages(['identity' => 'This backup\'s key is customer-held: paste the age identity (AGE-SECRET-KEY-1…) that matches its recipient.']);
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

        try {
            $encryption = $this->keys->opening((string) $backup->encryption_mode, $backup->wrapped_key, $backup->organization_id, $backup->id, $identity);
        } catch (DecryptionFailed) {
            throw ValidationException::withMessages(['backup' => 'The backup\'s key can\'t be opened (it belongs to another organization or was changed).']);
        }

        $restore = DB::transaction(function () use ($backup, $target, $databaseName, $actorId, $url, $encryption) {
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
                CommandPayloads::restore($target, $databaseName, $backup, $encryption, $url),
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
            'encryption' => $backup->encryption_mode,
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
