<?php

namespace Kiln\Sites\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Compose services that are no longer public: made internal in Settings → Compose, or taken out of the stack
 * (replaced by a Kiln database, split into their own site). Edge removes their domains.
 */
final class ComposeServicesUnpublished
{
    use Dispatchable;

    /**
     * @param  list<string>  $services
     */
    public function __construct(
        public string $siteId,
        public string $organizationId,
        public array $services,
    ) {}
}
