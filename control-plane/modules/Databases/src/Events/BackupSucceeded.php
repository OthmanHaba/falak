<?php

namespace Kiln\Databases\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A database dump was uploaded to object storage.
 */
final class BackupSucceeded
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
}
