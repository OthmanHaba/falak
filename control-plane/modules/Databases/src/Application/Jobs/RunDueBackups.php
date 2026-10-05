<?php

namespace Falak\Databases\Application\Jobs;

use Falak\Databases\Application\Actions\RunBackupSchedule;
use Falak\Databases\Application\Actions\SaveBackupSchedule;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Identity\Contracts\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute: run enabled schedules whose next_run_at has passed. Each schedule is claimed with a
 * compare-and-set on next_run_at, so overlapping workers never run it twice. Missed runs (downtime)
 * collapse into one.
 */
final class RunDueBackups implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(RunBackupSchedule $run, CurrentOrganization $organization): void
    {
        BackupSchedule::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->each(function (BackupSchedule $schedule) use ($run, $organization) {
                $claimed = BackupSchedule::query()
                    ->whereKey($schedule->id)
                    ->where('next_run_at', $schedule->next_run_at)
                    ->update(['next_run_at' => SaveBackupSchedule::nextRun($schedule->cron), 'last_run_at' => now()]);

                if ($claimed !== 1) {
                    return;
                }

                try {
                    $organization->run($schedule->organization_id, fn () => $run($schedule));
                } catch (Throwable $e) {
                    Log::error('Scheduled database backup failed to start.', ['schedule_id' => $schedule->id, 'exception' => $e->getMessage()]);
                }
            });
    }
}
