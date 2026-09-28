<?php

namespace Kiln\Deployments\Contracts;

use Kiln\Deployments\Contracts\Data\LiveRelease;

/**
 * Which release every site runs on a server. Processes supervises a site's programs on a server only once it has a
 * live release there, with the release's ids and environment in the program env.
 */
interface LiveReleases
{
    /**
     * @return array<string, LiveRelease> keyed by (lower-case) site id
     */
    public function onServer(string $serverId): array;
}
