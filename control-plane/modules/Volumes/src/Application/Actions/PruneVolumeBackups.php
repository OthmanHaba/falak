<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\VolumeBackup;

/**
 * A schedule's retention (count and/or age), like database backups: objects are removed with a signed DELETE from the
 * control plane (BackupStorage) and the rows kept as "pruned" history. The newest successful backup always survives.
 */
final class PruneVolumeBackups
{
    public function __construct(
        private readonly BackupStorage $storage,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return int objects deleted
     */
    public function __invoke(BackupSchedule $schedule): int
    {
        if ($schedule->retention_count === null && $schedule->retention_days === null) {
            return 0;
        }

        $cutoff = $schedule->retention_days !== null ? now()->subDays($schedule->retention_days) : null;
        $pruned = 0;

        $backups = VolumeBackup::query()
            ->where('schedule_id', $schedule->id)
            ->where('status', BackupStatus::Succeeded)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($backups->values() as $index => $backup) {
            if ($index === 0) {
                continue;
            }

            $overCount = $schedule->retention_count !== null && $index >= $schedule->retention_count;
            $tooOld = $cutoff !== null && $backup->created_at->lt($cutoff);

            if (($overCount || $tooOld) && $this->prune($backup)) {
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->audit->record('volumes.backups_pruned', 'volume', $schedule->volume_id, ['count' => $pruned, 'schedule_id' => $schedule->id], $schedule->organization_id);
        }

        return $pruned;
    }

    public function prune(VolumeBackup $backup): bool
    {
        if ($backup->storage_provider_id === null) {
            $backup->forceFill(['prune_error' => 'Storage provider was deleted.'])->save();

            return false;
        }

        try {
            $this->storage->delete($backup->organization_id, $backup->storage_provider_id, $backup->object_key);
        } catch (StorageUnavailable $e) {
            $backup->forceFill(['prune_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return false;
        }

        $backup->forceFill(['status' => BackupStatus::Pruned, 'pruned_at' => now(), 'prune_error' => null])->save();

        return true;
    }
}
