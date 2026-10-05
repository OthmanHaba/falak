<?php

namespace Falak\Servers\Application\Listeners;

use Falak\Identity\Events\OrganizationDeleted;
use Falak\Servers\Application\Actions\DeleteServer;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tenant cleanup. Machines are NOT destroyed at the provider (the organization's credentials are gone
 * with it); agents are revoked and records removed.
 */
final class DeleteOrganizationServers implements ShouldQueue
{
    public function __construct(private readonly DeleteServer $delete) {}

    public function handle(OrganizationDeleted $event): void
    {
        Server::query()->where('organization_id', $event->organizationId)->each(fn (Server $server) => ($this->delete)($server, destroyAtProvider: false));
        SshKey::query()->where('organization_id', $event->organizationId)->delete();
    }
}
