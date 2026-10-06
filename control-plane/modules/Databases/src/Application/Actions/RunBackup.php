<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\KeyValue\KeyValueBackups;
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
 * Dumps one database straight into object storage: the control plane presigns a PUT URL for a fresh
 * object key and the agent streams `db.backup` output to it. No storage credentials reach the server.
 * Redis / Valkey instances: an RDB snapshot (`<ts>-<id>.rdb.gz`), from agents with db.redis.backup only.
 */
final class RunBackup
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly ObjectStores $stores,
        private readonly AuditLog $audit,
        private readonly KeyValueBackups $keyValue,
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
        $server = $database->databaseServer;
        $background = $trigger === 'scheduled';

        $unsupported = $this->keyValue->unsupported($server);

        if ($unsupported !== null && ! $background) {
            throw ValidationException::withMessages(['database' => $unsupported]);
        }

        if ($provider->organization_id !== $database->organization_id) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        if ($database->status !== ResourceStatus::Active && ! $background) {
            throw ValidationException::withMessages(['database' => ($server->engine->isKeyValue() ? 'Instance' : 'Database')." \"{$database->name}\" is not active."]);
        }

        $store = $this->stores->for($provider);
        $backup = new Backup;
        $backup->id = (string) Str::ulid();
        $now = now()->utc();

        $backup->fill([
            'organization_id' => $database->organization_id,
            'schedule_id' => $scheduleId,
            'database_id' => $database->id,
            'database_server_id' => $server->id,
            'server_id' => $database->server_id,
            'server_name' => $server->server_name,
            'database_name' => $database->name,
            'engine' => $server->engine,
            'storage_provider_id' => $provider->id,
            'object_key' => $store->key(
                Str::slug($server->server_name).'-'.strtolower(substr($server->server_id, -6)),
                $database->name,
                $now->format('Y/m'),
                $now->format('Ymd\THis\Z').'-'.$backup->id.$compression->extension($server->engine),
            ),
            'compression' => $compression,
            'trigger' => $trigger,
            'status' => BackupStatus::Pending,
            'requested_by' => $actorId,
        ]);

        $error = match (true) {
            $database->status !== ResourceStatus::Active => ($server->engine->isKeyValue() ? 'Instance' : 'Database')." is {$database->status->value}.",
            $unsupported !== null => $unsupported,
            default => null,
        };

        if ($error !== null) {
            $backup->fill(['status' => BackupStatus::Failed, 'error' => $error, 'finished_at' => now()])->save();
            $this->failed($backup);

            return $backup;
        }

        $payload = CommandPayloads::backup($server->engine, $database->name, $compression, $store->presignPut($backup->object_key, (int) config('databases.upload_url_ttl', 43200)));
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
            'server_id' => $database->server_id,
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
