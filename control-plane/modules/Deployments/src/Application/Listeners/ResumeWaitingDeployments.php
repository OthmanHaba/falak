<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Deployments\Application\Orchestration\DeploymentQueue;
use Falak\Sites\Events\SiteTargetFailed;
use Falak\Sites\Events\SiteTargetReady;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

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
