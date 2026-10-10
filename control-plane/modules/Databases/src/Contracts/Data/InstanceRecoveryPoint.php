<?php

namespace Falak\Databases\Contracts\Data;

/**
 * A database container on a server and its databases' recovery points (DatabaseRecovery::instancesOn).
 */
final readonly class InstanceRecoveryPoint
{
    /**
     * @param  list<DatabaseRecoveryPoint>  $databases
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $serverId,
        public string $name,
        public string $engine,
        public string $version,
        public ?string $environmentId,
        public bool $pitrEnabled,
        public array $databases,
    ) {}
}
