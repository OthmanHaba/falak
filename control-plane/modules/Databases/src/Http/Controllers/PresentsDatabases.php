<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\RestoreBackup;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Grant;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;

/**
 * Array shapes sent to the Inertia pages (never secrets).
 */
trait PresentsDatabases
{
    /**
     * @return array<string, mixed>
     */
    protected function presentInstance(DatabaseInstance $instance): array
    {
        return [
            'id' => $instance->id,
            'name' => $instance->name,
            'server_id' => $instance->server_id,
            'server_name' => $instance->server_name,
            'environment_id' => $instance->environment_id,
            'engine' => $instance->engine->value,
            'engine_label' => $instance->engine->label(),
            'kind' => $instance->engine->kind()->value,
            'version' => $instance->version,
            'image' => $instance->image,
            'image_digest' => $instance->image_digest,
            'hostname' => $instance->hostname,
            'port' => $instance->port,
            'host_port' => $instance->host_port,
            'published_addresses' => array_values((array) ($instance->published_addresses ?? [])),
            // Waiting to be applied: a restart (new container) is required.
            'pending_published_addresses' => $instance->pending_published_addresses !== null ? array_values($instance->pending_published_addresses) : null,
            'allowed_sources' => array_values((array) ($instance->allowed_sources ?? [])),
            'password_overlap_until' => $instance->password_overlap_until?->toIso8601String(),
            'replaced_by' => $instance->replaced_by,
            'public_access' => $instance->public_access,
            'require_tls' => $instance->require_tls,
            'memory_mb' => intdiv($instance->memory_bytes, 1024 ** 2),
            'cpus' => $instance->cpus,
            'settings' => (object) ($instance->settings ?? []),
            'pitr_enabled' => $instance->pitr_enabled,
            'volume_id' => $instance->volume_id,
            'tls_expires_at' => $instance->tls_expires_at?->toIso8601String(),
            'status' => $instance->status->value,
            'status_message' => $instance->status_message,
            'health' => $instance->health,
            'health_at' => $instance->health_at?->toIso8601String(),
            'rotating_password' => $instance->next_root_password !== null,
            'upgrade_of' => $instance->upgrade_of,
            'retire_at' => $instance->retire_at?->toIso8601String(),
            'databases_count' => $instance->databases_count ?? null,
            'users_count' => $instance->users_count ?? null,
            'created_at' => $instance->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDatabase(Database $database): array
    {
        return [
            'id' => $database->id,
            'instance_id' => $database->database_instance_id,
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
            'instance_id' => $backup->database_instance_id,
            'instance_name' => $backup->instance_name,
            'engine' => $backup->engine->value,
            'engine_version' => $backup->engine_version,
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
            'started_at' => $backup->started_at?->toIso8601String(),
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
            'warnings' => $restore->warnings ?? [],
            'command_id' => $restore->command_id,
            'created_at' => $restore->created_at->toIso8601String(),
            'finished_at' => $restore->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Running instances a backup of the source's engine can be restored into (RestoreBackup::compatible), with their
     * active databases (a restore goes into an existing one).
     *
     * @return list<array{id: string, label: string, engine: string, databases: list<string>}>
     */
    protected function restoreTargets(DatabaseInstance $source): array
    {
        $probe = new Backup(['engine' => $source->engine, 'engine_version' => $source->version]);

        return DatabaseInstance::query()->where('organization_id', $source->organization_id)->where('status', InstanceStatus::Active)->orderBy('name')->get()
            ->filter(fn (DatabaseInstance $target) => RestoreBackup::compatible($probe, $target))
            ->map(fn (DatabaseInstance $target) => [
                'id' => $target->id,
                'label' => "{$target->name} ({$target->label()} on {$target->server_name})",
                'engine' => $target->engine->value,
                'databases' => $target->databases()->where('status', 'active')->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();
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
