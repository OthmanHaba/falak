<?php

namespace Falak\Servers\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Servers\Domain\Models\Server;

final class ServerPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Server $server): Response
    {
        return $this->check($user, $server, 'servers.view');
    }

    public function update(Authenticatable $user, Server $server): Response
    {
        return $this->check($user, $server, 'servers.manage');
    }

    public function delete(Authenticatable $user, Server $server): Response
    {
        return $this->check($user, $server, 'servers.delete');
    }

    private function check(Authenticatable $user, Server $server, string $permission): Response
    {
        if (! $this->access->can($user, $server->organization_id, 'servers.view')) {
            // Do not reveal servers of other organizations.
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $server->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
