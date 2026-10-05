<?php

namespace Falak\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class AgentEnrolled
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public string $agentId,
        public string $organizationId,
        public ?string $serverId,
        public array $facts,
    ) {}
}
