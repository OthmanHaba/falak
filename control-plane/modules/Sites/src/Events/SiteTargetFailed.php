<?php

namespace Kiln\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Preparing a site on a server failed (unix user, PHP-FPM pool or JS runtime); the target stays failed until
 * it is retried. Deployments waiting for the site's servers react to it.
 */
final class SiteTargetFailed
{
    use Dispatchable;

    public function __construct(
        public string $siteId,
        public string $organizationId,
        public string $serverId,
        public string $targetId,
        public string $reason,
    ) {}
}
