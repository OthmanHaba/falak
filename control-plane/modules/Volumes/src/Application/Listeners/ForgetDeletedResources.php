<?php

namespace Falak\Volumes\Application\Listeners;

use Falak\Databases\Events\StorageProviderDeleted;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Events\SiteDeleted;
use Falak\Volumes\Application\Actions\ReleaseSite;
use Falak\Volumes\Domain\Models\BackupSchedule;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;

/**
 * What goes when what volumes live on goes: a deleted site lets go of its volumes (DeleteSite already released them
 * with the user's choice; this catches every other path), a deleted server takes its volumes' rows (backups stay), a
 * deleted storage provider stops the schedules using it, a deleted organization takes everything.
 */
final class ForgetDeletedResources
{
    public function __construct(private readonly ReleaseSite $release) {}

    public function siteDeleted(SiteDeleted $event): void
    {
        ($this->release)($event->siteId);
    }

    public function serverDeleted(ServerDeleted $event): void
    {
        Volume::query()->where('server_id', $event->serverId)->get()->each->delete();
    }

    public function storageProviderDeleted(StorageProviderDeleted $event): void
    {
        BackupSchedule::query()->where('organization_id', $event->organizationId)->where('storage_provider_id', $event->providerId)
            ->update(['storage_provider_id' => null, 'enabled' => false, 'next_run_at' => null]);
        VolumeBackup::query()->where('organization_id', $event->organizationId)->where('storage_provider_id', $event->providerId)
            ->update(['storage_provider_id' => null]);
    }

    public function organizationDeleted(OrganizationDeleted $event): void
    {
        Operation::query()->where('organization_id', $event->organizationId)->delete();
        Volume::query()->where('organization_id', $event->organizationId)->get()->each->delete();
        VolumeBackup::query()->where('organization_id', $event->organizationId)->delete();
    }
}
