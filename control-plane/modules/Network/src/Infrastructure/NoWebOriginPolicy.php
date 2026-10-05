<?php

namespace Falak\Network\Infrastructure;

use Falak\Network\Contracts\WebOriginPolicy;

/** Default: web ports follow the server's own rules. */
final class NoWebOriginPolicy implements WebOriginPolicy
{
    public function for(string $serverId): ?array
    {
        return null;
    }
}
