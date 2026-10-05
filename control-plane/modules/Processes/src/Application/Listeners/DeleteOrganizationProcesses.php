<?php

namespace Falak\Processes\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\OctaneRoute;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Domain\Models\Worker;

final class DeleteOrganizationProcesses
{
    public function handle(OrganizationDeleted $event): void
    {
        foreach ([Worker::class, Daemon::class, Schedule::class, ServerState::class, OctaneRoute::class] as $model) {
            $model::query()->where('organization_id', $event->organizationId)->delete();
        }
    }
}
