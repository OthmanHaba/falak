<?php

namespace Falak\Terminal\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Session state for live viewers (status, size, sharing) on presence-terminal.sessions.{sessionId}.
 */
final class TerminalSessionUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(
        public string $sessionId,
        public string $status,
        public ?string $closeReason,
        public ?int $exitCode,
        public ?string $error,
        public int $cols,
        public int $rows,
        public bool $shared,
        public int $epoch = 0,
        public ?int $channelEpoch = null,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("terminal.sessions.{$this->sessionId}.{$this->epoch}");
    }

    public function broadcastAs(): string
    {
        return 'terminal.session.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->sessionId,
            'status' => $this->status,
            'close_reason' => $this->closeReason,
            'exit_code' => $this->exitCode,
            'error' => $this->error,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'shared' => $this->shared,
            'channel_epoch' => $this->channelEpoch ?? $this->epoch,
        ];
    }
}
