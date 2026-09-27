<?php

namespace Kiln\Projects\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A site / database left its environment (it was deleted).
 */
final class ServiceUnlinked
{
    use Dispatchable;

    public function __construct(
        public string $serviceId,
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        /** site | database */
        public string $kind,
        public string $refId,
    ) {}
}
