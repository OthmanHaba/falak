<?php

namespace Kiln\Deployments\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kiln\Deployments\Application\Orchestration\DeploymentQueue;
use Kiln\Deployments\Application\Orchestration\Orchestrator;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\StepStatus;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;

/**
 * Every minute: resume deployments whose progress events were lost (worker crash, missed event)
 * by reconciling long-running steps against the agent / build status, and start queued
 * deployments of idle sites.
 */
final class ReconcileDeployments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(Orchestrator $orchestrator, DeploymentQueue $queue): void
    {
        $stale = now()->subSeconds((int) config('deployments.reconcile_after_seconds', 120));

        $deploymentIds = DeploymentStep::query()->where('status', StepStatus::Running)->where('updated_at', '<', $stale)
            ->distinct()->pluck('deployment_id');

        foreach ($deploymentIds as $id) {
            $orchestrator->reconcile((string) $id);
        }

        // Active deployments with nothing running (e.g. crashed between settle and dispatch).
        Deployment::query()->whereIn('status', DeploymentStatus::active())->where('updated_at', '<', $stale)->pluck('id')
            ->each(fn ($id) => $orchestrator->reconcile((string) $id));

        Deployment::query()->where('status', DeploymentStatus::Queued)->distinct()->pluck('site_id')
            ->each(fn ($siteId) => $queue->startNext((string) $siteId));
    }
}
