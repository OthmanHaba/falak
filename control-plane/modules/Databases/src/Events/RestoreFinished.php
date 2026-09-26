<?php

namespace Kiln\Databases\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A restore from backup completed (succeeded or failed).
 */
final class RestoreFinished
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
}
