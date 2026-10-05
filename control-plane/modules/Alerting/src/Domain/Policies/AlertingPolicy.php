<?php

namespace Falak\Alerting\Domain\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Falak\Identity\Contracts\OrganizationAccess;

/**
 * Organization-scoped policy for channels and rules: viewing needs alerting.view, changes need
 * alerting.manage; records of other organizations are reported as not found.
 */
abstract class AlertingPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, 'alerting.view');
    }

    public function update(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, 'alerting.manage');
    }

    public function delete(Authenticatable $user, Model $model): Response
    {
        return $this->check($user, $model, 'alerting.manage');
    }

    private function check(Authenticatable $user, Model $model, string $permission): Response
    {
        $organizationId = (string) $model->getAttribute('organization_id');

        if (! $this->access->can($user, $organizationId, 'alerting.view')) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $organizationId, $permission) ? Response::allow() : Response::deny();
    }
}
