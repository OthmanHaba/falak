<?php

namespace Falak\Network\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Network\Domain\Models\FirewallRule;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class FirewallRulePolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function manage(Authenticatable $user, FirewallRule $rule): Response
    {
        if (! $this->access->can($user, $rule->organization_id, 'network.view')) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $rule->organization_id, 'network.manage') ? Response::allow() : Response::deny();
    }
}
