<?php

namespace Kiln\Projects\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class EnvironmentCreated
{
    use Dispatchable;

    /**
     * @param  ?string  $forkedFromId  environment it was duplicated from
     */
    public function __construct(
        public string $environmentId,
        public string $projectId,
        public string $organizationId,
        public string $slug,
        public bool $isProduction,
        public ?string $forkedFromId,
    ) {}
}
