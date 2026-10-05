<?php

namespace Falak\Deployments\Contracts;

use Falak\Deployments\Contracts\Data\DeploymentSummary;

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

    /**
     * The most recent deployments of the given sites, newest first (the canvas Activity rail).
     *
     * @param  list<string>  $siteIds
     * @return list<DeploymentSummary>
     */
    public function recentForSites(array $siteIds, int $limit = 20): array;

    /** The commit of the release the site runs now (a function's live version hash), or null before its first deploy. */
    public function liveCommit(string $siteId): ?string;
}
