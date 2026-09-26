<?php

namespace Kiln\Deployments\Http\Controllers;

use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\DeploymentTarget;

trait PresentsDeployments
{
    /**
     * Shape of `api.Deployment` in the Go CLI (plus a few extras).
     *
     * @return array<string, mixed>
     */
    protected function deploymentResource(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'site_id' => $deployment->site_id,
            'number' => $deployment->number,
            'status' => $deployment->status->value,
            'phase' => $deployment->phase,
            'trigger' => $deployment->trigger->value,
            'strategy' => $deployment->strategy?->value,
            'branch' => $deployment->branch,
            'commit' => $deployment->commit,
            'message' => $deployment->commit_message,
            'author' => $deployment->commit_author,
            'release_id' => $deployment->release_id,
            'build_id' => $deployment->build_id,
            'rolled_back' => $deployment->rolled_back,
            'url' => $deployment->url(),
            'error' => $deployment->error,
            'created_at' => $deployment->created_at->toIso8601String(),
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Targets with their per-phase timeline.
     *
     * @return list<array<string, mixed>>
     */
    protected function targetsResource(Deployment $deployment): array
    {
        $deployment->loadMissing(['targets', 'steps']);
        $steps = $deployment->steps->groupBy('target_id');

        return $deployment->targets->map(fn (DeploymentTarget $target) => [
            'id' => $target->id,
            'server_id' => $target->server_id,
            'server_name' => $target->server_name,
            'role' => $target->role,
            'batch' => $target->batch,
            'status' => $target->status->value,
            'activated' => $target->activated,
            'error' => $target->error,
            'steps' => ($steps->get($target->id) ?? collect())->map(fn (DeploymentStep $step) => $this->stepResource($step))->values(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function stepResource(DeploymentStep $step): array
    {
        return [
            'id' => $step->id,
            'key' => $step->key,
            'kind' => $step->kind->value,
            'label' => $step->label(),
            'phase' => $step->phase,
            'rollback' => $step->rollback,
            'batch' => $step->batch,
            'status' => $step->status->value,
            'command_id' => $step->command_id,
            'build_id' => $step->build_id,
            'exit_code' => $step->exit_code,
            'error' => $step->error,
            'started_at' => $step->started_at?->toIso8601String(),
            'finished_at' => $step->finished_at?->toIso8601String(),
            'duration_ms' => $step->started_at && $step->finished_at ? (int) $step->started_at->diffInMilliseconds($step->finished_at, true) : null,
        ];
    }

    /**
     * Deployment-level steps (the build).
     *
     * @return list<array<string, mixed>>
     */
    protected function globalSteps(Deployment $deployment): array
    {
        $deployment->loadMissing('steps');

        return $deployment->steps->whereNull('target_id')->map(fn (DeploymentStep $s) => $this->stepResource($s))->values()->all();
    }
}
