<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Infrastructure\CommandPayloads;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dumps one database straight into object storage: the control plane presigns a PUT URL for a fresh object key and the
 * agent streams `falak-db backup logical` from the instance's container to it (db.backup). No storage credentials reach
 * the server. Redis / Valkey instances: an RDB snapshot (`<ts>-<id>.rdb.gz`).
 */
final class RunBackup
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  string  $trigger  manual|scheduled
     */
    public function __invoke(
        Database $database,
        StorageProvider $provider,
        Compression $compression,
        string $trigger = 'manual',
        ?string $scheduleId = null,
        ?string $actorId = null,
    ): Backup {
        $instance = $database->instance;
        $background = $trigger === 'scheduled';
        $noun = $instance->engine->isKeyValue() ? 'Instance' : 'Database';

        if ($provider->organization_id !== $database->organization_id) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        if (! $background && ($database->status !== ResourceStatus::Active || ! $instance->isRunning())) {
            throw ValidationException::withMessages(['database' => "{$noun} \"{$database->name}\" is not active."]);
        }

        $store = $this->stores->for($provider);
        $backup = new Backup;
        // Lowercase, like every HasUlids id (it is also part of the object key).
        $backup->id = strtolower((string) Str::ulid());
        $now = now()->utc();

        $backup->fill([
            'organization_id' => $database->organization_id,
            'schedule_id' => $scheduleId,
            'database_id' => $database->id,
            'database_instance_id' => $instance->id,
            'server_id' => $database->server_id,
            'server_name' => $instance->server_name,
            'instance_name' => $instance->name,
            'database_name' => $database->name,
            'engine' => $instance->engine,
            'engine_version' => $instance->version,
            'storage_provider_id' => $provider->id,
            'object_key' => $store->key(
                Str::slug($instance->name).'-'.substr($instance->id, -6),
                $database->name,
                $now->format('Y/m'),
                $now->format('Ymd\THis\Z').'-'.$backup->id.$compression->extension($instance->engine),
            ),
            'compression' => $compression,
            'trigger' => $trigger,
            'status' => BackupStatus::Pending,
            'requested_by' => $actorId,
        ]);

        if ($database->status !== ResourceStatus::Active || ! $instance->isRunning()) {
            $backup->fill(['status' => BackupStatus::Failed, 'error' => $database->status !== ResourceStatus::Active ? "{$noun} is {$database->status->value}." : "The database server is {$instance->status->value}.", 'finished_at' => now()])->save();
            $this->failed($backup);

            return $backup;
        }

        $payload = CommandPayloads::backup($instance, $database->name, $compression, $store->presignPut($backup->object_key, (int) config('databases.upload_url_ttl', 43200)));
        $key = "db.backup:{$backup->id}";
        $timeout = (int) config('databases.timeouts.backup', 3600);

        $handle = $background
            ? $this->commands->tryDispatch($database->server_id, 'db.backup', $payload, $timeout, $key)
            : $this->commands->dispatch($database->server_id, 'db.backup', $payload, $timeout, $key, 'database');

        if (! $handle) {
            $backup->fill(['status' => BackupStatus::Failed, 'error' => AgentCommands::NOT_CONNECTED, 'finished_at' => now()])->save();
            $this->failed($backup);

            return $backup;
        }

        // Started: handed to the agent (the control plane learns of the agent starting it only from its result).
        $backup->fill(['command_id' => $handle->id, 'started_at' => now()])->save();

        $this->audit->record('databases.backup_started', 'backup', $backup->id, [
            'database' => $database->name,
            'instance_id' => $instance->id,
            'storage_provider' => $provider->name,
            'trigger' => $trigger,
        ], $database->organization_id);

        return $backup;
    }

    private function failed(Backup $backup): void
    {
        BackupFailed::dispatch($backup->id, $backup->organization_id, $backup->server_id, $backup->server_name, $backup->database_name, (string) $backup->error, $backup->schedule_id, $backup->trigger);
    }
}
