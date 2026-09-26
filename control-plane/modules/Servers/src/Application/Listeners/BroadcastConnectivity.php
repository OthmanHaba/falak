<?php

namespace Kiln\Servers\Application\Listeners;

use Kiln\Fleet\Events\AgentCameOnline;
use Kiln\Fleet\Events\AgentRevoked;
use Kiln\Fleet\Events\AgentWentOffline;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\ServerUpdated;

final class BroadcastConnectivity
{
    public function handle(AgentWentOffline|AgentCameOnline|AgentRevoked $event): void
    {
        $server = $event->serverId ? Server::query()->find($event->serverId) : null;

        if ($server) {
            ServerUpdated::dispatch($server->id, $server->status->value, $server->status_message, $server->provision_command_id, $event instanceof AgentCameOnline);
        }
    }
}
