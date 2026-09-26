<?php

namespace Kiln\Recipes\Http\Channels;

use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Recipes\Domain\Models\Run;

/**
 * private-recipes.runs.{runId}: members of the run's organization with recipes.view.
 */
final class RunChannel
{
    public const NAME = 'recipes.runs.{runId}';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function join(Authenticatable $user, string $runId): bool
    {
        $organizationId = Run::query()->whereKey($runId)->value('organization_id');

        return is_string($organizationId) && $this->access->can($user, $organizationId, 'recipes.view');
    }
}
