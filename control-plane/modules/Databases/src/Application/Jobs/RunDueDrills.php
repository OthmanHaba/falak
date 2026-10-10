<?php

namespace Falak\Databases\Application\Jobs;

use Falak\Databases\Application\Actions\StartDrill;
use Falak\Databases\Contracts\DrillFrequency;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Identity\Contracts\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every ten minutes: start the restore drills of enabled schedules whose next_drill_at has passed. Each schedule is
 * claimed with a compare-and-set on next_drill_at (overlapping workers never drill it twice); missed drills collapse
 * into one.
 */
final class RunDueDrills implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(StartDrill $start, CurrentOrganization $organization): void
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
                    Log::error('Scheduled restore drill failed to start.', ['schedule_id' => $schedule->id, 'exception' => $e->getMessage()]);
                }
            });
    }
}
