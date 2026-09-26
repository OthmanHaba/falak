<?php

namespace Kiln\Fleet\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;

final class AgentWentOffline
{
    use Dispatchable;

    public function __construct(
        public string $agentId,
        public string $organizationId,
        public ?string $serverId,
        public ?DateTimeImmutable $lastHeartbeatAt,
    ) {}
}
