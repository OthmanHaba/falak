<?php

namespace Falak\Volumes\Application\Actions;

use Cron\CronExpression;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Domain\Enums\Consistency;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a volume's backup schedule: when (cron, UTC), where (a storage provider), how (consistency) and
 * how long backups are kept (count and/or days).
 */
final class SaveBackupSchedule
{
    public function __construct(
        private readonly BackupStorage $storage,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(
        Volume $volume,
        string $storageProviderId,
        string $cron,
        ?int $retentionCount,
        ?int $retentionDays,
        Consistency $consistency,
        bool $enabled = true,
        ?BackupSchedule $schedule = null,
        ?string $actorId = null,
    ): BackupSchedule {
        if (! $volume->kind->portable()) {
            throw ValidationException::withMessages(['volume' => 'Only Docker and sized volumes can be backed up.']);
        }

        if ($volume->holdsDatabase()) {
            throw ValidationException::withMessages(['volume' => 'A database’s data volume is backed up with the database.']);
        }

        if ($this->storage->find($volume->organization_id, $storageProviderId) === null) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Choose a storage provider of this organization.']);
        }

        if (! CronExpression::isValidExpression($cron) || count(preg_split('/\s+/', trim($cron)) ?: []) !== 5) {
            throw ValidationException::withMessages(['cron' => 'Use a 5-field cron expression (UTC), e.g. "0 3 * * *".']);
        }

        $schedule ??= new BackupSchedule(['organization_id' => $volume->organization_id, 'volume_id' => $volume->id, 'created_by' => $actorId]);
        $schedule->forceFill([
            'storage_provider_id' => strtolower($storageProviderId),
            'cron' => trim($cron),
            'retention_count' => $retentionCount,
            'retention_days' => $retentionDays,
            'consistency' => $consistency,
            'enabled' => $enabled,
            'next_run_at' => $enabled ? self::nextRun($cron) : null,
        ])->save();

        $this->audit->record($schedule->wasRecentlyCreated ? 'volumes.backup_schedule_created' : 'volumes.backup_schedule_updated', 'volume', $volume->id, [
            'name' => $volume->name,
            'cron' => $schedule->cron,
            'enabled' => $enabled,
        ], $volume->organization_id);

        return $schedule;
    }

    public static function nextRun(string $cron, ?Carbon $after = null): Carbon
    {
        return Carbon::instance((new CronExpression(trim($cron)))->getNextRunDate(($after ?? now())->copy()->utc(), 0, false, 'UTC'));
    }
}
