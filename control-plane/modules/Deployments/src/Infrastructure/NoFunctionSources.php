<?php

namespace Falak\Deployments\Infrastructure;

use Falak\Deployments\Contracts\Data\FunctionSource;
use Falak\Deployments\Contracts\FunctionSources;

/** Default until the Functions module binds its own: no function has code. */
final class NoFunctionSources implements FunctionSources
{
    public function head(string $siteId): ?FunctionSource
    {
        return null;
    }

    public function find(string $siteId, string $hash): ?FunctionSource
    {
        return null;
    }
}
