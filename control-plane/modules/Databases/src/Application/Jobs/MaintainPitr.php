<?php

namespace Falak\Databases\Application\Jobs;

use Carbon\CarbonImmutable;
use Falak\Databases\Application\Actions\PrunePitr;
use Falak\Databases\Application\Actions\TakePitrBase;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Events\PitrAlert;
use Falak\Fleet\Contracts\AgentDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute, for each instance with point-in-time recovery on: takes the base backups that are due, and alerts on
 * what the heartbeat reports of its spool (pitr.lag: the oldest unshipped segment older than
 * databases.pitr.lag_alert_seconds while the instance is up; pitr.spool_full: the spool above
 * databases.pitr.spool_alert_percent of the volume), resolving them once they are fine again. With $prune (hourly), old
 * bases and segments are pruned too (PrunePitr), also for instances that turned PITR off.
 */
final class MaintainPitr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public bool $prune = false) {}

    /** A report older than this, from an online agent for a healthy instance, means shipping stopped (pitr.stopped). */
    public const STALE_REPORT_SECONDS = 600;

    public function handle(TakePitrBase $base, PrunePitr $prune, AgentDirectory $agents): void
    {
        $instances = DatabaseInstance::query()->where('pitr_enabled', true)->where('status', InstanceStatus::Active)->get();
        $online = array_map(fn ($agent) => $agent->isOnline(), $instances->isEmpty() ? [] : $agents->forServers($instances->pluck('server_id')->unique()->values()->all()));

        foreach ($instances as $instance) {
            try {
                if ($instance->pitr_next_base_at === null || $instance->pitr_next_base_at <= now()) {
                    $base($instance, 'scheduled');
                }

                $this->alerts($instance, $online[$instance->server_id] ?? false);
            } catch (Throwable $e) {
                Log::warning('PITR maintenance failed.', ['instance_id' => $instance->id, 'error' => $e->getMessage()]);
            }
        }

        if (! $this->prune) {
            return;
        }

        $all = DatabaseInstance::query()->whereIn('id', fn ($q) => $q->select('database_instance_id')->from('databases_pitr_segments'))
            ->orWhere('pitr_enabled', true)->get();

        foreach ($all as $instance) {
            try {
                $prune($instance);
            } catch (Throwable $e) {
                Log::warning('PITR pruning failed.', ['instance_id' => $instance->id, 'error' => $e->getMessage()]);
            }
        }

        // History of deleted instances whose removal did not finish (storage unreachable then).
        $orphans = collect([
            ...DB::table('databases_pitr_segments')->distinct()->pluck('database_instance_id')->all(),
            ...DB::table('databases_backups')->where('type', Backup::BASE)->whereIn('status', [BackupStatus::Succeeded->value, BackupStatus::Failed->value])
                ->distinct()->pluck('database_instance_id')->all(),
        ])->filter()->unique()->diff(DatabaseInstance::query()->pluck('id'))->values();

        foreach ($orphans as $instanceId) {
            try {
                $prune->forget((string) $instanceId);
            } catch (Throwable $e) {
                Log::warning('Forgetting a deleted instance\'s PITR history failed.', ['instance_id' => $instanceId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function alerts(DatabaseInstance $instance, bool $agentOnline): void
    {
        $report = (array) ($instance->pitr_report ?? []);
        $up = $instance->health === 'healthy';
        $reportedAt = is_string($report['at'] ?? null) ? CarbonImmutable::parse($report['at']) : null;
        // Never reported: the grace period runs from the instance's last change (PITR turned on, a health change).
        $since = $reportedAt ?? $instance->updated_at?->toImmutable();

        if ($agentOnline && $up) {
            $stopped = $since !== null && $since->diffInSeconds(now(), true) > self::STALE_REPORT_SECONDS;
            $this->toggle($instance, PitrAlert::STOPPED, $stopped, $reportedAt !== null
                ? "The server last reported the instance's spool {$reportedAt->diffForHumans(now(), true)} ago: write-ahead logs / binlogs are no longer shipped. Turn point-in-time recovery off and on again, or update the agent."
                : 'The server never reported the instance\'s spool: write-ahead logs / binlogs are not shipped. Turn point-in-time recovery off and on again, or update the agent.');
        }

        $oldest = is_string($report['oldest_pending_at'] ?? null) ? CarbonImmutable::parse($report['oldest_pending_at']) : null;
        $lagging = $up && $oldest !== null && $oldest->diffInSeconds(now(), true) > (int) config('databases.pitr.lag_alert_seconds', 300);
        $spool = (int) ($report['spool_bytes'] ?? 0);
        $volume = (int) ($report['volume_bytes'] ?? 0);
        $full = $volume > 0 && $spool * 100 > $volume * (int) config('databases.pitr.spool_alert_percent', 20);
        $error = is_string($report['error'] ?? null) && $report['error'] !== '' ? " Last error: {$report['error']}" : '';

        $this->toggle($instance, PitrAlert::LAG, $lagging, $oldest !== null
            ? "The oldest unshipped segment was spooled {$oldest->diffForHumans(now(), true)} ago ({$report['pending']} waiting).{$error}"
            : '');
        $this->toggle($instance, PitrAlert::SPOOL_FULL, $full, $volume > 0
            ? sprintf('The spool holds %d MiB, %d%% of the %d MiB volume: segments pile up while they can\'t be shipped.%s', intdiv($spool, 1024 ** 2), intdiv($spool * 100, $volume), intdiv($volume, 1024 ** 2), $error)
            : '');
    }

    /** Alerts once when a problem starts, and resolves it once it ended. */
    private function toggle(DatabaseInstance $instance, string $problem, bool $now, string $body): void
    {
        $key = "databases:pitr:{$problem}:{$instance->id}";
        $was = (bool) Cache::get($key, false);

        if ($now === $was) {
            return;
        }

        if ($now) {
            Cache::forever($key, true);
        } else {
            Cache::forget($key);
        }

        PitrAlert::dispatch($now ? $problem : PitrAlert::RECOVERED, $instance->organization_id, $instance->id, $instance->name, $instance->server_name, $body, $now ? null : $problem);
    }
}
