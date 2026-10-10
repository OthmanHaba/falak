<?php

namespace Falak\Databases\Infrastructure;

use DateTimeImmutable;
use Falak\Databases\Application\Actions\RelocateInstance;
use Falak\Databases\Application\Actions\RestoreBackup;
use Falak\Databases\Contracts\Data\DatabaseRecoveryPoint;
use Falak\Databases\Contracts\Data\InstanceRecoveryPoint;
use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Enums\RestoreStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\Restore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class EloquentDatabaseRecovery implements DatabaseRecovery
{
    public function __construct(
        private readonly RelocateInstance $relocate,
        private readonly RestoreBackup $restore,
    ) {}

    public function instancesOn(string $serverId): array
    {
        $instances = DatabaseInstance::query()->with('databases')->where('server_id', $serverId)
            ->whereNotIn('status', [InstanceStatus::Retired, InstanceStatus::Deleting])->orderBy('name')->get();
        $points = $this->points($instances->flatMap(fn (DatabaseInstance $instance) => $instance->databases->modelKeys())->values()->all());

        return $instances->map(fn (DatabaseInstance $instance) => new InstanceRecoveryPoint(
            $instance->id,
            $instance->organization_id,
            $instance->server_id,
            $instance->name,
            $instance->engine->value,
            $instance->version,
            $instance->environment_id,
            $instance->pitr_enabled,
            array_values(array_filter(array_map(fn (string $id) => $points[$id] ?? null, $instance->databases->modelKeys()))),
        ))->values()->all();
    }

    public function points(array $databaseIds): array
    {
        if ($databaseIds === []) {
            return [];
        }

        $databases = Database::query()->with('instance')->whereIn('id', $databaseIds)->get();
        $instanceIds = $databases->pluck('database_instance_id')->unique()->values()->all();
        $scheduled = BackupSchedule::query()->whereIn('database_instance_id', $instanceIds)->where('enabled', true)->pluck('database_instance_id')->flip();
        $backups = Backup::query()->where('status', BackupStatus::Succeeded)
            ->where(fn ($q) => $q->whereIn('database_id', $databases->modelKeys())->orWhereIn('database_instance_id', $instanceIds))
            ->orderByDesc('finished_at')->get();

        $points = [];

        foreach ($databases as $database) {
            /** @var Database $database */
            $own = $backups->filter(fn (Backup $backup) => $backup->database_id === $database->id
                || ($backup->database_id === null && $backup->database_instance_id === $database->database_instance_id && $backup->database_name === $database->name));
            $latest = $own->first(fn (Backup $backup) => $backup->isRestorable());
            $drilled = $own->max(fn (Backup $backup) => $backup->verified_at?->getTimestamp());
            $instance = $database->instance;

            $points[$database->id] = new DatabaseRecoveryPoint(
                $database->id,
                $database->organization_id,
                $instance->id,
                $instance->name,
                $database->name,
                $instance->engine->value,
                $database->site_id,
                $latest?->id,
                self::time($latest?->finished_at ?? $latest?->created_at),
                (bool) $latest?->isCustomerHeld(),
                $drilled !== null ? (new DateTimeImmutable)->setTimestamp((int) $drilled) : null,
                $scheduled->has($instance->id),
                $instance->pitr_enabled,
                ! $instance->engine->isKeyValue(),
            );
        }

        return $points;
    }

    public function relocate(string $instanceId, string $targetServerId, ?string $actorId = null): void
    {
        ($this->relocate)($this->instance($instanceId), $targetServerId, $actorId);
    }

    public function restoreLatest(string $instanceId, ?string $actorId = null): array
    {
        $instance = $this->instance($instanceId);
        $points = $this->points($instance->databases()->pluck('id')->all());
        $restores = [];
        $skipped = [];

        foreach ($instance->databases()->get() as $database) {
            $point = $points[$database->id] ?? null;

            if ($point === null || $point->backupId === null) {
                $skipped[] = "{$database->name}: no restorable backup (it starts empty)";

                continue;
            }

            if ($point->customerHeld) {
                $skipped[] = "{$database->name}: its backups use your own key; restore the latest one on the Databases page with your age identity";

                continue;
            }

            $backup = Backup::query()->with('storageProvider')->findOrFail($point->backupId);
            $restores[] = ($this->restore)($backup, $instance, $database->name, $actorId)->id;
        }

        return ['restores' => $restores, 'skipped' => $skipped];
    }

    public function progress(string $instanceId, array $restoreIds = []): array
    {
        $instance = DatabaseInstance::query()->find($instanceId);

        if ($instance === null) {
            return ['state' => 'failed', 'message' => 'The database was deleted.'];
        }

        if ($instance->status === InstanceStatus::Failed) {
            return ['state' => 'failed', 'message' => $instance->status_message ?: 'The container could not be created.'];
        }

        if ($restoreIds === []) {
            $databases = $instance->databases()->get();
            $failed = $databases->first(fn (Database $database) => $database->status === ResourceStatus::Failed);

            return match (true) {
                $failed !== null => ['state' => 'failed', 'message' => "Creating {$failed->name} failed: {$failed->status_message}"],
                $instance->status === InstanceStatus::Active && $databases->every(fn (Database $database) => $database->status === ResourceStatus::Active) => ['state' => 'ready', 'message' => null],
                default => ['state' => 'pending', 'message' => $instance->status_message],
            };
        }

        /** @var Collection<int, Restore> $restores */
        $restores = Restore::query()->whereIn('id', $restoreIds)->get();
        $failed = $restores->first(fn (Restore $restore) => $restore->status === RestoreStatus::Failed);

        return match (true) {
            $failed !== null => ['state' => 'failed', 'message' => "Restoring {$failed->database_name} failed: {$failed->error}"],
            $restores->count() === count($restoreIds) && $restores->every(fn (Restore $restore) => $restore->status === RestoreStatus::Succeeded) => ['state' => 'succeeded', 'message' => null],
            default => ['state' => 'running', 'message' => null],
        };
    }

    private function instance(string $instanceId): DatabaseInstance
    {
        return DatabaseInstance::query()->find($instanceId) ?? throw ValidationException::withMessages(['database_instance_id' => 'Unknown database.']);
    }

    private static function time(?Carbon $at): ?DateTimeImmutable
    {
        return $at?->toDateTimeImmutable();
    }
}
