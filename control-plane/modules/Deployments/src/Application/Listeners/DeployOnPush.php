<?php

namespace Kiln\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\SourceControl\Events\PushReceived;
use Throwable;

/**
 * Push-to-deploy: a verified push to a site's branch deploys every site with push-to-deploy enabled.
 */
final class DeployOnPush implements ShouldQueue
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly TriggerDeployment $trigger,
    ) {}

    public function handle(PushReceived $event): void
    {
        foreach ($this->sites->forRepository($event->connectionId, $event->repository, $event->branch) as $site) {
            if (! $site->pushToDeploy || $site->organizationId !== $event->organizationId || $site->branch !== $event->branch) {
                continue;
            }

            try {
                ($this->trigger)(
                    $site,
                    Trigger::Push,
                    branch: $event->branch,
                    commit: $event->commit->sha,
                    message: $event->commit->message,
                    author: $event->commit->authorName ?? $event->pusher,
                );
            } catch (Throwable $e) {
                Log::warning('deployments: push-to-deploy failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
