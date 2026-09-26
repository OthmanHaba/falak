<?php

namespace Kiln\Servers\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast to live server pages (private-servers.{id}) whenever status, facts or connectivity change.
 */
final class ServerUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(
        public string $serverId,
        public string $status,
        public ?string $statusMessage = null,
        public ?string $provisionCommandId = null,
        public ?bool $online = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("servers.{$this->serverId}");
    }

    public function broadcastAs(): string
    {
        return 'server.updated';
    }
}
