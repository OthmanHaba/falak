<?php

namespace Kiln\Deployments\Http\Channels;

use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Identity\Contracts\OrganizationAccess;

/**
 * private-deployments.{deploymentId}: members of the deployment's organization with deployments.view.
 */
final class DeploymentChannel
{
    public const NAME = 'deployments.{deploymentId}';

    public function __construct(private readonly OrganizationAccess $access) {}

    public function join(Authenticatable $user, string $deploymentId): bool
    {
        $organizationId = Deployment::query()->whereKey($deploymentId)->value('organization_id');

        return is_string($organizationId) && $this->access->can($user, $organizationId, DeploymentPermissions::VIEW);
    }
}
