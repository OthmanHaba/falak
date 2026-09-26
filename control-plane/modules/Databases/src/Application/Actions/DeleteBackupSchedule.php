<?php

namespace Kiln\Databases\Application\Actions;

use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Identity\Contracts\AuditLog;

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
