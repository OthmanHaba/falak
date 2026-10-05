<?php

namespace Falak\Builds\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class BuildCancelled
{
    use Dispatchable;

    public function __construct(
        public string $buildId,
        public string $organizationId,
        public string $siteId,
        public ?string $deploymentId,
    ) {}
}
