<?php

namespace Kiln\Deployments\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Start a deployment from another module (e.g. Templates deploys a new site right after creating it). Same
 * queueing, audit and validation as POST /sites/{site}/deployments; the caller authorizes the user.
 */
interface DeploymentTrigger
{
    /**
     * Queue a deployment of the site's configured source and start it when nothing else is running.
     *
     * @return string the deployment id
     *
     * @throws ValidationException when the site cannot be deployed (unknown, no source, …)
     */
    public function deploy(string $siteId, ?string $requestedBy = null): string;
}
