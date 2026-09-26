<?php

namespace Kiln\Insights\Application;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kiln\Insights\Contracts\IssueKind;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Domain\Models\HeartbeatMonitor;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Events\HeartbeatMissed;

/**
 * Cron heartbeat tracking (cron.apply `$defs.heartbeat`): monitors are created on the first
 * heartbeat of a job; each run moves `next_expected_at` to the schedule's next slot. A job that
 * has not reported by next_expected_at + grace is "missed" and opens a heartbeat issue; failed
 * or timed-out runs open a separate failure issue. A later successful run resolves both.
 */
final class HeartbeatTracker
{
    public function __construct(private readonly IssueTracker $issues) {}

    /**
     * @param  array<string, mixed>  $heartbeat
     *
     * @throws InvalidArgumentException
     */
    public function record(string $organizationId, ?string $serverId, string $sourceId, ?string $siteId, array $heartbeat): HeartbeatMonitor
    {
        $job = is_string($heartbeat['job'] ?? null) ? trim($heartbeat['job']) : '';
        $status = $heartbeat['status'] ?? null;

        if ($job === '' || strlen($job) > 191 || ! in_array($status, ['finished', 'failed', 'skipped', 'timeout'], true)) {
            throw new InvalidArgumentException('Heartbeat needs a job and a valid status.');
        }

        $at = $this->time($heartbeat['at'] ?? null) ?? CarbonImmutable::now();
        $scheduledAt = $this->time($heartbeat['scheduled_at'] ?? null) ?? $at;
        $schedule = is_string($heartbeat['schedule'] ?? null) && $heartbeat['schedule'] !== '' ? substr($heartbeat['schedule'], 0, 191) : null;

        $monitor = $this->monitor($organizationId, $sourceId, $job, [
            'server_id' => $serverId,
            'site_id' => $siteId,
            'schedule' => $schedule,
        ]);

        $inserted = DB::table('insights_heartbeat_runs')->insertOrIgnore([
            'bucket_date' => $scheduledAt->toDateString(),
            'monitor_id' => $monitor->id,
            'status' => $status,
            'exit_code' => isset($heartbeat['exit_code']) && is_int($heartbeat['exit_code']) ? $heartbeat['exit_code'] : null,
            'duration_ms' => isset($heartbeat['duration_ms']) && is_int($heartbeat['duration_ms']) ? max(0, $heartbeat['duration_ms']) : null,
            'scheduled_at' => $scheduledAt,
            'at' => $at,
        ]);

        if ($inserted === 0 || ($monitor->last_scheduled_at !== null && $scheduledAt->lessThan($monitor->last_scheduled_at))) {
            // Duplicate or out-of-order (buffered) heartbeat: history only.
            return $monitor;
        }

        $changes = [
            'last_status' => $status,
            'last_exit_code' => $heartbeat['exit_code'] ?? null,
            'last_duration_ms' => $heartbeat['duration_ms'] ?? null,
            'last_run_at' => $at,
            'last_scheduled_at' => $scheduledAt,
            'missed_at' => null,
        ];

        if ($schedule !== null) {
            $changes['schedule'] = $schedule;
        }

        if ($siteId !== null) {
            $changes['site_id'] = $siteId;
        }

        $monitor->forceFill($changes);
        $monitor->forceFill(['next_expected_at' => $monitor->cron()?->nextAfter($scheduledAt)])->save();

        if (in_array($status, ['failed', 'timeout'], true)) {
            $this->issues->record(
                organizationId: $organizationId,
                kind: IssueKind::Heartbeat,
                fingerprint: $this->fingerprint($monitor, 'failed'),
                attributes: $this->issueAttributes($monitor, "Scheduled task {$job} ".($status === 'timeout' ? 'timed out' : 'failed'), [
                    'reason' => 'failed',
                    'status' => $status,
                    'exit_code' => $heartbeat['exit_code'] ?? null,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                ]),
                firstAt: $at,
                lastAt: $at,
            );
        } else {
            // Alive again: a run happened, so the "missed" issue recovers; a successful run also clears failures.
            $this->autoResolve($monitor, 'missed');

            if ($status === 'finished') {
                $this->autoResolve($monitor, 'failed');
            }
        }

        return $monitor;
    }

