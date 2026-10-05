<?php

namespace Falak\Processes\Infrastructure;

use Falak\Processes\Contracts\OctaneRouting;
use Falak\Processes\Domain\Enums\OctaneRouteStatus;
use Falak\Processes\Domain\Models\OctaneRoute;

final class EloquentOctaneRouting implements OctaneRouting
{
    public function listeningPort(string $siteId, string $serverId): ?int
    {
        $port = OctaneRoute::query()
            ->where('site_id', $siteId)
            ->where('server_id', $serverId)
            ->where('status', OctaneRouteStatus::Listening)
            ->value('port');

        return $port !== null ? (int) $port : null;
    }
}
