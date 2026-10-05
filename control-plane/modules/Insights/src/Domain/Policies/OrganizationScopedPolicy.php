<?php

namespace Falak\Insights\Domain\Policies;

use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Issues, thresholds and heartbeat monitors: `insights.view` to see, `insights.manage` to change.
 * Records of other organizations are reported as not found.
 */
final class OrganizationScopedPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, 'insights.view');
    }

    public function update(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, 'insights.manage');
    }

    public function delete(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, 'insights.manage');
    }

    private function check(Authenticatable $user, Model $model, string $permission): Response
    {
        $organizationId = (string) $model->getAttribute('organization_id');

        if (! $this->access->can($user, $organizationId, 'insights.view')) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $organizationId, $permission) ? Response::allow() : Response::deny();
    }
}
