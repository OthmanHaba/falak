<?php

namespace Kiln\Deployments\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * Sites (by id or slug) and deployments of the current organization; anything else is a 404.
 */
trait ResolvesSites
{
    protected function site(?Authenticatable $user, string $idOrSlug, string $permission = DeploymentPermissions::VIEW): SiteData
    {
        $organizationId = app(CurrentOrganization::class)->requireId();
        $access = app(OrganizationAccess::class);
        $access->authorize($user, $organizationId, DeploymentPermissions::VIEW);

        $directory = app(SiteDirectory::class);
        $site = $directory->find(strtolower($idOrSlug));

        if ($site === null || $site->organizationId !== $organizationId) {
            $site = null;

            foreach ($directory->forOrganization($organizationId) as $candidate) {
                if ($candidate->slug === strtolower($idOrSlug)) {
                    $site = $candidate;
                    break;
                }
            }
        }

        abort_if($site === null, 404, 'Site not found.');
        $access->authorize($user, $organizationId, $permission);

        return $site;
    }

    protected function deployment(?Authenticatable $user, string $id, ?string $siteId = null): Deployment
    {
        $organizationId = app(CurrentOrganization::class)->requireId();
        app(OrganizationAccess::class)->authorize($user, $organizationId, DeploymentPermissions::VIEW);

        return Deployment::query()->where('organization_id', $organizationId)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->findOrFail(strtolower($id));
    }

    protected function can(?Authenticatable $user, SiteData $site, string $permission): bool
    {
        return app(OrganizationAccess::class)->can($user, $site->organizationId, $permission);
    }
}
