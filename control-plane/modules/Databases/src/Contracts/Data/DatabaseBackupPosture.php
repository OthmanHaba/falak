<?php

namespace Falak\Databases\Contracts\Data;

use Illuminate\Support\Carbon;

final readonly class DatabaseBackupPosture
{
    public function __construct(
        public string $databaseId,
        public string $name,
        public string $instanceId,
        /** The last successful backup, null when there is none */
        public ?Carbon $lastBackupAt,
        /** Whether that backup is encrypted (null without a backup) */
        public ?bool $encrypted,
        /** The last passed restore drill of the database's backups */
        public ?Carbon $lastDrillAt,
    ) {}
}
