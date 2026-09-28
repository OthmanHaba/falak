<?php

namespace Kiln\Fleet\Contracts\Data;

use DateTimeImmutable;
use Kiln\Fleet\Contracts\AgentUpgradeStatus;

final readonly class AgentUpgradeData
{
    public function __construct(
        public string $id,
        public string $serverId,
        public AgentUpgradeStatus $status,
        public ?string $fromVersion,
        public string $toVersion,
        public ?string $rolloutId,
        public ?string $error,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->serverId,
            'status' => $this->status->value,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'rollout_id' => $this->rolloutId,
            'error' => $this->error,
            'requested_at' => $this->requestedAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
        ];
    }
}
