<?php

namespace Kiln\Deployments\Application\Listeners;

use Kiln\Deployments\Application\Orchestration\DeploymentLog;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\StepCommand;
use Kiln\Fleet\Events\CommandOutputReceived;

/**
 * Copies agent output of deployment commands into the deployment output stream.
 *
 * Synchronous on purpose: it fires for every ingested batch of every command, and one indexed
 * lookup is cheaper than queueing a job per batch.
 */
final class RecordCommandOutput
{
    public function __construct(private readonly DeploymentLog $log) {}

    public function handle(CommandOutputReceived $event): void
    {
        $output = array_values(array_filter($event->events, fn (array $e) => ($e['kind'] ?? null) === 'output'));

        if ($output === []) {
            return;
        }

        $stepId = StepCommand::query()->whereKey($event->commandId)->value('step_id');
        $step = $stepId ? DeploymentStep::query()->with('target')->find($stepId) : null;

        if ($step === null) {
            return;
        }

        $this->log->stepOutput($step, $step->target?->server_name, array_map(fn (array $e) => [
            'seq' => (int) $e['seq'],
            'stream' => $e['stream'] ?? 'stdout',
            'data' => $e['data'] ?? null,
            'at' => $e['at'] ?? null,
        ], $output));
    }
}
