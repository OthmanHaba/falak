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
use Kiln\Deployments\Events\DeploymentFailed;
use Kiln\Deployments\Events\DeploymentSucceeded;
use Kiln\Sites\Contracts\ComposeSites;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * A compose stack whose service runs as its own Kiln site needs that site live first (its services reach it by name).
 * A stack deployment that stopped for it ({@see StepPayloads::composeRelease()} records `awaits_site`) deploys the
 * site, and the site's next successful deployment deploys the stack again — once: the new stack deployment carries no
 * marker, so nothing loops. A service split out at the stack's creation thus needs no manual ordering.
 *
 * The handlers are not named `failed`: a queued listener's `failed()` is Laravel's hook for a job that failed.
 */
final class DeploySplitSitesFirst implements ShouldQueue
{
    /** Another trigger holding a site's lock is retried (DeploymentTriggerBusy). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /** A stack deployment that stopped for its site this long ago is not followed up any more. */
    private const MAX_AGE_HOURS = 24;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ComposeSites $compose,
        private readonly TriggerDeployment $trigger,
    ) {}

    /** A stack deployment stopped for its split-out site: deploy that site (unless it is already on its way). */
    public function onStackFailed(DeploymentFailed $event): void
    {
        $siteId = Deployment::query()->find($event->deploymentId)?->setting('awaits_site');

        if (! is_string($siteId) || $this->active($siteId)) {
            return;
        }

        // Re-checked under the site's trigger lock: a deployment started meanwhile wins.
        $seen = (int) Deployment::query()->where('site_id', $siteId)->max('number');
        $this->deploy($siteId, 'Deployed first: a compose stack runs one of its services as this site.', null, $seen);
    }

    /** A split-out site is live: deploy the stacks whose latest deployment recently stopped for it. */
    public function onSiteSucceeded(DeploymentSucceeded $event): void
    {
        foreach ($this->compose->stacksUsing($event->siteId) as $stackId => $service) {
            $latest = Deployment::query()->where('site_id', $stackId)->orderByDesc('number')->first();

            if ($latest === null || $latest->status !== DeploymentStatus::Failed || $latest->setting('awaits_site') !== $event->siteId
                || ($latest->finished_at ?? $latest->created_at)->lt(now()->subHours(self::MAX_AGE_HOURS))) {
                continue;
            }

            // Its commit, unless a newer deployment (a push meanwhile) was triggered: re-checked under the trigger lock.
            $this->deploy($stackId, "{$service} is live as its own site: deploying the stack.", $latest, $latest->number);
        }
    }

    private function active(string $siteId): bool
    {
        return Deployment::query()->where('site_id', $siteId)
            ->whereNotIn('status', [DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Cancelled])->exists();
    }

    private function deploy(string $siteId, string $message, ?Deployment $source, int $unlessNewerThan): void
    {
        $site = $this->sites->find($siteId);

        if ($site === null) {
            return;
        }

        try {
            ($this->trigger)($site, Trigger::Manual, branch: $source?->branch, commit: $source?->commit, message: $message,
                author: $source?->commit_author, unlessNewerThan: $unlessNewerThan);
        } catch (DeploymentTriggerBusy $e) {
            throw $e; // another trigger holds the site's lock: retried ($tries, $backoff)
        } catch (ValidationException $e) {
            Log::warning('deployments: deploying a compose stack and its split-out site in order was refused', ['site_id' => $siteId, 'errors' => $e->errors()]);
        }
    }
}
