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
 * a day after they were last asked for. Turning PITR off keeps the history (the same way) until its window has passed;
 * then, like for a deleted instance (forget), all of it goes.
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
        // The base that covers the window's start (the newest finished before it), else the oldest one.
        $keep = $bases->filter(fn (Backup $base) => $base->base_finished_at <= $cutoff)->last() ?? $bases->first();

        // PITR off: the history stays until its window passed, then all of it goes.
        if (! $instance->pitr_enabled) {
            $newestSegment = PitrSegment::query()->where('database_instance_id', $instance->id)->max('end_time');
            $newestBase = $bases->last()?->base_finished_at;
            $inWindow = ($newestSegment !== null && CarbonImmutable::parse($newestSegment) >= $cutoff) || ($newestBase !== null && $newestBase >= $cutoff);

            if (! $inWindow) {
                return $this->forget($instance->id);
            }
        }

        // Segments are kept from the kept base's start (with a minute of slack for clock rounding).
        $keepFrom = $keep !== null ? CarbonImmutable::instance($keep->base_started_at ?? $keep->base_finished_at)->subMinute() : $cutoff;
        $deleted = 0;

        $old = Backup::query()->with('storageProvider')->where('database_instance_id', $instance->id)->where('type', Backup::BASE)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', BackupStatus::Succeeded)->when($keep !== null, fn ($q) => $q->where('base_finished_at', '<', $keep->base_finished_at), fn ($q) => $q->whereRaw('1 = 0')))
                ->orWhere(fn ($q) => $q->where('status', BackupStatus::Failed)->where('created_at', '<', $cutoff)))
            ->get();

        foreach ($old as $base) {
            $deleted += $this->pruneBase($base);
        }

        $segments = PitrSegment::query()->with('storageProvider')->where('database_instance_id', $instance->id)
            // A segment handed out but never reported shipped goes a day after it was last asked for.
            ->where(fn ($q) => $q->where('end_time', '<', $keepFrom)->orWhere(fn ($q) => $q->whereNull('shipped_at')->where('updated_at', '<', now()->subDay())))
            ->orderBy('end_time')->limit(5000)->get();

        $deleted += $this->deleteSegments($segments);
        PitrGap::query()->where('database_instance_id', $instance->id)->where('detected_at', '<', $keepFrom)->delete();

        return $deleted;
    }

    /**
     * All PITR history of an instance (deleted, or PITR off past its window): bases, segments and gaps, objects first.
     *
     * @return int objects deleted
     */
    public function forget(string $instanceId): int
    {
        $deleted = 0;

        foreach (Backup::query()->with('storageProvider')->where('database_instance_id', $instanceId)->where('type', Backup::BASE)
            ->whereIn('status', [BackupStatus::Succeeded, BackupStatus::Failed])->get() as $base) {
            $deleted += $this->pruneBase($base);
        }

        $deleted += $this->deleteSegments(PitrSegment::query()->with('storageProvider')->where('database_instance_id', $instanceId)->limit(5000)->get());

        if (! PitrSegment::query()->where('database_instance_id', $instanceId)->exists()) {
            PitrGap::query()->where('database_instance_id', $instanceId)->delete();
        }

        return $deleted;
    }

    private function pruneBase(Backup $base): int
    {
        if ($base->status === BackupStatus::Failed) {
            $base->forceFill(['status' => BackupStatus::Pruned, 'pruned_at' => now()])->save();

            return 0;
        }

        return $this->backups->prune($base) ? 1 : 0;
    }

    /**
     * @param  iterable<PitrSegment>  $segments
     */
    private function deleteSegments(iterable $segments): int
    {
        $deleted = 0;

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

        return $deleted;
    }
}
