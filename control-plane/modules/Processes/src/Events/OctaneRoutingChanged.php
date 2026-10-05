<?php

namespace Falak\Processes\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Falak\Processes\Contracts\OctaneRouting;

/**
 * {@see OctaneRouting::listeningPort()} changed for a site on a server: Octane became reachable (the edge
 * switches to reverse_proxy) or is being switched off / reconfigured (the edge switches back to serving the
 * site directly; a draining program is stopped only after that edge config is applied).
 */
final class OctaneRoutingChanged
{
    use Dispatchable;

    public function __construct(
        public string $siteId,
        public string $serverId,
        public string $organizationId,
        public ?int $port,
    ) {}
}
