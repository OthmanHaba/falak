<?php

namespace Kiln\Processes\Application\Listeners;

use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Domain\Models\OctaneRoute;
use Kiln\Processes\Domain\Models\ServerState;
use Kiln\Processes\Domain\Models\Worker;
use Kiln\Servers\Events\ServerDeleted;

final class ForgetDeletedServer
{
    public function handle(ServerDeleted $event): void
    {
        ServerState::query()->where('server_id', $event->serverId)->delete();
        OctaneRoute::query()->where('server_id', $event->serverId)->delete();

        // Drop the server from explicit per-server restrictions.
        foreach ([Worker::class, Daemon::class] as $model) {
            $model::query()->where('organization_id', $event->organizationId)->whereNotNull('server_ids')->get()
                ->each(function (Worker|Daemon $row) use ($event) {
                    $ids = array_values(array_diff($row->server_ids ?? [], [$event->serverId]));

                    if ($ids !== ($row->server_ids ?? [])) {
                        $row->forceFill(['server_ids' => $ids === [] ? null : $ids])->save();
                    }
                });
        }
    }
}
