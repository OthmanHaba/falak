<?php

namespace Kiln\Databases\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Domain\Enums\BackupStatus;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Deletes a backup's object (signed DELETE) and its history row.
 */
final class DeleteBackup
{
    public function __construct(
        private readonly PruneBackups $pruner,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Backup $backup): void
    {
        if (in_array($backup->status, [BackupStatus::Pending, BackupStatus::Running], true)) {
            throw ValidationException::withMessages(['backup' => 'The backup is still running.']);
        }

        if ($backup->status === BackupStatus::Succeeded && $backup->storageProvider && ! $this->pruner->prune($backup)) {
            throw ValidationException::withMessages(['backup' => $backup->prune_error ?? 'Could not delete the object.']);
        }

        $backup->delete();

        $this->audit->record('databases.backup_deleted', 'backup', $backup->id, ['database' => $backup->database_name, 'server_id' => $backup->server_id], $backup->organization_id);
    }
}
