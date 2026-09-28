<?php

namespace Kiln\Processes\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Processes\Application\OctaneRoutes;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Domain\Models\OctaneRoute;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Processes\Domain\Models\Worker;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetReady;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Sites\Events\SiteUpdated;

/**
 * Re-converges every affected server when a site changes (runtime, PHP version, paths, users, Laravel
 * toggles, targets). Unchanged state is never re-sent, so reacting to every change is cheap.
 */
final class ConvergeOnSiteChanges implements ShouldQueue
{
    public function __construct(
        private readonly ServerConverger $converger,
        private readonly OctaneRoutes $octane,
    ) {}

    public function created(SiteCreated $event): void
    {
        $this->octane->sync($event->siteId);
        $this->converger->schedule(...$event->serverIds);
    }

    public function updated(SiteUpdated $event): void
    {
        $this->octane->sync($event->siteId);
        $this->converger->schedule(...$event->serverIds);
    }

    public function targetsChanged(SiteTargetsChanged $event): void
    {
        $this->octane->sync($event->siteId);
        // Servers removed from a site drop its programs; the new leader takes over the scheduler.
        $this->converger->schedule(...$event->serverIds, ...$event->removed);
    }

    public function targetReady(SiteTargetReady $event): void
    {
        $this->octane->sync($event->siteId);
        $this->converger->schedule($event->serverId);
    }

    public function deleted(SiteDeleted $event): void
    {
        foreach ([Worker::class, Daemon::class, Schedule::class, OctaneRoute::class] as $model) {
            $model::query()->where('site_id', $event->siteId)->where('organization_id', $event->organizationId)->delete();
        }

        $this->converger->schedule(...$event->serverIds);
    }
}
