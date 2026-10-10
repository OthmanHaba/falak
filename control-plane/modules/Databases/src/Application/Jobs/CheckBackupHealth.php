<?php

namespace Falak\Databases\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;
use Falak\Kernel\Support\MissedRuns;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backups that should exist but don't:
 *
 *  - databases.backup_missed (every 5 minutes): an enabled schedule of an active instance that missed its last two
 *    runs (MissedRuns: no success since the second-most-recent scheduled run, counted from its creation at first);
 *  - databases.storage_unreachable (with $probe, every 30 minutes): a storage provider that worked once (verified)
 *    refuses a probe object twice in a row. The probe writes and deletes a small object, like verification does.
 *
 * Both resolve on their own: a backup succeeds, the provider answers again.
 */
final class CheckBackupHealth implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Unique while queued or running (a slow probe is never overlapped). */
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return $this->probe ? 'probe' : 'schedules';
    }

    /** A failing provider alerts once it failed for this long (two probes 30 minutes apart). */
    public const UNREACHABLE_SECONDS = 25 * 60;

    public function __construct(public bool $probe = false) {}

    public function handle(AlertConditions $conditions, ObjectStores $stores): void
    {
        $now = CarbonImmutable::now();

        BackupSchedule::query()->with('instance:id,name,server_name,status')->where('enabled', true)->chunkById(200, function ($schedules) use ($conditions, $now) {
            foreach ($schedules as $schedule) {
                if ($schedule->instance?->status !== InstanceStatus::Active) {
                    continue;
                }

                try {
                    $this->missed($schedule, $now, $conditions);
                } catch (Throwable $e) {
                    Log::warning('Backup schedule check failed.', ['schedule_id' => $schedule->id, 'error' => $e->getMessage()]);
                }
            }
        });

        if ($this->probe) {
            StorageProvider::query()->whereNotNull('verified_at')->each(fn (StorageProvider $provider) => $this->probe($provider, $stores, $conditions));
        }
    }

    private function missed(BackupSchedule $schedule, CarbonImmutable $now, AlertConditions $conditions): void
    {
        $lastSuccess = Backup::query()->where('schedule_id', $schedule->id)->whereIn('status', [BackupStatus::Succeeded, BackupStatus::Pruned])->max('finished_at');
        $since = $lastSuccess !== null ? CarbonImmutable::parse($lastSuccess) : CarbonImmutable::instance($schedule->created_at);
        $missed = MissedRuns::twice($schedule->cron, $since, $now);
        $instance = $schedule->instance;

        $conditions->observe($schedule->organization_id, "databases.backup_missed:{$schedule->id}", $missed, fn () => new AlertData(
            $schedule->organization_id,
            'databases.backup_missed',
            Severity::Critical,
            "No backup of {$instance->name} on {$instance->server_name} since ".($lastSuccess !== null ? $since->diffForHumans($now, true) : 'the schedule was created'),
            sprintf('Schedule "%s" (%s) missed its last two runs. Check them: the server may be offline, the storage unreachable, or the backups failing.',
                $schedule->name, $schedule->cron),
            '/databases/backups',
            context: ['schedule_id' => $schedule->id, 'instance_id' => $instance->id, 'last_success_at' => $lastSuccess !== null ? $since->toIso8601String() : null],
        ), fn () => new AlertData($schedule->organization_id, 'databases.backup_missed', Severity::Info, "Backups of {$instance->name} on {$instance->server_name} run again",
            "Schedule \"{$schedule->name}\" produced a backup.", '/databases/backups'));
    }

    private function probe(StorageProvider $provider, ObjectStores $stores, AlertConditions $conditions): void
    {
        $error = null;

        try {
            $store = $stores->for($provider);
            $key = $store->key('.falak-probe-'.Str::lower((string) Str::ulid()));
            $store->put($key, 'falak storage probe '.now()->toIso8601String()."\n", 'text/plain');
            $store->delete($key);
        } catch (StorageRequestFailed $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            Log::warning('Storage provider probe failed unexpectedly.', ['provider_id' => $provider->id, 'error' => $e->getMessage()]);

            return;
        }

        $conditions->observe($provider->organization_id, "databases.storage_unreachable:{$provider->id}", $error !== null, fn () => new AlertData(
            $provider->organization_id,
            'databases.storage_unreachable',
            Severity::Critical,
            "Storage {$provider->name} is unreachable",
            'Backups, PITR shipping and restores using it fail until it answers again.',
            '/settings/storage',
            context: ['storage_provider_id' => $provider->id],
            detail: "Bucket {$provider->bucket}. Last error: {$error}",
        ), fn () => new AlertData($provider->organization_id, 'databases.storage_unreachable', Severity::Info, "Storage {$provider->name} is reachable again", '', '/settings/storage'),
            forSeconds: self::UNREACHABLE_SECONDS);
    }
}
