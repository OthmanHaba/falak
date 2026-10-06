<?php

namespace Falak\Volumes\Application\Jobs;

use Falak\Volumes\Application\Actions\PruneVolumeBackups;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Retention of one schedule, after each of its backups succeeded.
 */
final class PruneScheduleBackups implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 600;

    public function __construct(public string $scheduleId) {}

    public function uniqueId(): string
    {
        return $this->scheduleId;
    }

    public function handle(PruneVolumeBackups $prune): void
    {
        $schedule = BackupSchedule::query()->find($this->scheduleId);

        if ($schedule) {
            $prune($schedule);
        }
    }
}
