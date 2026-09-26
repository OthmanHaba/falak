<?php

namespace Kiln\Databases\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Removes a provider (objects in the bucket are left untouched). Backups stored there become
 * unrestorable from Kiln.
 */
final class DeleteStorageProvider
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(StorageProvider $provider): void
    {
        $schedules = BackupSchedule::query()->where('storage_provider_id', $provider->id)->pluck('name');

        if ($schedules->isNotEmpty()) {
            throw ValidationException::withMessages(['provider' => 'Used by backup schedules: '.$schedules->implode(', ').'.']);
        }

        Backup::query()->where('storage_provider_id', $provider->id)->update(['storage_provider_id' => null]);
        $provider->delete();

        $this->audit->record('databases.storage_provider_deleted', 'storage_provider', $provider->id, ['name' => $provider->name], $provider->organization_id);
    }
}
