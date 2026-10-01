<?php

namespace Kiln\Projects\Application\Listeners;

use Kiln\Databases\Events\DatabaseCreated;
use Kiln\Databases\Events\DatabaseDeleted;
use Kiln\Projects\Application\Actions\PlaceService;
use Kiln\Projects\Application\Actions\UnlinkService;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Events\ComposeServiceExtracted;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;

/**
 * Keeps services in sync with Sites and Databases. Runs synchronously so a site created from the
 * canvas is placed before the response returns (linking is a single idempotent insert).
 */
final class PlaceCreatedServices
{
    public function __construct(
        private readonly PlaceService $place,
        private readonly UnlinkService $unlink,
        private readonly SiteDirectory $sites,
        private readonly ProjectDirectory $projects,
    ) {}

    public function siteCreated(SiteCreated $event): void
    {
        $placement = $event->placement;
        $name = $placement?->name ?? $this->sites->find($event->siteId)?->name ?? $event->slug;

        ($this->place)(
            $event->organizationId,
            ServiceKind::Site,
            $event->siteId,
            $name,
            $placement?->projectId,
            $placement?->environmentId,
            $placement?->x,
            $placement?->y,
        );
    }

    public function siteDeleted(SiteDeleted $event): void
    {
        ($this->unlink)(ServiceKind::Site, $event->siteId);
    }

    /**
     * Databases created from the Databases pages land in the default project once they exist;
     * the canvas places its databases immediately (this is then a no-op).
     */
    public function databaseCreated(DatabaseCreated $event): void
    {
        ($this->place)($event->organizationId, ServiceKind::Database, $event->databaseId, $event->name);
    }

    /**
     * A compose stack's database service now runs as a Kiln database: place it next to the stack, in its environment
     * (its `${{ name.KEY }}` references resolve there). Split-out sites are placed by SiteCreated.
     */
    public function composeServiceExtracted(ComposeServiceExtracted $event): void
    {
        $stack = $this->projects->projectOf(ServiceKind::Site, $event->siteId);

        if ($event->kind !== 'database' || $stack === null) {
            return;
        }

        ($this->place)($event->organizationId, ServiceKind::Database, $event->refId, $event->name, $stack->projectId, $stack->environmentId, $stack->x + 360, $stack->y + 200);
    }

    public function databaseDeleted(DatabaseDeleted $event): void
    {
        ($this->unlink)(ServiceKind::Database, $event->databaseId);
    }
}
