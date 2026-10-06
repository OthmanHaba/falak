<?php

namespace Falak\Volumes\Application\Jobs;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Volumes\Application\Actions\RunVolumeBackup;
use Falak\Volumes\Application\Actions\SaveBackupSchedule;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute: back up the volumes whose schedule is due. Each schedule is claimed with a compare-and-set on
 * next_run_at (overlapping workers never run it twice); missed runs collapse into one.
 */
final class RunDueVolumeBackups implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(RunVolumeBackup $run, CurrentOrganization $organization): void
    {
        BackupSchedule::query()
            ->with('volume')
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
                    $organization->run($schedule->organization_id, fn () => $run(
                        $schedule->volume,
                        (string) $schedule->storage_provider_id,
                        $schedule->consistency,
                        'scheduled',
                        $schedule->id,
                    ));
                } catch (Throwable $e) {
                    Log::error('Scheduled volume backup failed to start.', ['schedule_id' => $schedule->id, 'exception' => $e->getMessage()]);
                }
            });
    }
}
