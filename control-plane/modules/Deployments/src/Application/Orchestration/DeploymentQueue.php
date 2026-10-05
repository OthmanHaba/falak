<?php

namespace Falak\Deployments\Application\Orchestration;

use Closure;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentUpdated;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\DB;

/**
 * One deployment at a time per site: later ones wait (status queued, the "stacked" view) and start
 * in order when the active one finishes.
 *
 * A deployment claimed while some of the site's servers are still being prepared is held as `waiting`
 * (it keeps its place: nothing else starts on the site) and starts once every preparing server is ready
 * ({@see resume()}, driven by Sites' target events and the minute reconciler). It fails when the leader's
 * preparation fails, when no server can be prepared, or after `deployments.waiting.timeout_minutes`.
 */
final class DeploymentQueue
{
    /** @var list<Closure(): void> */
    private array $after = [];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ServerDirectory $servers,
        private readonly DeploymentLog $log,
    ) {}

    public function startNext(string $siteId): void
    {
        $claimed = $this->transaction(function () use ($siteId) {
            // Serialize claims per site on the oldest queued row + an occupancy check.
            $busy = Deployment::query()->where('site_id', $siteId)->whereIn('status', DeploymentStatus::occupying())->lockForUpdate()->exists();

            if ($busy) {
                return null;
            }

            $next = Deployment::query()->where('site_id', $siteId)->where('status', DeploymentStatus::Queued)
                ->orderBy('number')->lockForUpdate()->first();

            if ($next === null) {
                return null;
            }

            $site = $this->sites->find($siteId);
            $readiness = $site !== null ? TargetReadiness::of($site, $this->servers) : null;

            if ($readiness?->mustWait()) {
                $reason = $readiness->waitingReason();
                $updated = Deployment::query()->whereKey($next->id)->where('status', DeploymentStatus::Queued)
                    ->update(['status' => DeploymentStatus::Waiting, 'waiting_since' => now(), 'waiting_reason' => $reason, 'updated_at' => now()]);

                if ($updated === 1) {
                    $this->log->note($next->id, "{$reason}. The deployment starts automatically once they are ready.");

                    foreach ($readiness->skipped() as $warning) {
                        $this->log->note($next->id, $warning, stream: 'stderr');
                    }

                    $this->broadcast($next, DeploymentStatus::Waiting);
                }

                return null;
            }

            $updated = Deployment::query()->whereKey($next->id)->where('status', DeploymentStatus::Queued)
                ->update(['status' => DeploymentStatus::Deploying, 'started_at' => now(), 'updated_at' => now()]);

            return $updated === 1 ? $next->id : null;
        });

        if ($claimed !== null) {
            app(Orchestrator::class)->begin($claimed);
        }
    }

    /**
     * Re-evaluate the site's waiting deployment (a server finished or failed preparing, servers changed, or the
     * reconciler's minute tick): keep waiting, start it, or fail it. Without one, start the next queued deployment.
     */
    public function resume(string $siteId): void
    {
        $started = $this->transaction(function () use ($siteId) {
            $waiting = Deployment::query()->where('site_id', $siteId)->where('status', DeploymentStatus::Waiting)->lockForUpdate()->first();

            if ($waiting === null) {
                return false;
            }

            $site = $this->sites->find($siteId);

            if ($site === null) {
                $this->failWaiting($waiting, 'The site no longer exists.');

                return null;
            }

            $readiness = TargetReadiness::of($site, $this->servers);
            $blocker = $readiness->blocker();

            if ($blocker !== null) {
                $this->failWaiting($waiting, $blocker);

                return null;
            }

            if ($readiness->mustWait()) {
                $timeout = max(1, (int) config('deployments.waiting.timeout_minutes', 30));

                if ($waiting->waiting_since !== null && $waiting->waiting_since->lte(now()->subMinutes($timeout))) {
                    $this->failWaiting($waiting, sprintf('Timed out after %d minutes waiting for the site\'s servers to finish preparing (%s). Check them in the site\'s settings, then deploy again.',
                        $timeout, implode(', ', array_map(fn ($t) => $readiness->name($t), $readiness->preparing))));

                    return null;
                }

                $reason = $readiness->waitingReason();

                if ($reason !== $waiting->waiting_reason) {
                    $waiting->forceFill(['waiting_reason' => $reason])->save();
                    $this->log->note($waiting->id, "{$reason}.");

                    foreach ($readiness->skipped() as $warning) {
                        $this->log->note($waiting->id, $warning, stream: 'stderr');
                    }

                    $this->broadcast($waiting, DeploymentStatus::Waiting);
                }

                return null;
            }

            $waiting->forceFill(['status' => DeploymentStatus::Deploying, 'started_at' => now(), 'waiting_reason' => null])->save();
            $this->log->note($waiting->id, 'The site\'s servers are ready; starting the deployment.');

            return $waiting->id;
        });

        if (is_string($started)) {
            app(Orchestrator::class)->begin($started);
        } elseif ($started === false) {
            $this->startNext($siteId);
        }
    }

    /**
     * Sites with a waiting deployment (for the reconciler).
     *
     * @return list<string>
     */
    public static function waitingSites(): array
    {
        return Deployment::query()->where('status', DeploymentStatus::Waiting)->distinct()->pluck('site_id')->map(fn ($id) => (string) $id)->all();
    }

    private function failWaiting(Deployment $deployment, string $error): void
    {
        $deployment->forceFill(['status' => DeploymentStatus::Failed, 'error' => mb_substr($error, 0, 2000), 'finished_at' => now(), 'waiting_reason' => null])->save();
        $this->log->note($deployment->id, "Deployment failed: {$error}", stream: 'stderr');
        $this->broadcast($deployment, DeploymentStatus::Failed);

        $this->after[] = function () use ($deployment) {
            DeploymentFailed::dispatch($deployment->id, $deployment->organization_id, $deployment->site_id, $deployment->site_slug, $deployment->number,
                $deployment->trigger->value, null, (string) $deployment->error, $deployment->commit, false);
            $this->startNext($deployment->site_id);
        };
    }

    private function broadcast(Deployment $deployment, DeploymentStatus $status): void
    {
        $id = $deployment->id;
        $siteId = $deployment->site_id;
        $this->after[] = fn () => DeploymentUpdated::dispatch($id, $siteId, $status->value, null);
    }

    /**
     * Run $callback in a transaction, then the side effects it queued (events, the next claim).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        $outer = $this->after;
        $this->after = [];

        try {
            $result = DB::transaction($callback);
            $effects = $this->after;
        } finally {
            $this->after = $outer;
        }

        foreach ($effects as $effect) {
            $effect();
        }

        return $result;
    }
}
