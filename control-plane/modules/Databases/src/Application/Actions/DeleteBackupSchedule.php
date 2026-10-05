<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Identity\Contracts\AuditLog;

/**
 * Deletes a schedule. Its backups stay (history + restorable objects).
 */
final class DeleteBackupSchedule
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(BackupSchedule $schedule): void
    {
        $schedule->delete();
        $this->audit->record('databases.backup_schedule_deleted', 'backup_schedule', $schedule->id, ['name' => $schedule->name], $schedule->organization_id);
    }
}
