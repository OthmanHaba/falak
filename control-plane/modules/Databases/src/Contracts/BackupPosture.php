<?php

namespace Falak\Databases\Contracts;

use Falak\Databases\Contracts\Data\DatabaseBackupPosture;

/**
 * How well a server's databases are backed up (Security's baseline report scores it).
 */
interface BackupPosture
{
    /**
     * @return list<DatabaseBackupPosture> one per active database on the server
     */
    public function forServer(string $serverId): array;
}
