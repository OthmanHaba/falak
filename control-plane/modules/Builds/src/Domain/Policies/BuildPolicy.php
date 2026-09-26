<?php

namespace Kiln\Builds\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Identity\Contracts\OrganizationAccess;

final class BuildPolicy
{
    public const VIEW = 'builds.view';

    public const MANAGE = 'builds.manage';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Build $build): Response
    {
        return $this->access->can($user, $build->organization_id, self::VIEW) ? Response::allow() : Response::denyAsNotFound();
    }

    public function cancel(Authenticatable $user, Build $build): Response
    {
        if (! $this->access->can($user, $build->organization_id, self::VIEW)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $build->organization_id, self::MANAGE) ? Response::allow() : Response::deny();
    }
}
