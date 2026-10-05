<?php

namespace Falak\Servers\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Servers\Domain\Models\SshKey;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class SshKeyPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function delete(Authenticatable $user, SshKey $key): Response
    {
        if (! $this->access->can($user, $key->organization_id, 'servers.view')) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $key->organization_id, 'ssh_keys.manage') ? Response::allow() : Response::deny();
    }
}
