<?php

namespace Falak\Servers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nothing blocks the server any more: a machine check came back clean after a blocking one, or the server finished
 * provisioning. Resolves {@see ServerNeedsAttention}.
 */
final class ServerAttentionCleared
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $name,
    ) {}
}
