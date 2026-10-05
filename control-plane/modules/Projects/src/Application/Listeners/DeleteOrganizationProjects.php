<?php

namespace Falak\Projects\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

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