    /**
     * Find monitors whose expected run (+ grace) passed without a heartbeat.
     *
     * @return int number of monitors marked missed
     */
    public function detectMissed(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $missed = 0;

        HeartbeatMonitor::query()
            ->where('enabled', true)
            ->whereNotNull('next_expected_at')
            ->where('next_expected_at', '<=', $now)
            ->orderBy('next_expected_at')
            ->chunkById(200, function ($monitors) use ($now, &$missed) {
                foreach ($monitors as $monitor) {
                    /** @var HeartbeatMonitor $monitor */
                    $expected = CarbonImmutable::instance($monitor->next_expected_at);

                    if ($expected->addSeconds($monitor->graceSeconds())->greaterThan($now)) {
                        continue;
                    }

                    $this->markMissed($monitor, $expected, $now);
                    $missed++;
                }
            });

        return $missed;
    }

    private function markMissed(HeartbeatMonitor $monitor, CarbonImmutable $expected, CarbonImmutable $now): void
    {
        // Watch the next slot after now so a long outage yields one detection per check, not a backlog.
        $monitor->forceFill([
            'missed_at' => $monitor->missed_at ?? $now,
            'next_expected_at' => $monitor->cron()?->nextAfter($now),
        ])->save();

        [$issue, $transition] = $this->issues->record(
            organizationId: $monitor->organization_id,
            kind: IssueKind::Heartbeat,
            fingerprint: $this->fingerprint($monitor, 'missed'),
            attributes: $this->issueAttributes($monitor, "Scheduled task {$monitor->job} missed its run", [
                'reason' => 'missed',
                'expected_at' => $expected->toIso8601String(),
                'last_run_at' => $monitor->last_run_at?->toIso8601String(),
            ]),
            firstAt: $now,
            lastAt: $now,
        );

        if (in_array($transition, [IssueTracker::OPENED, IssueTracker::REGRESSED], true)) {
            HeartbeatMissed::dispatch(
                $monitor->organization_id,
                $monitor->site_id,
                $monitor->server_id,
                $monitor->id,
                $issue->id,
                $monitor->job,
                $monitor->schedule,
                $expected->toDateTimeImmutable(),
                $monitor->last_run_at?->toDateTimeImmutable(),
                $issue->url(),
            );
        }
    }

    private function autoResolve(HeartbeatMonitor $monitor, string $reason): void
    {
        $issue = Issue::query()
            ->where('organization_id', $monitor->organization_id)
            ->where('kind', IssueKind::Heartbeat)
            ->where('fingerprint', $this->fingerprint($monitor, $reason))
            ->where('status', IssueStatus::Open)
            ->first();

        if ($issue) {
            $this->issues->resolve($issue, null, automatic: true);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function issueAttributes(HeartbeatMonitor $monitor, string $title, array $meta): array
    {
        return [
            'site_id' => $monitor->site_id,
            'server_id' => $monitor->server_id,
            'title' => mb_strcut($title, 0, 500),
            'culprit' => $monitor->schedule,
            'event_type' => 'scheduled_task',
            'meta' => [...$meta, 'monitor_id' => $monitor->id, 'job' => $monitor->job, 'schedule' => $monitor->schedule],
        ];
    }

    public function fingerprint(HeartbeatMonitor $monitor, string $reason): string
    {
        return hash('sha256', "heartbeat\n{$monitor->id}\n{$reason}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function monitor(string $organizationId, string $sourceId, string $job, array $attributes): HeartbeatMonitor
    {
        $find = fn () => HeartbeatMonitor::query()->where('organization_id', $organizationId)->where('source_id', $sourceId)->where('job', $job)->first();

        if ($monitor = $find()) {
            return $monitor;
        }

        try {
            return HeartbeatMonitor::query()->create([...$attributes, 'organization_id' => $organizationId, 'source_id' => $sourceId, 'job' => $job]);
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new InvalidArgumentException('Heartbeat monitor disappeared.');
        }
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
