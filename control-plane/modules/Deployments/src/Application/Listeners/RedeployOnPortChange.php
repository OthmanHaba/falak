<?php

namespace Kiln\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Contracts\Exceptions\DeploymentTriggerBusy;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Events\SiteUpdated;
use Throwable;

/**
 * A docker site's ports only take effect when a new container starts: when its container port changes, or its host port
 * moves (a new server already used it), redeploy the live release's commit so no new code ships with the change and the
 * edge never proxies to a port nothing listens on. Sites that were never deployed wait for their first deploy, and a
 * queued or waiting deployment already picks the new ports up.
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

        if ($site === null || $site->runtime !== SiteRuntime::Docker) {
            return;
        }

        // The newest deployment this decision is based on: anything numbered higher was triggered meanwhile.
        $seen = (int) Deployment::query()->where('site_id', $site->id)->max('number');
        $pending = Deployment::query()->where('site_id', $site->id)->whereNotIn('status', [DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Cancelled])
            ->latest('created_at')->orderByDesc('id')->first();

        // A queued or waiting deployment has not started a container yet: it runs with the new ports (and its own commit).
        if ($pending !== null && in_array($pending->status, [DeploymentStatus::Queued, DeploymentStatus::Waiting], true)) {
            return;
        }

        // A deployment in progress may already run the old ports: follow it with the same commit (it becomes live).
        $source = $pending ?? Release::current($site->id);

        if ($source === null) {
            return;
        }

        try {
            ($this->trigger)(
                $site,
                Trigger::Manual,
                branch: $source->branch,
                commit: $source->commit,
                message: 'Ports changed: container '.$site->listenPort().', host '.$site->appPort,
                author: $source->commit_author,
                // Re-checked under the site's trigger lock: a push queued or started since the reads above wins.
                unlessNewerThan: $seen,
            );
        } catch (DeploymentTriggerBusy $e) {
            // A ValidationException too, but not a refusal: another trigger held the site's lock. Nothing was queued,
            // and the running container keeps the old ports until a deployment starts: let the queue retry.
            Log::info('deployments: redeploy after a port change waits for the site\'s trigger lock', ['site_id' => $site->id]);

            throw $e;
        } catch (ValidationException $e) {
            Log::warning('deployments: redeploy after a port change refused', ['site_id' => $site->id, 'errors' => $e->errors()]);
        } catch (Throwable $e) {
            Log::warning('deployments: redeploy after a port change failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            throw $e; // let the queue retry
        }
    }
}
