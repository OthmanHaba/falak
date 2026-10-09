<?php

namespace Falak\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A heartbeat carried `service_events`: OOM kills and restarts of containers, slices and supervised programs since
 * the agent's previous delivered heartbeat (each event is delivered once).
 */
final class AgentServiceEventsReported
{
    use Dispatchable;

    /**
     * @param  list<array{kind: string, source: string, name: string, site: ?string, project: ?string, service: ?string, instance: ?string, count: int, at: string}>  $events
     */
    public function __construct(
        public string $agentId,
        public string $organizationId,
        public string $serverId,
        public array $events,
    ) {}
}
