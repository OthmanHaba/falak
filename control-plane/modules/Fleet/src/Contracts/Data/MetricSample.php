<?php

namespace Falak\Fleet\Contracts\Data;

use DateTimeImmutable;

final readonly class MetricSample
{
    public function __construct(
        public DateTimeImmutable $at,
        public float $load1,
        public float $load5,
        public float $load15,
        public ?float $cpuPercent,
        public int $memoryUsedBytes,
        public int $diskUsedBytes,
        /** @var array<string, array{0: int, 1: int, 2: int}> mount => [used, available, total] bytes (agents since v0.10.0) */
        public array $disks = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->format(DATE_ATOM),
            'load1' => $this->load1,
            'load5' => $this->load5,
            'load15' => $this->load15,
            'cpu_percent' => $this->cpuPercent,
            'memory_used_bytes' => $this->memoryUsedBytes,
            'disk_used_bytes' => $this->diskUsedBytes,
        ];
    }
}
