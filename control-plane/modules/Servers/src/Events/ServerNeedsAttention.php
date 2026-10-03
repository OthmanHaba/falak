<?php

namespace Kiln\Servers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The machine check before provisioning found conflicts Kiln won't resolve on its own; nothing was applied.
 */
final class ServerNeedsAttention
{
    use Dispatchable;

    /**
     * @param  list<string>  $blocks  one message per conflict
     */
    public function __construct(
        public string $serverId,
        public string $organizationId,
        public string $name,
        public array $blocks,
    ) {}
}
