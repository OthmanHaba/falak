<?php

namespace Falak\Sites\Contracts;

/**
 * Deploy-related site settings that Sites owns but the Deployments settings page edits.
 */
interface SiteDeploySettings
{
    /**
     * Enable or disable push-to-deploy (registers / removes the repository webhook as needed).
     *
     * @return list<string> warnings (e.g. the webhook must be added manually)
     */
    public function setPushToDeploy(string $siteId, bool $enabled, ?string $actorId = null): array;
}
