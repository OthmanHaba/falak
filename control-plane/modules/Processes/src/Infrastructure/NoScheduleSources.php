<?php

namespace Kiln\Processes\Infrastructure;

use Kiln\Processes\Contracts\ScheduleSources;
use Kiln\Sites\Contracts\Data\SiteData;

/** Default until a module binds its own: no extra jobs. */
final class NoScheduleSources implements ScheduleSources
{
    public function jobs(SiteData $site, string $serverId, bool $isLeader): array
    {
        return [];
    }
}
