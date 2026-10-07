<?php

namespace Falak\Databases\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A restore drill finished: failed (the backup did not restore, or did not hold what it should) alerts once per
 * schedule until a drill passes again. Skipped drills alert nothing.
 */
final class DrillFinished implements Alertable
{
    use Dispatchable;

    public const ALERT_FAILED = 'databases.drill_failed';

    public const ALERT_PASSED = 'databases.drill_recovered';

    public function __construct(
        public string $drillId,
        public string $organizationId,
        public ?string $scheduleId,
        public ?string $backupId,
        public string $databaseName,
        public string $serverName,
        public bool $passed,
        public string $detail,
    ) {}

    public function toAlert(): AlertData
    {
        $dedup = "databases.drill:{$this->scheduleId}";

        return $this->passed
            ? new AlertData($this->organizationId, self::ALERT_PASSED, Severity::Info, "Restore drills of {$this->databaseName} pass again", '',
                '/databases/backups', $dedup, resolves: true, context: ['drill_id' => $this->drillId, 'backup_id' => $this->backupId])
            : new AlertData($this->organizationId, self::ALERT_FAILED, Severity::Critical, "Restore drill of {$this->databaseName} failed", $this->detail,
                '/databases/backups', $dedup, context: ['drill_id' => $this->drillId, 'backup_id' => $this->backupId, 'server' => $this->serverName]);
    }
}
