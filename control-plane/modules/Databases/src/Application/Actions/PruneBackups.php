<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Collection;

/**
 * Applies a schedule's retention (count and/or age) per database: objects are removed with a signed
 * DELETE from the control plane and the rows kept as "pruned" history. The newest successful backup
 * of each database is never pruned.
 */
final class PruneBackups
{
    public function __construct(
        private readonly ObjectStores $stores,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return int number of objects deleted
     */
    public function __invoke(BackupSchedule $schedule): int
    {
        if ($schedule->retention_count === null && $schedule->retention_days === null) {
            return 0;
        }

        /** @var Collection<string, Collection<int, Backup>> $byDatabase */
        $byDatabase = Backup::query()
            ->with('storageProvider')
            ->where('schedule_id', $schedule->id)
            ->where('status', BackupStatus::Succeeded)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('database_name');

        $cutoff = $schedule->retention_days !== null ? now()->subDays($schedule->retention_days) : null;
        $pruned = 0;

        foreach ($byDatabase as $backups) {
            foreach ($backups->values() as $index => $backup) {
                if ($index === 0) {
                    continue; // newest successful backup always survives
                }

                $overCount = $schedule->retention_count !== null && $index >= $schedule->retention_count;
                $tooOld = $cutoff !== null && $backup->created_at->lt($cutoff);

                if (($overCount || $tooOld) && $this->prune($backup)) {
                    $pruned++;
                }
            }
        }

        if ($pruned > 0) {
            $this->audit->record('databases.backups_pruned', 'backup_schedule', $schedule->id, ['count' => $pruned], $schedule->organization_id);
        }

        return $pruned;
    }

    public function prune(Backup $backup): bool
    {
        if (! $backup->storageProvider) {
            $backup->forceFill(['prune_error' => 'Storage provider was deleted.'])->save();

            return false;
        }

        try {
            $this->stores->for($backup->storageProvider)->delete($backup->object_key);
        } catch (StorageRequestFailed $e) {
            $backup->forceFill(['prune_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return false;
        }

        $backup->forceFill(['status' => BackupStatus::Pruned, 'pruned_at' => now(), 'prune_error' => null])->save();

        return true;
    }
}
