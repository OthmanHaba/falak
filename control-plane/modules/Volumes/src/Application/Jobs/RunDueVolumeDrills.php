<?php

namespace Falak\Volumes\Application\Jobs;

use Falak\Databases\Contracts\DrillFrequency;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Volumes\Application\Actions\StartVolumeDrill;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every ten minutes: start the restore drills of enabled volume schedules whose next_drill_at has passed (claimed with
 * a compare-and-set on next_drill_at, like backups).
 */
final class RunDueVolumeDrills implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(StartVolumeDrill $start, CurrentOrganization $organization): void
    {
        BackupSchedule::query()
            ->where('enabled', true)
            ->where('drill', '!=', DrillFrequency::Off->value)
            ->whereNotNull('next_drill_at')
            ->where('next_drill_at', '<=', now())
            ->orderBy('next_drill_at')
            ->each(function (BackupSchedule $schedule) use ($start, $organization) {
                $claimed = BackupSchedule::query()
                    ->whereKey($schedule->id)
                    ->where('next_drill_at', $schedule->next_drill_at)
                    ->update(['next_drill_at' => $schedule->drill->next(), 'last_drill_at' => now()]);

                if ($claimed !== 1) {
                    return;
                }

                try {
                    $organization->run($schedule->organization_id, fn () => $start($schedule));
                } catch (Throwable $e) {
                    Log::error('Scheduled volume restore drill failed to start.', ['schedule_id' => $schedule->id, 'exception' => $e->getMessage()]);
                }
            });
    }
}
