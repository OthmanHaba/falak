<?php

namespace Falak\Deployments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A release became the site's current release on all its servers.
 */
final class ReleaseActivated
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds
     */
    public function __construct(
        public string $releaseId,
        public string $organizationId,
        public string $siteId,
        public string $deploymentId,
        public ?string $commit,
        public ?string $previousReleaseId,
        public array $serverIds,
    ) {}
}
