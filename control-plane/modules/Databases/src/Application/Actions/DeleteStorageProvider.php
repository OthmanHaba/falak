<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Events\StorageProviderDeleted;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Validation\ValidationException;

/**
 * Removes a provider (objects in the bucket are left untouched). Backups stored there become
 * unrestorable from Falak. Other modules' schedules that used it stop (StorageProviderDeleted).
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
        StorageProviderDeleted::dispatch($provider->id, $provider->organization_id, $provider->name);
    }
}
