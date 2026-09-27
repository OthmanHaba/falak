<?php

namespace Kiln\Sites\Contracts\Data;

use DateTimeImmutable;

/**
 * Last reported state of a compose service's container on one server (docker.compose.ps / up results).
 */
final readonly class ComposeServiceState
{
    /**
     * @param  list<array{host_ip?: string, host_port?: int, container_port: int, protocol: string}>  $ports
     */
    public function __construct(
        public string $serverId,
        public string $service,
        public string $state,
        public ?string $health,
        public string $image,
        public ?string $imageDigest,
        public array $ports,
        public int $restarts,
        public ?float $cpuPercent,
        public ?int $memoryBytes,
        public ?string $containerName,
        public ?DateTimeImmutable $reportedAt,
    ) {}

    public function healthy(): bool
    {
        return $this->state === 'running' && ($this->health === null || $this->health === '' || $this->health === 'healthy');
    }

    public function failing(): bool
    {
        return $this->health === 'unhealthy' || in_array($this->state, ['exited', 'dead', 'restarting'], true);
    }
}
