<?php

namespace Kiln\Network\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Network\Domain\Models\PrivateNetwork;

final class PrivateNetworkPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, PrivateNetwork $network): Response
    {
        return $this->check($user, $network->organization_id, 'network.view');
    }

    public function manage(Authenticatable $user, PrivateNetwork $network): Response
    {
        return $this->check($user, $network->organization_id, 'network.manage');
    }

    private function check(Authenticatable $user, string $organizationId, string $permission): Response
    {
        if (! $this->access->can($user, $organizationId, 'network.view')) {
            // Do not reveal networks of other organizations.
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $organizationId, $permission) ? Response::allow() : Response::deny();
    }
}
