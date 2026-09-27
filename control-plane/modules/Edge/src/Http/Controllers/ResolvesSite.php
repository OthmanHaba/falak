<?php

namespace Kiln\Edge\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;

trait ResolvesSite
{
    /**
     * The site of the current organization, 404 when it belongs elsewhere or the user may not view edge settings.
     */
    protected function site(Request $request, string $siteId, ?string $permission = null): SiteData
    {
        $site = app(SiteDirectory::class)->find($siteId);
        $access = app(OrganizationAccess::class);

        abort_if(
            $site === null
            || $site->organizationId !== app(CurrentOrganization::class)->id()
            || ! $access->can($request->user(), $site->organizationId, 'edge.view'),
            404,
        );

        if ($permission !== null) {
            $access->authorize($request->user(), $site->organizationId, $permission);
        }

        return $site;
    }

    /** A fetch() from the canvas service panel (JSON), as opposed to a browser / Inertia visit. */
    protected function wantsPanelJson(Request $request): bool
    {
        return $request->wantsJson() && $request->header('X-Inertia') === null;
    }

    /** The classic Domains / Routing pages moved into the panel's Settings → Networking section. */
    protected function toNetworking(SiteData $site): RedirectResponse
    {
        $panel = app(ProjectDirectory::class)->serviceUrl(ServiceKind::Site, $site->id, 'settings');

        return redirect($panel !== null ? "{$panel}/networking" : '/projects');
    }
}
