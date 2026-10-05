<?php

namespace Falak\Servers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The provisioning plan converged successfully; the server is active and ready for sites.
 */
final class ServerProvisioned
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $type,
        public string $name,
    ) {}
}
