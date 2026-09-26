<?php

namespace Kiln\SourceControl\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\SourceControl\Domain\Models\Connection;

final class ConnectionPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Connection $connection): Response
    {
        return $this->check($user, $connection, 'source_control.view');
    }

    public function delete(Authenticatable $user, Connection $connection): Response
    {
        return $this->check($user, $connection, 'source_control.manage');
    }

    private function check(Authenticatable $user, Connection $connection, string $permission): Response
    {
        if (! $this->access->can($user, $connection->organization_id, 'source_control.view')) {
            // Do not reveal connections of other organizations.
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $connection->organization_id, $permission) ? Response::allow() : Response::deny();
    }
}
