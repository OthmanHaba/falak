<?php

namespace Falak\Databases\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A database now exists on its server (db.create converged).
 */
final class DatabaseCreated
{
    use Dispatchable;

    public function __construct(
        public string $databaseId,
        public string $organizationId,
        public string $serverId,
        public string $name,
        public string $engine,
        public ?string $siteId,
    ) {}
}
