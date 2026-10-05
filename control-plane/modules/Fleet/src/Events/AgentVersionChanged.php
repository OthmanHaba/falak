<?php

namespace Falak\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A heartbeat reported a different agent version than the one on record (an upgrade, or a manual reinstall).
 * State that modules deduplicate should be re-sent: the new agent may understand fields the old one did not
 * (see `facts.features`).
 */
final class AgentVersionChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $features
     */
    public function __construct(
        public string $agentId,
        public string $organizationId,
        public ?string $serverId,
        public ?string $previousVersion,
        public string $version,
        public array $features = [],
    ) {}
}
