<?php

namespace Falak\Security\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Security\Application\Actions\StartAudit;
use Falak\Security\Domain\Models\Audit;
use Falak\Security\Domain\Models\FixRun;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;

/**
 * A freshly provisioned server is audited at once; deleted servers and organizations take their reports with them.
 */
final class LifecycleListener
{
    public function __construct(
        private readonly StartAudit $start,
        private readonly ServerDirectory $servers,
    ) {}

    public function provisioned(ServerProvisioned $event): void
    {
        if ($server = $this->servers->find($event->serverId)) {
            ($this->start)($server, 'provisioned');
        }
    }

    public function serverDeleted(ServerDeleted $event): void
    {
        Audit::query()->where('server_id', $event->serverId)->delete();
        FixRun::query()->where('server_id', $event->serverId)->delete();
    }

    public function organizationDeleted(OrganizationDeleted $event): void
    {
        Audit::query()->where('organization_id', $event->organizationId)->delete();
        FixRun::query()->where('organization_id', $event->organizationId)->delete();
    }
}
