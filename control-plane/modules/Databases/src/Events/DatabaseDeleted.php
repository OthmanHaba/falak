<?php

namespace Falak\Databases\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A database was dropped from its server.
 */
final class DatabaseDeleted
{
    use Dispatchable;

    public function __construct(
        public string $databaseId,
        public string $organizationId,
        public string $serverId,
        public string $name,
        public ?string $siteId,
    ) {}
}
