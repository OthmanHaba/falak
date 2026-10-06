<?php

namespace Falak\Fleet\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A heartbeat reported sites whose secrets are gone from the server's tmpfs (`missing_secrets`, usually after a
 * reboot). Sent with every heartbeat until they are restored, so listeners throttle.
 */
final class AgentSecretsMissing
{
    use Dispatchable;

    /**
     * @param  list<string>  $sites  site slugs
     */
    public function __construct(
        public string $agentId,
        public string $organizationId,
        public string $serverId,
        public array $sites,
    ) {}
}
