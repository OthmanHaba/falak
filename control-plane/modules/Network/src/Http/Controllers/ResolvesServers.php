<?php

namespace Falak\Network\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;

trait ResolvesServers
{
    /**
     * Resolve a server through the Servers contract; 404 for unknown servers and other organizations, 403 without $permission.
     */
    protected function server(?Authenticatable $user, string $serverId, string $permission = 'network.view'): ServerData
    {
        $server = app(ServerDirectory::class)->find($serverId);
        $access = app(OrganizationAccess::class);

        abort_if($server === null || ! $access->can($user, $server->organizationId, 'network.view'), 404);
        $access->authorize($user, $server->organizationId, $permission);

        return $server;
    }
}
