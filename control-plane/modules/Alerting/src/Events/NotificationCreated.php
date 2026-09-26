<?php

namespace Kiln\Alerting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification-center entry was created for a user (broadcast on private-alerting.users.{userId}).
 */
final class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $notification  id, type, severity, title, body, url, read_at, created_at
     */
    public function __construct(
        public string $userId,
        public string $organizationId,
        public array $notification,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("alerting.users.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['organization_id' => $this->organizationId, 'notification' => $this->notification];
    }
}
