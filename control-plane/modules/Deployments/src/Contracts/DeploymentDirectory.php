<?php

namespace Kiln\Deployments\Contracts;

use Kiln\Deployments\Contracts\Data\DeploymentSummary;

/**
 * Read-only deployment lookups for other modules (e.g. the Projects canvas status).
 */
interface DeploymentDirectory
{
    /**
     * The deployment that describes each site's state: the running one (building / deploying), else
     * the most recent one. Sites that were never deployed are omitted.
     *
     * @param  list<string>  $siteIds
     * @return array<string, DeploymentSummary> keyed by site id
     */
    public function currentForSites(array $siteIds): array;
}
