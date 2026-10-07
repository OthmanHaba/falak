<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Databases\Contracts\DrillStatus;
use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Falak\Volumes\Domain\Models\VolumeDrill;
use Falak\Volumes\Events\VolumeDrillFinished;

/**
 * Records a finished volume.drill: status, checks, duration, RTO estimate, the archive's "verified" mark, and an alert
 * when it failed.
 */
final class SettleVolumeDrill
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $result  volume.drill $defs/result
     */
    public function __invoke(string $commandId, bool $succeeded, ?string $error, array $result): void
    {
        $drill = VolumeDrill::query()->where('command_id', $commandId)->first();

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

        $failure = null;

        foreach ($checks as $check) {
            if (! $check['passed']) {
                $failure ??= $check['name'].': '.($check['detail'] ?? 'failed');
            }
        }

        $reason = $status === DrillStatus::Skipped ? mb_substr((string) ($result['reason'] ?? ''), 0, 900) : null;

        if ($reason !== null && $drill->schedule?->drill_server_id === null) {
            $reason .= ' Choose a drill server on the schedule to run drills elsewhere.';
        }

        $drill->forceFill([
            'status' => $status,
            'reason' => $reason,
            'checks' => $checks !== [] ? $checks : null,
            'duration_ms' => isset($result['duration_ms']) ? (int) $result['duration_ms'] : null,
            'rto_estimate_seconds' => $status === DrillStatus::Passed ? (int) ceil(((int) ($result['download_ms'] ?? 0) + (int) ($result['restore_ms'] ?? 0)) / 1000) : null,
            'error' => $succeeded ? ($status === DrillStatus::Failed ? mb_substr((string) $failure, 0, 1000) : null) : $error,
            'finished_at' => now(),
        ])->save();

        if ($status !== DrillStatus::Skipped && $drill->backup_id !== null) {
            VolumeBackup::query()->whereKey($drill->backup_id)->update(array_filter([
                'drill_status' => $status->value,
                'verified_at' => $status === DrillStatus::Passed ? now() : null,
            ], fn ($value) => $value !== null));
        }

        $this->audit->record('volumes.drill_finished', 'volume', (string) $drill->volume_id, [
            'drill_id' => $drill->id,
            'backup_id' => $drill->backup_id,
            'status' => $status->value,
        ], $drill->organization_id);

        self::alert($drill);
    }

    public static function alert(VolumeDrill $drill): void
    {
        if ($drill->status === DrillStatus::Failed || $drill->status === DrillStatus::Passed) {
            VolumeDrillFinished::dispatch($drill->id, $drill->organization_id, $drill->schedule_id, $drill->volume_id, (string) ($drill->volume_name ?? 'a volume'),
                $drill->status === DrillStatus::Passed, (string) ($drill->error ?? ''));
        }
    }
}
