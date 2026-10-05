<?php

namespace Falak\Servers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Installing a database engine added to a provisioned server failed; the engine left the server's stack again.
 * Databases forgets an engine row it may have registered meanwhile, unless it holds databases.
 */
final class DatabaseEngineInstallFailed
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $engine,
    ) {}
}
