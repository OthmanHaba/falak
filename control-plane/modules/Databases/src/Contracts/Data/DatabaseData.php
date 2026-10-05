<?php

namespace Falak\Databases\Contracts\Data;

final readonly class DatabaseData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $serverId,
        public string $name,
        /** mysql | mariadb | postgresql | redis | valkey */
        public string $engine,
        public ?string $engineVersion,
        /** The engine's port; Redis / Valkey: the instance's own port */
        public int $port,
        /** pending | active | failed | deleting */
        public string $status,
        public ?string $siteId,
        /** Redis / Valkey: the instance's memory limit (MB) */
        public ?int $maxMemoryMb = null,
    ) {}

    public function isKeyValue(): bool
    {
        return in_array($this->engine, ['redis', 'valkey'], true);
    }
}
