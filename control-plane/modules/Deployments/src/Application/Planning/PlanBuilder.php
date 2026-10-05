<?php

namespace Falak\Deployments\Application\Planning;

use Falak\Deployments\Domain\Enums\StepKind;
use Falak\Deployments\Domain\Enums\StepStatus;
use Falak\Deployments\Domain\Enums\Strategy;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Deployments\Domain\Models\DeploymentTarget;
use Falak\Sites\Contracts\SiteRuntime;

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

        if ($runtime === SiteRuntime::Function) {
            $this->functionPlan($deployment, $batches, 'activate', StepKind::Activate);

            return $this->steps;
        }

        $build = $needsBuild ? [$this->add($deployment, null, 'build', StepKind::Build, 'build', [])->key] : [];

        if ($runtime === SiteRuntime::Compose) {
            $this->composePlan($deployment, $strategy, $targets, $batches, $build);

            return $this->steps;
        }

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
     * Compose (docs/COMPOSE_TEMPLATES.md §1.4): FETCH (write files + pull) on every server → leader command
     * (MIGRATE, only when a service declares falak.deploy.leader_command) → ACTIVATE (`up --wait`) → HEALTHCHECK.
     * The compose strategy activates all servers behind one barrier; rolling / canary batch them.
     *
     * @param  list<DeploymentTarget>  $targets  leader first
     * @param  list<list<DeploymentTarget>>  $batches
     * @param  list<string>  $build
     */
    private function composePlan(Deployment $deployment, Strategy $strategy, array $targets, array $batches, array $build): void
    {
        $fetched = [];

        foreach ($targets as $target) {
            $fetched[$target->id] = $this->add($deployment, $target, "fetch:{$target->id}", StepKind::Fetch, 'fetch', $build, null, 'docker.compose.pull')->key;
        }

        $migrate = $this->add($deployment, $targets[0], "migrate:{$targets[0]->id}", StepKind::Hook, 'migrate', array_values($fetched), ['name' => 'leader_command', 'compose' => true], 'system.exec')->key;
        $barrier = [...array_values($fetched), $migrate];
        $previousBatch = [];

        foreach ($batches as $batch) {
            $done = [];

            foreach ($batch as $target) {
                $deps = $strategy === Strategy::Compose ? $barrier : [$fetched[$target->id], $migrate];
                $up = $this->add($deployment, $target, "activate:{$target->id}", StepKind::Activate, 'activate', [...$deps, ...$previousBatch], null, 'docker.compose.up')->key;
                $done[] = $this->add($deployment, $target, "healthcheck:{$target->id}", StepKind::HealthCheck, 'healthcheck', [$up])->key;
            }

            $previousBatch = $done;
        }
    }

    /**
     * Functions: one `fn.release.apply` per server, batch after batch. The agent installs the release's dependencies,
     * boots it and only then switches the gateway to it, so there is no separate health check (probing a function
     * would also keep it from scaling to zero).
     *
     * @param  list<list<DeploymentTarget>>  $batches
     */
    private function functionPlan(Deployment $deployment, array $batches, string $key, StepKind $kind): void
    {
        $previousBatch = [];

        foreach ($batches as $batch) {
            $done = [];

            foreach ($batch as $target) {
                $done[] = $this->add($deployment, $target, "{$key}:{$target->id}", $kind, 'activate', $previousBatch, null, 'fn.release.apply')->key;
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

        if ($runtime === SiteRuntime::Function) {
            $this->functionPlan($deployment, $batches, 'switch', StepKind::Switch);

            return;
        }

        foreach ($batches as $batch) {
            $done = [];

            foreach ($batch as $target) {
                if ($runtime === SiteRuntime::Compose) {
                    $last = $this->add($deployment, $target, "switch:{$target->id}", StepKind::Switch, 'activate', $previousBatch, null, 'docker.compose.up')->key;
                } elseif ($runtime->isContainer()) {
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
    private function add(Deployment $deployment, ?DeploymentTarget $target, string $key, StepKind $kind, string $phase, array $deps, ?array $meta = null, ?string $commandType = null): DeploymentStep
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
            'command_type' => $commandType ?? $kind->commandType(),
        ]);

        $this->steps[] = $step;

        return $step;
    }
}
