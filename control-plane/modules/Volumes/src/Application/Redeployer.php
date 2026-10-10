<?php

namespace Falak\Volumes\Application;

use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Redeploys the sites whose mounts changed (attach, detach, a volume swapped for its restore or moved). Sites that never
 * went live are left alone: their first deploy mounts what is attached then.
 */
final class Redeployer
{
    public function __construct(
        private readonly DeploymentTrigger $trigger,
        private readonly DeploymentDirectory $deployments,
    ) {}

    /**
     * @param  list<string>  $siteIds
     * @return list<string> the sites a deployment was queued for
     */
    public function redeploy(array $siteIds, ?string $actorId, string $reason): array
    {
        $siteIds = array_values(array_unique(array_filter($siteIds)));
        $live = $siteIds !== [] ? $this->deployments->currentForSites($siteIds) : [];
        $queued = [];

        foreach ($siteIds as $siteId) {
            if (! isset($live[$siteId])) {
                continue;
            }

            try {
                $this->trigger->deploy($siteId, $actorId, message: $reason);
                $queued[] = $siteId;
            } catch (ValidationException $e) {
                Log::info('Volumes: could not redeploy a site after its volumes changed.', ['site_id' => $siteId, 'errors' => $e->errors()]);
            }
        }

        return $queued;
    }
}
