<?php

namespace Falak\Processes\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Processes\Application\OctaneRoutes;
use Falak\Processes\Application\ServerConverger;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\OctaneRoute;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\Worker;
use Falak\Sites\Events\SiteCreated;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Events\SiteTargetReady;
use Falak\Sites\Events\SiteTargetsChanged;
use Falak\Sites\Events\SiteUpdated;

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
