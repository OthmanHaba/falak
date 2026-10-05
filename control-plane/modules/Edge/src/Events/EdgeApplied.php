<?php

namespace Falak\Edge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server's edge converged to a compiled config (edge.caddy.apply finished).
 */
final class EdgeApplied
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $commandId,
        public string $payloadSha256,
        public bool $changed,
        public ?string $configSha256,
        public int $routes,
    ) {}
}
