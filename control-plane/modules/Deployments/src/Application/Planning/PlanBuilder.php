<?php

namespace Kiln\Deployments\Application\Planning;

use Kiln\Deployments\Domain\Enums\StepKind;
use Kiln\Deployments\Domain\Enums\StepStatus;
use Kiln\Deployments\Domain\Enums\Strategy;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\DeploymentTarget;
use Kiln\Sites\Contracts\SiteRuntime;

/**
 * Turns a deployment into its step DAG (ARCHITECTURE §5):
 *
 *   BUILD once → FETCH (all) → PREPARE (all) → MIGRATE (leader) → ACTIVATE (barrier, per batch)
 *   → RESTART procs → HEALTHCHECK → next batch
 *
 * Batches: rolling = N servers at a time, canary = the leader first then the rest, otherwise one batch.
 * In-place drops the cross-server activation barrier. Rollback deployments switch `current` back to
 * the chosen release instead of building/fetching. Failure rollback steps are added by the
 * Orchestrator when a step fails.
 */
final class PlanBuilder
{
    /** @var list<DeploymentStep> */
    private array $steps = [];

    private int $position = 0;

    /**
     * @param  list<DeploymentTarget>  $targets  leader first
     * @return list<DeploymentStep>
     */
    public function build(Deployment $deployment, SiteRuntime $runtime, array $targets, ScriptSections $sections, bool $needsBuild): array
    {
        $this->steps = [];
        $this->position = 0;
        $strategy = $deployment->strategy ?? Strategy::ZeroDowntime;
        $batches = $this->batches($targets, $strategy, (int) $deployment->setting('batch_size', 1));

        foreach ($batches as $index => $batch) {
            foreach ($batch as $target) {
                $target->forceFill(['batch' => $index])->save();
            }
        }

        if ($deployment->trigger === Trigger::Rollback) {
            $this->rollbackPlan($deployment, $runtime, $batches);

            return $this->steps;
        }

        $build = $needsBuild ? [$this->add($deployment, null, 'build', StepKind::Build, 'build', [])->key] : [];

        if ($runtime->isContainer()) {
            $this->activationBatches($deployment, $runtime, $batches, $build, fn () => $build);

            return $this->steps;
        }

        $leader = $targets[0];
        $prepared = [];
        $ready = [];

        foreach ($targets as $target) {
            $deps = $build;

            if ($sections->beforeFetch !== '') {
                $deps = [$this->hook($deployment, $target, 'before_fetch', 'fetch', $sections->beforeFetch, 'site_root', $deps)->key];
            }

            $fetch = $this->add($deployment, $target, "fetch:{$target->id}", StepKind::Fetch, 'fetch', $deps);
            $prepare = $this->add($deployment, $target, "prepare:{$target->id}", StepKind::Prepare, 'prepare', [$fetch->key]);
            $prepared[] = $prepare->key;
            $ready[$target->id] = [$prepare->key];

            if (! $target->isLeader() && $sections->beforeActivate !== '') {
                $ready[$target->id][] = $this->hook($deployment, $target, 'before_activate', 'prepare', $sections->beforeActivate, 'release', [$prepare->key])->key;
            }
        }

        // MIGRATE: the leader runs the pre-activation section once every server is prepared.
        $migrate = null;

        if ($sections->beforeActivate !== '') {
            $migrate = $this->hook($deployment, $leader, 'before_activate', 'migrate', $sections->beforeActivate, 'release', $prepared, 'migrate')->key;
            $ready[$leader->id][] = $migrate;
        }

        $barrier = array_merge(...array_values($ready));

        $this->activationBatches($deployment, $runtime, $batches, [], function (DeploymentTarget $target) use ($strategy, $barrier, $ready, $migrate) {
            if ($strategy === Strategy::InPlace) {
                return array_values(array_unique(array_filter([...$ready[$target->id], $migrate])));
            }

            return $barrier;
        }, $sections);

        return $this->steps;
    }

