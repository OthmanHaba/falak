<?php

namespace Kiln\Servers\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Servers\Domain\Models\SshKey;

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
