<?php

namespace Falak\Sites\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Move a site off a server that is gone (the Recovery module's "This server is gone" wizard): the server is replaced
 * by another in its deployment group, like changing its servers in the site's settings (the new target is prepared,
 * edge routes and Falak-managed DNS records follow SiteTargetsChanged). Nothing is deployed: the caller deploys once
 * the data is back.
 */
interface SiteRelocation
{
    /**
     * @throws ValidationException when the site cannot run on $toServerId
     */
    public function replaceServer(string $siteId, string $fromServerId, string $toServerId): void;
}
