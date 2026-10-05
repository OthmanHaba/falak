<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Builds\Events\BuildOutputReceived;
use Falak\Deployments\Application\Orchestration\DeploymentLog;
use Falak\Deployments\Domain\Enums\StepKind;
use Falak\Deployments\Domain\Models\DeploymentStep;

/**
 * Build log lines become deployment output (phase "build"). Synchronous: one indexed lookup.
 */
final class RecordBuildOutput
{
    public function __construct(private readonly DeploymentLog $log) {}

    public function handle(BuildOutputReceived $event): void
    {
        if ($event->deploymentId === null || $event->lines === []) {
            return;
        }

        $step = DeploymentStep::query()->where('build_id', $event->buildId)->where('kind', StepKind::Build)->first();

        if ($step === null) {
            return;
        }

        $this->log->stepOutput($step, null, $event->lines);
    }
}
