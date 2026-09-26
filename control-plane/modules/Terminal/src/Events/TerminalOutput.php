<?php

namespace Kiln\Terminal\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raw PTY output for live viewers on presence-terminal.sessions.{sessionId}.
 * `data` is base64 of raw bytes; one agent event may be split into several parts to stay under the
 * broadcaster's message size limit (each part decodes independently).
 */
final class TerminalOutput implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(
        public string $sessionId,
        public int $seq,
        public int $part,
        public int $parts,
        public string $data,
        public int $epoch = 0,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("terminal.sessions.{$this->sessionId}.{$this->epoch}");
    }

    public function broadcastAs(): string
    {
        return 'terminal.output';
    }

    /**
     * @return array{seq: int, part: int, parts: int, data: string}
     */
    public function broadcastWith(): array
    {
        return ['seq' => $this->seq, 'part' => $this->part, 'parts' => $this->parts, 'data' => $this->data];
    }
}
