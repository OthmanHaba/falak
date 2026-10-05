<?php

namespace Falak\Projects\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;

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
