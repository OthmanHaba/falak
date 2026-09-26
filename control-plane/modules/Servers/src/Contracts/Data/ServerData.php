<?php

namespace Kiln\Servers\Contracts\Data;

use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;

final readonly class ServerData
{
    /**
     * @param  list<string>  $phpVersions  installed PHP versions
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public ServerType $type,
        public ServerStatus $status,
        public string $provider,
        public ?string $ipv4,
        public ?string $ipv6,
        public ?string $privateIpv4,
        public ?string $arch,
        public array $phpVersions,
        public ?string $defaultPhpVersion,
        public ?string $phpRuntime,
        public ?string $nodeVersion,
        public ?string $databaseEngine,
        public ?string $cacheEngine,
        public bool $docker,
        public string $unixUser,
    ) {}

    public function isActive(): bool
    {
        return $this->status === ServerStatus::Active;
    }
}
