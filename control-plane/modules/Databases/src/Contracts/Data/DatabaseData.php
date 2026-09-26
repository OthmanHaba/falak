<?php

namespace Kiln\Databases\Contracts\Data;

final readonly class DatabaseData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $serverId,
        public string $name,
        /** mysql | mariadb | postgresql */
        public string $engine,
        public ?string $engineVersion,
        public int $port,
        /** pending | active | failed | deleting */
        public string $status,
        public ?string $siteId,
    ) {}
}
