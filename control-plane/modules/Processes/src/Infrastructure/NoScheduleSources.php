<?php

namespace Falak\Processes\Infrastructure;

use Falak\Processes\Contracts\ScheduleSources;
use Falak\Sites\Contracts\Data\SiteData;

/** Default until a module binds its own: no extra jobs. */
final class NoScheduleSources implements ScheduleSources
{
    public function jobs(SiteData $site, string $serverId, bool $isLeader): array
    {
        return [];
    }
}
