<?php

namespace Kiln\Projects\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Projects\Domain\Models\Environment;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Models\Service;

final class DeleteOrganizationProjects implements ShouldQueue
{
    public function handle(OrganizationDeleted $event): void
    {
        DB::transaction(function () use ($event) {
            Service::query()->where('organization_id', $event->organizationId)->delete();
            Environment::query()->where('organization_id', $event->organizationId)->delete();
            Project::query()->where('organization_id', $event->organizationId)->delete();
        });
    }
}
