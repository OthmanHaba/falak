<?php

namespace Kiln\Deployments\Contracts;

use Kiln\Deployments\Contracts\Data\FunctionSource;

/**
 * The code of function sites (SiteRuntime::Function), implemented by the Functions module. A deployment of a function
 * records its version's hash as the commit, so a release — and a rollback to it — always runs the same code.
 */
interface FunctionSources
{
    /** The function's newest version, or null when it has no code yet. */
    public function head(string $siteId): ?FunctionSource;

    /** The version with this hash (the files are the same for every version sharing a hash). */
    public function find(string $siteId, string $hash): ?FunctionSource;
}
