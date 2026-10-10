<?php

namespace Falak\Fleet\Contracts\Data;

use Falak\Fleet\Contracts\AgentRequests;

/**
 * The agent an agent request ({@see AgentRequests}) comes from.
 */
final readonly class AgentCaller
{
    public function __construct(
        public string $agentId,
        public string $organizationId,
        public string $serverId,
    ) {}
}
