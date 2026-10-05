<?php

namespace Falak\Recipes\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Recipes\Domain\Models\Run;

final class RunPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Run $run): Response
    {
        return $this->access->can($user, $run->organization_id, 'recipes.view') ? Response::allow() : Response::denyAsNotFound();
    }
}
