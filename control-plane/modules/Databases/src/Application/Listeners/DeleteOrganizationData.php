<?php

namespace Kiln\Databases\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Identity\Events\OrganizationDeleted;

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
