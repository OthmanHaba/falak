<?php

namespace Kiln\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Deployments\Application\Orchestration\DeploymentQueue;
use Kiln\Sites\Events\SiteTargetFailed;
use Kiln\Sites\Events\SiteTargetReady;
use Kiln\Sites\Events\SiteTargetsChanged;

/**
 * A site's servers changed preparation state: start, keep or fail its deployment waiting for them.
 */
final class ResumeWaitingDeployments implements ShouldQueue
{
    public function __construct(private readonly DeploymentQueue $queue) {}

    public function handle(SiteTargetReady|SiteTargetFailed|SiteTargetsChanged $event): void
    {
        $this->queue->resume($event->siteId);
    }
}
