<?php

namespace Falak\Databases\Events;

use Falak\Alerting\Contracts\Alertable;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A restore from backup completed (succeeded or failed).
 */
final class RestoreFinished implements Alertable
{
    use Dispatchable;

    public function __construct(
        public string $restoreId,
        public string $organizationId,
        public string $backupId,
        public string $serverId,
        public string $databaseName,
        public bool $succeeded,
        public ?string $error,
    ) {}

    public const ALERT_FAILED = 'databases.restore_failed';

    public const ALERT_SUCCEEDED = 'databases.restore_succeeded';

    public function toAlert(): AlertData
    {
        return $this->succeeded
            ? new AlertData($this->organizationId, self::ALERT_SUCCEEDED, Severity::Info, "Restore of {$this->databaseName} finished", '', '/databases/backups',
                context: ['server_id' => $this->serverId, 'database' => $this->databaseName, 'restore_id' => $this->restoreId])
            : new AlertData($this->organizationId, self::ALERT_FAILED, Severity::Critical, "Restore of {$this->databaseName} failed", (string) $this->error, '/databases/backups',
                "databases.restore:{$this->restoreId}", context: ['server_id' => $this->serverId, 'database' => $this->databaseName, 'restore_id' => $this->restoreId]);
    }
}
