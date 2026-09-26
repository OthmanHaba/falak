<?php

namespace Kiln\Builds\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class BuildSucceeded
{
    use Dispatchable;

    public function __construct(
        public string $buildId,
        public string $organizationId,
        public string $siteId,
        public string $mode,
        public ?string $commit,
        public ?string $deploymentId,
        public int $durationMs,
    ) {}
}
