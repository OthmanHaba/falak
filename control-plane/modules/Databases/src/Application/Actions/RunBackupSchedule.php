<?php

namespace Kiln\Databases\Application\Actions;

use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\Database;

/**
 * Backs up every database of a schedule (scheduled runs never throw: failures become failed backups).
 */
final class RunBackupSchedule
{
    public function __construct(private readonly RunBackup $backup) {}

    /**
     * @return list<Backup>
     */
    public function __invoke(BackupSchedule $schedule, string $trigger = 'scheduled', ?string $actorId = null): array
    {
        $schedule->loadMissing(['databases.databaseServer', 'storageProvider']);

        return $schedule->databases
            ->map(fn (Database $database) => ($this->backup)($database, $schedule->storageProvider, $schedule->compression, 'scheduled', $schedule->id, $actorId))
            ->values()
            ->all();
    }
}
