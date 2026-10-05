<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Identity\Events\OrganizationDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tenant cleanup. Objects already uploaded to the organization's buckets are left in place.
 */
final class DeleteOrganizationData implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        BackupSchedule::query()->where('organization_id', $event->organizationId)->delete();
        DatabaseServer::query()->where('organization_id', $event->organizationId)->get()->each->delete();
        Backup::query()->where('organization_id', $event->organizationId)->delete();
        StorageProvider::query()->where('organization_id', $event->organizationId)->delete();
    }
}
