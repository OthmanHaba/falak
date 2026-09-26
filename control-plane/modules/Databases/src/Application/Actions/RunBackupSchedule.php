<?php

namespace Kiln\Databases\Application\Actions;

use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\Database;

/**
 * Backs up every database of a schedule. Scheduled runs never throw (failures become failed backups);
 * manual "run now" surfaces a missing agent as a validation error.
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
            // Manual runs skip databases that are not active instead of failing the whole request.
            ->filter(fn (Database $database) => $trigger === 'scheduled' || $database->status === ResourceStatus::Active)
            ->map(fn (Database $database) => ($this->backup)($database, $schedule->storageProvider, $schedule->compression, $trigger, $schedule->id, $actorId))
            ->values()
            ->all();
    }
}
