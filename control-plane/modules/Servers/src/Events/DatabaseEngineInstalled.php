<?php

namespace Falak\Servers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A database engine added to an already provisioned server is installed and running (the provisioning plan
 * converged with it). Databases registers it like an engine installed at creation.
 */
final class DatabaseEngineInstalled
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $engine,
    ) {}
}
