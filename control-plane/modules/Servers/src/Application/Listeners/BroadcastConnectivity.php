<?php

namespace Falak\Servers\Application\Listeners;

use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentRevoked;
use Falak\Fleet\Events\AgentWentOffline;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Events\ServerUpdated;

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
