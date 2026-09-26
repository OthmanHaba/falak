<?php

namespace Kiln\Builds\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Builds\Application\Actions\InstallServerBuilder;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Builds\Domain\Models\Builder;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Servers\Events\ServerProvisioned;
use Kiln\Sites\Events\SiteDeleted;

/**
 * Builder servers become build workers when provisioned; deleted servers / organizations / sites
 * take their builders and queued builds with them.
 */
final class ManageServerBuilders implements ShouldQueue
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly InstallServerBuilder $install,
    ) {}

    public function provisioned(ServerProvisioned $event): void
    {
        if ($event->type !== ServerType::Builder->value) {
            return;
        }

        if ($server = $this->servers->find($event->serverId)) {
            ($this->install)($server);
        }
    }

    public function serverDeleted(ServerDeleted $event): void
    {
        Builder::query()->where('server_id', $event->serverId)->delete();
    }

    public function organizationDeleted(OrganizationDeleted $event): void
    {
        Builder::query()->where('organization_id', $event->organizationId)->delete();
        Build::query()->where('organization_id', $event->organizationId)->whereIn('status', BuildStatus::active())
            ->update(['status' => BuildStatus::Cancelled, 'error' => 'The organization was deleted.', 'finished_at' => now()]);
    }

    public function siteDeleted(SiteDeleted $event): void
    {
        Build::query()->where('site_id', $event->siteId)->whereIn('status', BuildStatus::active())
            ->update(['status' => BuildStatus::Cancelled, 'error' => 'The site was deleted.', 'finished_at' => now()]);
    }
}
