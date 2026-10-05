<?php

namespace Falak\Processes\Http\Controllers;

use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Http\Request;

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
