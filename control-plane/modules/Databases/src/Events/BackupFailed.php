<?php

namespace Falak\Databases\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A backup could not be taken or uploaded (agent failure, timeout, no agent connected).
 */
final class BackupFailed implements Alertable
{
    use Dispatchable;

    public function __construct(
        public string $backupId,
        public string $organizationId,
        public string $serverId,
        public string $serverName,
        public string $databaseName,
        public string $error,
        public ?string $scheduleId,
        public string $trigger,
    ) {}

    public const ALERT_TYPE = 'databases.backup_failed';

    /** Failures of one schedule (or of one database's manual backups) alert once until a backup succeeds. */
    public static function dedupKey(?string $scheduleId, string $serverId, string $databaseName): string
    {
        return $scheduleId !== null ? "databases.backup:schedule:{$scheduleId}" : "databases.backup:{$serverId}:{$databaseName}";
    }

    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Critical,
            "Backup of {$this->databaseName} on {$this->serverName} failed",
            $this->error,
            '/databases/backups',
            self::dedupKey($this->scheduleId, $this->serverId, $this->databaseName),
            context: ['server_id' => $this->serverId, 'database' => $this->databaseName, 'backup_id' => $this->backupId, 'trigger' => $this->trigger],
        );
    }
}
