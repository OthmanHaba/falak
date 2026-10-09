<?php

namespace Falak\Databases\Application\Actions;

use Carbon\CarbonImmutable;
use Falak\Databases\Application\PitrTimeline;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\PitrGap;
use Falak\Databases\Domain\Models\PitrSegment;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;

/**
 * Point-in-time recovery retention: every point of the last pitr_window_days stays recoverable. That needs the newest
 * base finished before the window starts (or, without one, the oldest base) and every segment from that base's start
 * on; older bases and segments are deleted from storage, then their rows. Segments whose upload was never reported go
 * after a day. An instance with PITR off keeps nothing once its window has passed.
 */
final class PrunePitr
{
    public function __construct(
        private readonly PruneBackups $backups,
        private readonly ObjectStores $stores,
        private readonly PitrTimeline $timeline,
    ) {}

    /**
     * @return int objects deleted
     */
    public function __invoke(DatabaseInstance $instance): int
    {
        $cutoff = CarbonImmutable::now()->subDays(max(1, $instance->pitr_window_days));
        $bases = $this->timeline->bases($instance);
        $keep = $bases->filter(fn (Backup $base) => $base->base_finished_at <= $cutoff)->last() ?? $bases->first();
        // Segments are kept from the kept base's start (with a minute of slack for clock rounding).
        $keepFrom = $keep !== null && $instance->pitr_enabled
            ? CarbonImmutable::instance($keep->base_started_at ?? $keep->base_finished_at)->subMinute()
            : $cutoff;
        $deleted = 0;

        $old = Backup::query()->with('storageProvider')->where('database_instance_id', $instance->id)->where('type', Backup::BASE)
            ->whereIn('status', [BackupStatus::Succeeded, BackupStatus::Failed])
            ->when($keep !== null && $instance->pitr_enabled,
                fn ($query) => $query->where(fn ($q) => $q->where('base_finished_at', '<', $keep->base_finished_at)->orWhere(fn ($q) => $q->where('status', BackupStatus::Failed)->where('created_at', '<', $cutoff))),
                fn ($query) => $query->where('created_at', '<', $cutoff))
            ->get();

        foreach ($old as $base) {
            if ($base->status === BackupStatus::Failed) {
                $base->forceFill(['status' => BackupStatus::Pruned, 'pruned_at' => now()])->save();

                continue;
            }

            $deleted += $this->backups->prune($base) ? 1 : 0;
        }

        $segments = PitrSegment::query()->with('storageProvider')->where('database_instance_id', $instance->id)
            ->where(fn ($q) => $q->where('end_time', '<', $keepFrom)->orWhere(fn ($q) => $q->whereNull('shipped_at')->where('created_at', '<', now()->subDay())))
            ->orderBy('end_time')->limit(5000)->get();

        foreach ($segments as $segment) {
            if ($segment->storageProvider !== null) {
                try {
                    $this->stores->for($segment->storageProvider)->delete($segment->object_key);
                } catch (StorageRequestFailed) {
                    continue; // tried again on the next pass
                }

                $deleted++;
            }

            $segment->delete();
        }

        PitrGap::query()->where('database_instance_id', $instance->id)->where('detected_at', '<', $keepFrom)->delete();

        return $deleted;
    }
}
