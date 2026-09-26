<?php

namespace Kiln\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class AgentRevoked
{
    use Dispatchable;

    public function __construct(
        public string $agentId,
        public string $organizationId,
        public ?string $serverId,
        public string $reason,
    ) {}
}
