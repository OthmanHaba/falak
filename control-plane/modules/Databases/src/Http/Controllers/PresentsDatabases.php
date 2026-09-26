<?php

namespace Kiln\Databases\Http\Controllers;

use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Domain\Models\Grant;
use Kiln\Databases\Domain\Models\Restore;
use Kiln\Databases\Domain\Models\StorageProvider;

/**
 * Array shapes sent to the Inertia pages (never secrets).
 */
trait PresentsDatabases
{
    /**
     * @return array<string, mixed>
     */
    protected function presentServer(DatabaseServer $server): array
    {
        return [
            'id' => $server->id,
            'server_id' => $server->server_id,
            'server_name' => $server->server_name,
            'engine' => $server->engine->value,
            'engine_label' => $server->engine->label(),
            'version' => $server->version,
            'version_source' => $server->version_source,
            'dedicated' => $server->dedicated,
            'port' => $server->port,
            'databases_count' => $server->databases_count ?? null,
            'users_count' => $server->users_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDatabase(Database $database): array
    {
        return [
            'id' => $database->id,
            'name' => $database->name,
            'charset' => $database->charset,
            'collation' => $database->collation,
            'site_id' => $database->site_id,
            'status' => $database->status->value,
            'status_message' => $database->status_message,
            'command_id' => $database->command_id,
            'created_at' => $database->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentUser(DatabaseUser $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'host' => $user->host,
            'site_id' => $user->site_id,
            'status' => $user->status->value,
            'status_message' => $user->status_message,
            'command_id' => $user->command_id,
            'grants' => $user->grants->map(fn (Grant $grant) => [
                'database_id' => $grant->database_id,
                'database' => $grant->database?->name,
                'privileges' => $grant->privileges,
            ])->sortBy('database')->values(),
            'created_at' => $user->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSchedule(BackupSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'cron' => $schedule->cron,
            'storage_provider_id' => $schedule->storage_provider_id,
            'storage_provider' => $schedule->storageProvider?->name,
            'database_ids' => $schedule->databases->pluck('id')->values(),
            'databases' => $schedule->databases->pluck('name')->values(),
            'retention_count' => $schedule->retention_count,
            'retention_days' => $schedule->retention_days,
            'compression' => $schedule->compression->value,
            'enabled' => $schedule->enabled,
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentBackup(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'database_name' => $backup->database_name,
            'server_id' => $backup->server_id,
            'server_name' => $backup->server_name,
            'database_server_id' => $backup->database_server_id,
            'engine' => $backup->engine->value,
            'storage_provider' => $backup->storageProvider?->name,
            'object_key' => $backup->object_key,
            'compression' => $backup->compression->value,
            'trigger' => $backup->trigger,
            'schedule_id' => $backup->schedule_id,
            'status' => $backup->status->value,
            'size_bytes' => $backup->size_bytes,
            'sha256' => $backup->sha256,
            'duration_ms' => $backup->duration_ms,
            'error' => $backup->error,
            'prune_error' => $backup->prune_error,
            'command_id' => $backup->command_id,
            'restorable' => $backup->isRestorable(),
            'created_at' => $backup->created_at->toIso8601String(),
            'finished_at' => $backup->finished_at?->toIso8601String(),
            'pruned_at' => $backup->pruned_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRestore(Restore $restore): array
    {
        return [
            'id' => $restore->id,
            'backup_id' => $restore->backup_id,
            'source_database' => $restore->backup->database_name,
            'database_name' => $restore->database_name,
            'status' => $restore->status->value,
            'bytes' => $restore->bytes,
            'duration_ms' => $restore->duration_ms,
            'error' => $restore->error,
            'command_id' => $restore->command_id,
            'created_at' => $restore->created_at->toIso8601String(),
            'finished_at' => $restore->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentProvider(StorageProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'driver' => $provider->driver->value,
            'driver_label' => $provider->driver->label(),
            'endpoint' => $provider->endpoint,
            'region' => $provider->region,
            'bucket' => $provider->bucket,
            'prefix' => $provider->prefix,
            'path_style' => $provider->path_style,
            // Only a hint of which key is configured; the secret never leaves the server.
            'access_key_hint' => '…'.substr($provider->access_key_id, -4),
            'verified_at' => $provider->verified_at?->toIso8601String(),
            'created_at' => $provider->created_at->toIso8601String(),
        ];
    }
}
