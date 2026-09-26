<?php

namespace Kiln\Sites\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Sites\Application\Actions\DeleteSite;
use Kiln\Sites\Domain\Models\Site;

final class DeleteOrganizationSites implements ShouldQueue
{
    public function __construct(private readonly DeleteSite $delete) {}

    public function handle(OrganizationDeleted $event): void
    {
        Site::query()->where('organization_id', $event->organizationId)->get()
            ->each(fn (Site $site) => ($this->delete)($site, cleanupRemote: false));
    }
}
