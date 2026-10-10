<?php

namespace Falak\Volumes\Application\Jobs;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every 5 minutes: an enabled schedule of an active volume with no successful backup within twice its interval (the
 * gap between its next two runs), counted from its last success or its creation, alerts (volumes.backup_missed) until
 * a backup succeeds.
 */
final class CheckVolumeBackups implements ShouldQueue
{
    use Queueable;

    public function handle(AlertConditions $conditions): void
    {
        $now = CarbonImmutable::now();

        BackupSchedule::query()->with('volume:id,name,status')->where('enabled', true)->chunkById(200, function ($schedules) use ($conditions, $now) {
            foreach ($schedules as $schedule) {
                if ($schedule->volume?->status !== VolumeStatus::Active) {
                    continue;
                }

                try {
                    $this->check($schedule, $now, $conditions);
                } catch (Throwable $e) {
                    Log::warning('Volume backup schedule check failed.', ['schedule_id' => $schedule->id, 'error' => $e->getMessage()]);
                }
            }
        });
    }

    private function check(BackupSchedule $schedule, CarbonImmutable $now, AlertConditions $conditions): void
    {
        $cron = new CronExpression(trim($schedule->cron));
        $next = CarbonImmutable::instance($cron->getNextRunDate($now->utc(), 0, false, 'UTC'));
        $interval = max(60, (int) $next->diffInSeconds(CarbonImmutable::instance($cron->getNextRunDate($now->utc(), 1, false, 'UTC'))));
        $lastSuccess = VolumeBackup::query()->where('schedule_id', $schedule->id)->whereIn('status', [BackupStatus::Succeeded, BackupStatus::Pruned])->max('finished_at');
        $since = $lastSuccess !== null ? CarbonImmutable::parse($lastSuccess) : CarbonImmutable::instance($schedule->created_at);
        $volume = $schedule->volume;

        $conditions->observe($schedule->organization_id, "volumes.backup_missed:{$schedule->id}", $since->diffInSeconds($now) > 2 * $interval, fn () => new AlertData(
            $schedule->organization_id,
            'volumes.backup_missed',
            Severity::Critical,
            "No backup of volume {$volume->name} since ".($lastSuccess !== null ? $since->diffForHumans($now, true) : 'its schedule was created'),
            "Its schedule ({$schedule->cron}) should have produced one. Check the last runs: the server may be offline, the storage unreachable, or the backups failing.",
            "/volumes/{$volume->id}",
            context: ['volume_id' => $volume->id, 'schedule_id' => $schedule->id],
        ), fn () => new AlertData($schedule->organization_id, 'volumes.backup_missed', Severity::Info, "Backups of volume {$volume->name} run again", '', "/volumes/{$volume->id}"));
    }
}
