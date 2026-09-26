<?php

namespace Kiln\Fleet\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live command events (output / progress / status) broadcast on private-fleet.commands.{commandId}.
 */
final class CommandOutputReceived implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  list<array<string, mixed>>  $events  seq, kind, stream, data, progress, at
     */
    public function __construct(
        public string $commandId,
        public string $serverId,
        public string $status,
        public array $events,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("fleet.commands.{$this->commandId}");
    }

    public function broadcastAs(): string
    {
        return 'command.output';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['command_id' => $this->commandId, 'server_id' => $this->serverId, 'status' => $this->status, 'events' => $this->events];
    }
}
