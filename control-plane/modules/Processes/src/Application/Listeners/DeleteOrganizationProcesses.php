<?php

namespace Kiln\Processes\Application\Listeners;

use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Domain\Models\OctaneRoute;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Processes\Domain\Models\ServerState;
use Kiln\Processes\Domain\Models\Worker;

final class DeleteOrganizationProcesses
{
    public function handle(OrganizationDeleted $event): void
    {
        foreach ([Worker::class, Daemon::class, Schedule::class, ServerState::class, OctaneRoute::class] as $model) {
            $model::query()->where('organization_id', $event->organizationId)->delete();
        }
    }
}
