<?php

namespace Falak\Sites\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Sites\Application\Actions\DeleteSite;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;

final class DeleteOrganizationSites implements ShouldQueue
{
    public function __construct(private readonly DeleteSite $delete) {}

    public function handle(OrganizationDeleted $event): void
    {
        Site::query()->where('organization_id', $event->organizationId)->get()
            ->each(fn (Site $site) => ($this->delete)($site, cleanupRemote: false));
    }
}
