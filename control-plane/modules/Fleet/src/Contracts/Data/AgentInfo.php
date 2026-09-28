<?php

namespace Kiln\Fleet\Contracts\Data;

use DateTimeImmutable;
use Kiln\Fleet\Contracts\AgentStatus;

final readonly class AgentInfo
{
    /**
     * @param  array<string, mixed>  $facts  latest facts (facts.schema.json)
     * @param  array<string, mixed>  $metrics  latest heartbeat summary: load, cpu_percent, memory_used_bytes, disk_used_bytes, uptime_s, at
     * @param  list<string>  $runningCommands
     */
    public function __construct(
        public string $id,
        public string $serverId,
        public AgentStatus $status,
        public ?string $version,
        public ?string $hostname,
        public ?string $arch,
        public array $facts,
        public array $metrics,
        public DateTimeImmutable $enrolledAt,
        public ?DateTimeImmutable $lastHeartbeatAt,
        public ?DateTimeImmutable $certificateExpiresAt,
        public array $runningCommands = [],
    ) {}

    /** Whether the agent reported the protocol feature (facts `features`, e.g. "edge.access_log"). */
    public function supports(string $feature): bool
    {
        return in_array($feature, (array) ($this->facts['features'] ?? []), true);
    }

    public function isOnline(): bool
    {
        return $this->status === AgentStatus::Online;
    }
}
