<?php

namespace Kiln\Processes\Domain\Models;

/**
 * Optional restriction of a site process to some of the site's servers (null = all of them).
 *
 * @property ?list<string> $server_ids
 */
trait ServerTargets
{
    public function runsOn(string $serverId): bool
    {
        $ids = $this->server_ids;

        return $ids === null || $ids === [] || in_array($serverId, $ids, true);
    }
}
