<?php

namespace Falak\Deployments\Contracts;

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
     * @param  ?string  $commit  a specific revision (a function's version hash); null deploys the latest
     * @param  ?string  $message  shown as the deployment's commit message
     * @param  ?string  $author  shown as the deployment's commit author
     * @return string the deployment id
     *
     * @throws ValidationException when the site cannot be deployed (unknown, no source, …)
     */
    public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string;
}
