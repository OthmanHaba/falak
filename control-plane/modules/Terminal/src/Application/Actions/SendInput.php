<?php

namespace Kiln\Terminal\Application\Actions;

use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Terminal\Domain\Models\TerminalSession;

/**
 * Hot path: forward a batch of keystrokes to the PTY. Keyed per (session, client seq) so a retried
 * request never types twice.
 */
final class SendInput
{
    /** Seconds between last_activity_at writes (keeps the hot path to one write per window). */
    public const ACTIVITY_WRITE_INTERVAL = 15;

    public function __construct(private readonly AgentGateway $agents) {}

    /**
     * @throws AgentUnavailable
     */
    public function __invoke(TerminalSession $session, string $base64, int $seq): void
    {
        $this->agents->dispatch(
            $session->server_id,
            'terminal.input',
            ['session_id' => $session->id, 'data' => $base64],
            30,
            "terminal.input:{$session->id}:{$seq}",
        );

        if ($session->last_activity_at === null || $session->last_activity_at->lt(now()->subSeconds(self::ACTIVITY_WRITE_INTERVAL))) {
            TerminalSession::query()->whereKey($session->id)->update(['last_activity_at' => now()]);
        }
    }
}
