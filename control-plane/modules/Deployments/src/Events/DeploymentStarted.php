<?php

namespace Falak\Deployments\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DeploymentStarted
{
    use Dispatchable;

    /**
     * @param  list<string>  $serverIds
     */
    public function __construct(
        public string $deploymentId,
        public string $organizationId,
        public string $siteId,
        public string $trigger,
        public string $strategy,
        public ?string $commit,
        public ?string $branch,
        public ?string $releaseId,
        public array $serverIds,
    ) {}
}
