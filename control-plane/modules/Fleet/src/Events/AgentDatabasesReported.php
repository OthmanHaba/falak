<?php

namespace Falak\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A heartbeat reported the server's database containers (`databases`: every container labelled falak.db.instance).
 * Sent with every heartbeat that carries the key, so listeners compare before they write and throttle what they send.
 */
final class AgentDatabasesReported
{
    use Dispatchable;

    /**
     * pitr: the spool of an instance with point-in-time recovery (heartbeat databases[].pitr), else null.
     *
     * @param  list<array{id: string, state: string, health: string, secrets_missing: bool, pitr: ?array<string, mixed>}>  $instances
     */
    public function __construct(
        public string $agentId,
        public string $organizationId,
        public string $serverId,
        public array $instances,
    ) {}
}
