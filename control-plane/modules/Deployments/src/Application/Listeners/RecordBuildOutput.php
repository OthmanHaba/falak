<?php

namespace Kiln\Deployments\Application\Listeners;

use Kiln\Builds\Events\BuildOutputReceived;
use Kiln\Deployments\Application\Orchestration\DeploymentLog;
use Kiln\Deployments\Domain\Enums\StepKind;
use Kiln\Deployments\Domain\Models\DeploymentStep;

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
