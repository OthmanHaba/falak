<?php

namespace Kiln\Processes\Infrastructure;

use Kiln\Processes\Contracts\OctaneRouting;
use Kiln\Processes\Domain\Enums\OctaneRouteStatus;
use Kiln\Processes\Domain\Models\OctaneRoute;

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
