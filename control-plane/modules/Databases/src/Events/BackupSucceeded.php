<?php

namespace Kiln\Databases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kiln\Alerting\Contracts\Alertable;
use Kiln\Alerting\Contracts\Data\AlertData;
use Kiln\Alerting\Contracts\Severity;

/**
 * A database dump was uploaded to object storage.
 */
final class BackupSucceeded implements Alertable
{
    use Dispatchable;

    public function __construct(
        public string $backupId,
        public string $organizationId,
        public string $serverId,
        public string $serverName,
        public string $databaseName,
        public int $sizeBytes,
        public string $sha256,
        public ?int $durationMs,
        public ?string $scheduleId,
        public string $trigger,
    ) {}

    public const ALERT_TYPE = 'databases.backup_recovered';

    /** Recovery of {@see BackupFailed}: only delivered after a failure alert for the same schedule/database. */
    public function toAlert(): AlertData
    {
        return new AlertData(
            $this->organizationId,
            self::ALERT_TYPE,
            Severity::Info,
            "Backups of {$this->databaseName} on {$this->serverName} succeed again",
            '',
            '/databases/backups',
            BackupFailed::dedupKey($this->scheduleId, $this->serverId, $this->databaseName),
            resolves: true,
            context: ['server_id' => $this->serverId, 'database' => $this->databaseName, 'backup_id' => $this->backupId],
        );
    }
}
