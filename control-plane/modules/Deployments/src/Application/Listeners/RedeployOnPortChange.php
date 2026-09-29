<?php

namespace Kiln\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Events\SiteUpdated;
use Throwable;

/**
 * A docker site's ports only take effect when a new container starts: when its container port changes, or its host port
 * moves (a new server already used it), redeploy the live release's commit so no new code ships with the change and the
 * edge never proxies to a port nothing listens on. Sites that were never deployed wait for their first deploy.
 */
final class RedeployOnPortChange implements ShouldQueue
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly TriggerDeployment $trigger,
    ) {}

    public function handle(SiteUpdated $event): void
    {
        if (! $event->changed('container_port', 'app_port')) {
            return;
        }

        $site = $this->sites->find($event->siteId);
        $release = $site?->runtime === SiteRuntime::Docker ? Release::current($site->id) : null;

        if ($site === null || $release === null) {
            return;
        }

        try {
            ($this->trigger)(
                $site,
                Trigger::Manual,
                branch: $release->branch,
                commit: $release->commit,
                message: 'Ports changed: container '.$site->listenPort().', host '.$site->appPort,
                author: $release->commit_author,
            );
        } catch (Throwable $e) {
            Log::warning('deployments: redeploy after a port change failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }
}
