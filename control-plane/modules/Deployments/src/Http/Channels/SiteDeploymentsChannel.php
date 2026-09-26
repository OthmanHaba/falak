<?php

namespace Kiln\Deployments\Http\Channels;

use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * private-deployments.site.{siteId}: deployment list updates for a site.
 */
final class SiteDeploymentsChannel
{
    public const NAME = 'deployments.site.{siteId}';

    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
    ) {}

    public function join(Authenticatable $user, string $siteId): bool
    {
        $site = $this->sites->find($siteId);

        return $site !== null && $this->access->can($user, $site->organizationId, DeploymentPermissions::VIEW);
    }
}
