<?php

namespace Falak\Builds\Http\Channels;

use Illuminate\Contracts\Auth\Authenticatable;
use Falak\Builds\Domain\Models\Build;
use Falak\Builds\Domain\Policies\BuildPolicy;
use Falak\Identity\Contracts\OrganizationAccess;

/**
 * private-builds.{buildId}: members of the build's organization with builds.view.
 */
final class BuildChannel
{
    public const NAME = 'builds.{buildId}';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function join(Authenticatable $user, string $buildId): bool
    {
        $organizationId = Build::query()->whereKey($buildId)->value('organization_id');

        return is_string($organizationId) && $this->access->can($user, $organizationId, BuildPolicy::VIEW);
    }
}