    /**
     * @param  list<list<DeploymentTarget>>  $batches
     * @param  list<string>  $initial
     * @param  callable(DeploymentTarget): list<string>  $depsFor
     */
    private function activationBatches(Deployment $deployment, SiteRuntime $runtime, array $batches, array $initial, callable $depsFor, ?ScriptSections $sections = null): void
    {
        $previousBatch = [];

        foreach ($batches as $batch) {
            $done = [];

            foreach ($batch as $target) {
                $deps = [...$depsFor($target), ...$previousBatch];

                if ($runtime->isContainer()) {
                    $last = $this->add($deployment, $target, "swap:{$target->id}", StepKind::Swap, 'activate', $deps)->key;
                } else {
                    $last = $this->add($deployment, $target, "activate:{$target->id}", StepKind::Activate, 'activate', $deps)->key;

                    if ($sections !== null && $sections->afterActivate !== '') {
                        $last = $this->hook($deployment, $target, 'after_activate', 'activate', $sections->afterActivate, 'current', [$last])->key;
                    }

                    if ($runtime !== SiteRuntime::Static) {
                        $last = $this->add($deployment, $target, "restart:{$target->id}", StepKind::Restart, 'restart', [$last])->key;
                    }

                    if ($sections !== null && $sections->afterRestart !== '') {
                        $last = $this->hook($deployment, $target, 'after_restart', 'restart', $sections->afterRestart, 'current', [$last])->key;
                    }
                }

                $done[] = $this->add($deployment, $target, "healthcheck:{$target->id}", StepKind::HealthCheck, 'healthcheck', [$last])->key;
            }

            $previousBatch = $done;
        }
    }

    /**
     * @param  list<list<DeploymentTarget>>  $batches
     */
    private function rollbackPlan(Deployment $deployment, SiteRuntime $runtime, array $batches): void
    {
        $previousBatch = [];

        foreach ($batches as $batch) {
            $done = [];

            foreach ($batch as $target) {
                if ($runtime->isContainer()) {
                    $last = $this->add($deployment, $target, "swap:{$target->id}", StepKind::Swap, 'activate', $previousBatch)->key;
                } else {
                    $last = $this->add($deployment, $target, "switch:{$target->id}", StepKind::Switch, 'activate', $previousBatch)->key;

                    if ($runtime !== SiteRuntime::Static) {
                        $last = $this->add($deployment, $target, "restart:{$target->id}", StepKind::Restart, 'restart', [$last])->key;
                    }
                }

                $done[] = $this->add($deployment, $target, "healthcheck:{$target->id}", StepKind::HealthCheck, 'healthcheck', [$last])->key;
            }

            $previousBatch = $done;
        }
    }

    /**
     * @param  list<DeploymentTarget>  $targets
     * @return list<list<DeploymentTarget>>
     */
    private function batches(array $targets, Strategy $strategy, int $batchSize): array
    {
        return match ($strategy) {
            Strategy::Rolling => array_chunk($targets, max(1, $batchSize)),
            Strategy::Canary => count($targets) > 1 ? [[$targets[0]], array_slice($targets, 1)] : [$targets],
            default => [$targets],
        };
    }

    /**
     * @param  list<string>  $deps
     */
    private function hook(Deployment $deployment, DeploymentTarget $target, string $name, string $phase, string $script, string $cwd, array $deps, ?string $key = null): DeploymentStep
    {
        return $this->add($deployment, $target, ($key ?? "hook.{$name}").":{$target->id}", StepKind::Hook, $phase, $deps, [
            'name' => $name,
            'script' => $script,
            'cwd' => $cwd,
        ]);
    }

    /**
     * @param  list<string>  $deps
     * @param  array<string, mixed>|null  $meta
     */
    private function add(Deployment $deployment, ?DeploymentTarget $target, string $key, StepKind $kind, string $phase, array $deps, ?array $meta = null): DeploymentStep
    {
        $step = DeploymentStep::query()->create([
            'deployment_id' => $deployment->id,
            'target_id' => $target?->id,
            'server_id' => $target?->server_id,
            'key' => $key,
            'kind' => $kind,
            'phase' => $phase,
            'rollback' => false,
            'batch' => $target?->batch ?? 0,
            'position' => $this->position++,
            'depends_on' => array_values(array_unique($deps)),
            'meta' => $meta,
            'status' => StepStatus::Pending,
            'command_type' => $kind->commandType(),
        ]);

        $this->steps[] = $step;

        return $step;
    }
}
