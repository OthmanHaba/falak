<?php

namespace Falak\Deployments\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Contracts\Exceptions\DeploymentTriggerBusy;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Contracts\SiteDirectory;

/**
 * A compose stack whose services run as their own Falak sites needs those sites live (its services reach them by name),
 * and the sites may need some of the stack's services (`uses`). A stack deployment that finds such sites not live
 * records them (`awaits_sites`, {@see StepPayloads::splitSiteOrder()}) and either
 *  - stops (the stack already runs, or the sites use nothing in it): the sites deploy, then the stack; or
 *  - runs a bootstrap pass (`bootstrap`: only the services the sites use): the sites deploy, then the full stack.
 * The full stack deploys once every awaited site is live, with that deployment's commit unless a newer stack deployment
 * was triggered meanwhile, and only within MAX_AGE_HOURS; it carries no marker, so nothing loops. A site whose deploy
 * fails leaves the stack as it is (bootstrapped or stopped) until someone deploys again.
 *
 * The handlers are not named `failed`: a queued listener's `failed()` is Laravel's hook for a job that failed.
 */
final class DeploySplitSitesFirst implements ShouldQueue
{
    /** Another trigger holding a site's lock is retried (DeploymentTriggerBusy). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /** A stack deployment that waited for its sites this long ago is not followed up any more. */
    private const MAX_AGE_HOURS = 24;

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ComposeSites $compose,
        private readonly TriggerDeployment $trigger,
    ) {}

    /** A stack deployment stopped for its split-out sites: deploy them (unless already on their way). */
    public function onStackFailed(DeploymentFailed $event): void
    {
        $deployment = Deployment::query()->find($event->deploymentId);

        if ($deployment !== null) {
            $this->deployAwaited($deployment);
        }
    }

    /**
     * A bootstrap pass succeeded: deploy the sites it started services for. A split-out site went live: deploy the
     * stacks waiting for it once all their sites are.
     */
    public function onSucceeded(DeploymentSucceeded $event): void
    {
        $deployment = Deployment::query()->find($event->deploymentId);

        if ($deployment !== null && (array) $deployment->setting('bootstrap', []) !== []) {
            $this->deployAwaited($deployment);
        }

        foreach ($this->compose->stacksUsing($event->siteId) as $stackId => $service) {
            $latest = Deployment::query()->where('site_id', $stackId)->orderByDesc('number')->first();
            $awaits = $latest !== null ? self::awaits($latest) : [];

            if ($latest === null || ! in_array($event->siteId, $awaits, true)
                || ! ($latest->status === DeploymentStatus::Failed || ($latest->status === DeploymentStatus::Succeeded && (array) $latest->setting('bootstrap', []) !== []))
                || ($latest->finished_at ?? $latest->created_at)->lt(now()->subHours(self::MAX_AGE_HOURS))) {
                continue;
            }

            // Every site the stack waits for must be live (several split-out services).
            if (array_filter($awaits, fn (string $siteId) => Release::current($siteId) === null) !== []) {
                continue;
            }

            // Its commit, unless a newer deployment (a push meanwhile) was triggered: re-checked under the trigger lock.
            $this->deploy($stackId, "{$service} is live as its own site: deploying the full stack.", $latest, $latest->number);
        }
    }

    private function deployAwaited(Deployment $deployment): void
    {
        if ($deployment->finished_at !== null && $deployment->finished_at->lt(now()->subHours(self::MAX_AGE_HOURS))) {
            return;
        }

        $awaits = self::awaits($deployment);

        foreach ($awaits as $siteId) {
            // Already live (it went live after the stack checked) or on its way: nothing to deploy.
            if (Release::current($siteId) !== null || $this->active($siteId)) {
                continue;
            }

            // Re-checked under the site's trigger lock: a deployment started meanwhile wins.
            $seen = (int) Deployment::query()->where('site_id', $siteId)->max('number');
            $this->deploy($siteId, 'Deployed before its compose stack: the stack runs one of its services as this site.', null, $seen);
        }

        // Every awaited site is live already: no site success will come to follow up on, so deploy the full stack now.
        if ($awaits !== [] && array_filter($awaits, fn (string $siteId) => Release::current($siteId) === null) === []) {
            $this->deploy($deployment->site_id, 'Its split-out sites are live: deploying the full stack.', $deployment, $deployment->number);
        }
    }

    /**
     * The split-out sites a stack deployment waits for (`awaits_site` from deployments before several were tracked).
     *
     * @return list<string>
     */
    private static function awaits(Deployment $deployment): array
    {
        $single = $deployment->setting('awaits_site');

        return array_values(array_filter(array_map('strval', [...(array) $deployment->setting('awaits_sites', []), ...(is_string($single) ? [$single] : [])])));
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
            Log::warning('deployments: deploying a compose stack and its split-out sites in order was refused', ['site_id' => $siteId, 'errors' => $e->errors()]);
        }
    }
}
