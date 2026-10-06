<?php

namespace Falak\Sites\Contracts;

/**
 * Which site variables hold secrets. Their names go to agents in payloads' `mask` (the agent masks the values in
 * deploy, hook and build output), the secrets mode `files` passes them as /run/secrets files, and the control plane
 * masks them again in the deployment log.
 */
interface SecretVariables
{
    /**
     * @param  array<string, string>  $variables  a site's variables as stored (`${{ service.KEY }}` references unresolved)
     * @return list<string> the names of the secret ones
     */
    public function names(array $variables): array;
}
