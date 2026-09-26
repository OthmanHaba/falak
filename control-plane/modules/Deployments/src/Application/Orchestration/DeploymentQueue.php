<?php

namespace Kiln\Deployments\Application\Orchestration;

use Illuminate\Support\Facades\DB;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Models\Deployment;

/**
 * One deployment at a time per site: later ones wait (status queued, the "stacked" view) and start
 * in order when the active one finishes.
 */
final class DeploymentQueue
{
    public function startNext(string $siteId): void
    {
        $claimed = DB::transaction(function () use ($siteId) {
            // Serialize claims per site on the oldest queued row + an active check.
            $active = Deployment::query()->where('site_id', $siteId)->whereIn('status', DeploymentStatus::active())->lockForUpdate()->exists();

            if ($active) {
                return null;
            }

            $next = Deployment::query()->where('site_id', $siteId)->where('status', DeploymentStatus::Queued)
                ->orderBy('number')->lockForUpdate()->first();

            if ($next === null) {
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
}
