<?php

namespace Falak\Deployments\Contracts\Data;

/**
 * The release a site runs on one server (what `current` points at there).
 */
final readonly class LiveRelease
{
    /**
     * @param  string  $releaseId  lower-case ULID (the release directory / FALAK_RELEASE_ID is its upper-case form)
     * @param  string  $deploymentId  the deployment that built the release (FALAK_DEPLOYMENT_ID in its `.env`)
     * @param  array<string, string>  $environment  the site variables the release's `.env` was written with; empty
     *                                              for releases made before Falak recorded them
     */
    public function __construct(
        public string $siteId,
        public string $serverId,
        public string $releaseId,
        public string $deploymentId,
        public array $environment,
    ) {}
}
