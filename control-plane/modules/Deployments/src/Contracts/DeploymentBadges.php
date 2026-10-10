<?php

namespace Falak\Deployments\Contracts;

/**
 * Deployment badges for services' canvas cards: "Watching" while a release's watch window is open, "Rolled back"
 * while a site runs the release its watch rolled back to (until the next deployment).
 */
interface DeploymentBadges
{
    /**
     * @param  list<string>  $siteIds
     * @return array<string, list<string>> by site id (sites without any are left out)
     */
    public function forSites(array $siteIds): array;
}
