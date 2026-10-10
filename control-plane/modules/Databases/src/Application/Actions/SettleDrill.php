<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Contracts\DrillStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\Drill;
use Falak\Databases\Events\DrillFinished;
use Falak\Identity\Contracts\AuditLog;

/**
 * Records a finished db.drill: its status, checks, duration and RTO estimate (download + restore), the backup's
 * "verified" mark, and an alert when it failed (a recovery notice when it passes again). A skipped drill (no room on
 * the server) only records why, with a hint to set a drill server.
 */
final class SettleDrill
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $result  db.drill $defs/result
     */
    public function __invoke(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $drill = Drill::query()->where('command_id', $commandId)->first();

        if ($drill === null || $drill->status->isFinished()) {
            return;
        }

        $status = $succeeded ? DrillStatus::tryFrom((string) ($result['status'] ?? '')) : DrillStatus::Failed;

        if ($status === null || $status === DrillStatus::Pending) {
            [$status, $error] = [DrillStatus::Failed, 'The agent reported no drill outcome.'];
        }

        $checks = array_values(array_map(fn (array $check) => array_filter([
            'name' => mb_substr((string) ($check['name'] ?? ''), 0, 64),
            'passed' => (bool) ($check['passed'] ?? false),
            'detail' => isset($check['detail']) ? mb_substr((string) $check['detail'], 0, 1000) : null,
        ], fn ($value) => $value !== null), array_slice(array_filter((array) ($result['checks'] ?? []), 'is_array'), 0, 20)));

        $reason = $status === DrillStatus::Skipped ? mb_substr((string) ($result['reason'] ?? ''), 0, 900) : null;

        if ($reason !== null && $drill->schedule?->drill_server_id === null) {
            $reason .= ' Choose a drill server on the schedule to run drills elsewhere.';
        }

        $download = (int) ($result['download_ms'] ?? 0);
        $restore = (int) ($result['restore_ms'] ?? 0);

        $drill->forceFill([
            'status' => $status,
            'reason' => $reason,
            'checks' => $checks !== [] ? $checks : null,
            'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            'rto_estimate_seconds' => $status === DrillStatus::Passed ? (int) ceil(($download + $restore) / 1000) : null,
            'error' => $succeeded ? ($status === DrillStatus::Failed ? self::firstFailure($checks) : null) : $error,
            'finished_at' => now(),
        ])->save();

        if ($status !== DrillStatus::Skipped && $drill->backup_id !== null) {
            Backup::query()->whereKey($drill->backup_id)->update(array_filter([
                'drill_status' => $status->value,
                'verified_at' => $status === DrillStatus::Passed ? now() : null,
            ], fn ($value) => $value !== null));
        }

        $this->audit->record('databases.drill_finished', 'backup_schedule', (string) $drill->schedule_id, [
            'drill_id' => $drill->id,
            'backup_id' => $drill->backup_id,
            'status' => $status->value,
        ], $drill->organization_id);

        self::alert($drill);
    }

    /** Failed and passed drills are alerting events (a pass only resolves an open failure). */
    public static function alert(Drill $drill): void
    {
        if ($drill->status === DrillStatus::Failed || $drill->status === DrillStatus::Passed) {
            DrillFinished::dispatch($drill->id, $drill->organization_id, $drill->schedule_id, $drill->backup_id, (string) ($drill->database_name ?? 'a database'),
                (string) $drill->server_name, $drill->status === DrillStatus::Passed, (string) ($drill->error ?? ''));
        }
    }

    /**
     * @param  list<array{name: string, passed: bool, detail?: string}>  $checks
     */
    private static function firstFailure(array $checks): string
    {
        foreach ($checks as $check) {
            if (! $check['passed']) {
                return mb_substr($check['name'].': '.($check['detail'] ?? 'failed'), 0, 1000);
            }
        }

        return 'A check failed.';
    }
}
