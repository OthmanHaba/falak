<?php

namespace Kiln\Network\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A server converged to its desired nftables ruleset.
 */
final class FirewallApplied
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $commandId,
        public ?string $rulesetSha256,
    ) {}
}
