<?php

namespace Kiln\Sites\Contracts;

use Kiln\Sites\Contracts\Data\EnvironmentData;
use Kiln\Sites\Contracts\Data\SharedPath;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Data\SiteTargetData;

/**
 * Read-only site lookups for other modules (Edge, Builds, Deployments, Processes, Telemetry, Insights).
 */
interface SiteDirectory
{
    public function find(string $siteId): ?SiteData;

    /**
     * @return list<SiteData>
     */
    public function forOrganization(string $organizationId): array;

    /**
     * Sites with a target on the server.
     *
     * @return list<SiteData>
     */
    public function forServer(string $serverId): array;

    /**
     * Sites deploying from a repository branch (push-to-deploy lookups).
     *
     * @return list<SiteData>
     */
    public function forRepository(string $connectionId, string $repository, ?string $branch = null): array;

    /**
     * @return list<SiteTargetData>
     */
    public function targets(string $siteId): array;

    /** The target that runs migrations / the scheduler. */
    public function leader(string $siteId): ?SiteTargetData;

    /**
     * Environment for a release: the given version, or the latest when null. Null when the site has none.
     */
    public function environment(string $siteId, ?int $version = null): ?EnvironmentData;

    /**
     * @return list<SharedPath>
     */
    public function sharedPaths(string $siteId): array;

    /**
     * Deploy script variables (KILN_*) for a deployment; merged with exposed environment variables.
     *
     * @param  array<string, string>  $context  e.g. KILN_COMMIT, KILN_RELEASE_DIR, KILN_DEPLOYMENT_ID supplied by Deployments
     * @return array<string, string>
     */
    public function deployVariables(string $siteId, string $serverId, array $context = []): array;
}
