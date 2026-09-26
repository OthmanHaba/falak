<?php

namespace Kiln\Processes\Http\Controllers;

use Illuminate\Http\Request;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;

trait ResolvesSite
{
    /**
     * The site of the current organization; 404 when it belongs elsewhere or the user may not view processes.
     */
    protected function site(Request $request, string $siteId, ?string $permission = null): SiteData
    {
        $site = app(SiteDirectory::class)->find(strtolower($siteId));
        $access = app(OrganizationAccess::class);

        abort_if(
            $site === null
            || $site->organizationId !== app(CurrentOrganization::class)->id()
            || ! $access->can($request->user(), $site->organizationId, 'processes.view'),
            404,
        );

        if ($permission !== null) {
            $access->authorize($request->user(), $site->organizationId, $permission);
        }

        return $site;
    }
}
