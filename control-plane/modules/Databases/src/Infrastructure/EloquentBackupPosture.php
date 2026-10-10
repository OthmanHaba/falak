<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Contracts\BackupPosture;
use Falak\Databases\Contracts\Data\DatabaseBackupPosture;
use Falak\Databases\Contracts\DrillStatus;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\Drill;

final class EloquentBackupPosture implements BackupPosture
{
    public function forServer(string $serverId): array
    {
        return Database::query()
            ->where('server_id', $serverId)
            ->where('status', ResourceStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->map(function (Database $database) {
                $backup = Backup::query()
                    ->where('database_id', $database->id)
                    ->where('status', BackupStatus::Succeeded->value)
                    ->latest('finished_at')
                    ->first();

                $drill = Drill::query()
                    ->where('database_instance_id', $database->database_instance_id)
                    ->where('database_name', $database->name)
                    ->where('status', DrillStatus::Passed->value)
                    ->latest('finished_at')
                    ->first();

                return new DatabaseBackupPosture(
                    databaseId: $database->id,
                    name: $database->name,
                    instanceId: $database->database_instance_id,
                    lastBackupAt: $backup?->finished_at ?? $backup?->created_at,
                    encrypted: $backup ? $backup->encryption_mode !== null : null,
                    lastDrillAt: $drill?->finished_at,
                );
            })
            ->values()
            ->all();
    }
}
