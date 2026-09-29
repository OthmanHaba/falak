<?php

namespace Kiln\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\ReleaseStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Events\SiteUpdated;
use Throwable;

/**
 * A docker site's container port only takes effect when a new container starts: redeploy the live release's source
 * so the edge never proxies to a port nothing listens on. Sites that were never deployed wait for their first deploy.
 */
final class RedeployOnContainerPortChange implements ShouldQueue
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly TriggerDeployment $trigger,
    ) {}

    public function handle(SiteUpdated $event): void
    {
        if (! $event->changed('container_port')) {
            return;
        }

        $site = $this->sites->find($event->siteId);

        if ($site === null || $site->runtime !== SiteRuntime::Docker || ! Release::query()->where('site_id', $site->id)->where('status', ReleaseStatus::Active)->exists()) {
            return;
        }

        try {
            ($this->trigger)($site, Trigger::Manual, message: "Container port changed to {$site->containerPort}");
        } catch (Throwable $e) {
            Log::warning('deployments: redeploy after a container port change failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }
}
