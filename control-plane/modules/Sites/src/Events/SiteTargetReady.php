<?php

namespace Kiln\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A site finished preparing on a server (unix user and pool exist), so per-server resources
 * (processes, schedules) can be converged there.
 */
final class SiteTargetReady
{
    use Dispatchable;

    public function __construct(
        public string $siteId,
        public string $organizationId,
        public string $serverId,
        public string $targetId,
    ) {}
}
