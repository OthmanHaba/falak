<?php

namespace Falak\Volumes\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A volume backup failed (volumes.backup_failed), or succeeded after one that failed (volumes.backup_succeeded, the
 * recovery). Failures of one schedule (or of one volume's manual backups) alert once until a backup succeeds.
 */
final class VolumeBackupFinished implements Alertable
{
    use Dispatchable;

    public const ALERT_FAILED = 'volumes.backup_failed';

    public const ALERT_SUCCEEDED = 'volumes.backup_succeeded';

    public function __construct(
        public bool $succeeded,
        public string $backupId,
        public string $organizationId,
        public ?string $volumeId,
        public string $volumeName,
        public ?string $scheduleId,
        public ?string $error = null,
    ) {}

    public static function dedupKey(?string $scheduleId, ?string $volumeId, string $backupId): string
    {
        return 'volumes.backup:'.($scheduleId !== null ? "schedule:{$scheduleId}" : 'volume:'.($volumeId ?? $backupId));
    }

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            $this->succeeded ? self::ALERT_SUCCEEDED : self::ALERT_FAILED,
            $this->succeeded ? Severity::Info : Severity::Critical,
            $this->succeeded ? "Backups of volume {$this->volumeName} succeed again" : "Backup of volume {$this->volumeName} failed",
            $this->succeeded ? '' : 'Its archive was not stored. Check the backup on the volume page.',
            $this->volumeId !== null ? "/volumes/{$this->volumeId}" : null,
            self::dedupKey($this->scheduleId, $this->volumeId, $this->backupId),
            resolves: $this->succeeded,
            context: array_filter(['volume_id' => $this->volumeId, 'backup_id' => $this->backupId, 'schedule_id' => $this->scheduleId]),
            // The raw error (paths, storage hosts) stays in-app.
            detail: $this->succeeded ? '' : (string) $this->error,
        );
    }
}
