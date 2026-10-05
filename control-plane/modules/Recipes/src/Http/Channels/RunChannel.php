<?php

namespace Falak\Recipes\Http\Channels;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Recipes\Domain\Models\Run;
use Illuminate\Contracts\Auth\Authenticatable;

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
