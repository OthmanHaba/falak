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
 * A stack deployment that stopped for it ({@see StepPayloads::composeRelease()}, setting `awaits_site`) deploys the site,
 * and the site's first successful deployment deploys the stack again — once: the new stack deployment carries no
 * marker, so nothing loops. A service split out at the stack's creation thus needs no manual ordering.
 */
final class DeploySplitSitesFirst implements ShouldQueue
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly ComposeSites $compose,
        private readonly TriggerDeployment $trigger,
    ) {}

    /** A stack deployment stopped for its split-out site: deploy that site (unless it is already on its way). */
    public function failed(DeploymentFailed $event): void
    {
        $siteId = Deployment::query()->find($event->deploymentId)?->setting('awaits_site');

        if (! is_string($siteId) || $this->active($siteId)) {
            return;
        }

        $this->deploy($siteId, 'Deployed first: a compose stack runs one of its services as this site.');
    }

    /** A split-out site is live: deploy the stacks whose last deployment stopped for it. */
    public function succeeded(DeploymentSucceeded $event): void
    {
        foreach ($this->compose->stacksUsing($event->siteId) as $stackId => $service) {
            $latest = Deployment::query()->where('site_id', $stackId)->orderByDesc('number')->first();

            if ($latest === null || $latest->status !== DeploymentStatus::Failed || $latest->setting('awaits_site') !== $event->siteId) {
                continue;
            }

            $this->deploy($stackId, "{$service} is live as its own site: deploying the stack.", $latest);
        }
    }

    private function active(string $siteId): bool
    {
        return Deployment::query()->where('site_id', $siteId)
            ->whereNotIn('status', [DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Cancelled])->exists();
    }

    private function deploy(string $siteId, string $message, ?Deployment $source = null): void
    {
        $site = $this->sites->find($siteId);

        if ($site === null) {
            return;
        }

        try {
            ($this->trigger)($site, Trigger::Manual, branch: $source?->branch, commit: $source?->commit, message: $message, author: $source?->commit_author);
        } catch (DeploymentTriggerBusy $e) {
            throw $e; // another trigger holds the site's lock: let the queue retry
        } catch (ValidationException $e) {
            Log::warning('deployments: deploying a compose stack and its split-out site in order was refused', ['site_id' => $siteId, 'errors' => $e->errors()]);
        }
    }
}
