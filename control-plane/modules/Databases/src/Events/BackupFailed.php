<?php

namespace Kiln\Databases\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A backup could not be taken or uploaded (agent failure, timeout, no agent connected).
 */
final class BackupFailed
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
}
