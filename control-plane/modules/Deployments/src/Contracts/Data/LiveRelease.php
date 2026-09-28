<?php

namespace Kiln\Deployments\Contracts\Data;

/**
 * The release a site runs on one server (what `current` points at there).
 */
final readonly class LiveRelease
{
    /**
     * @param  string  $releaseId  lower-case ULID (the release directory / KILN_RELEASE_ID is its upper-case form)
     * @param  string  $deploymentId  the deployment that built the release (KILN_DEPLOYMENT_ID in its `.env`)
     * @param  array<string, string>  $environment  the site variables the release's `.env` was written with; empty
     *                                              for releases made before Kiln recorded them
     */
    public function __construct(
        public string $siteId,
        public string $serverId,
        public string $releaseId,
        public string $deploymentId,
        public array $environment,
    ) {}
}
