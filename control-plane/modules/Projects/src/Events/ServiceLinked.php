<?php

namespace Falak\Projects\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A site / database was placed in a project environment.
 */
final class ServiceLinked
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
