<?php

namespace Falak\Terminal\Application\Actions;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Terminal\Domain\Models\TerminalSession;

/**
 * Hot path: forward a batch of keystrokes to the PTY. Keyed per (session, user, client stream, seq) so a
 * retried request never types twice, while a page reload (new stream nonce, seq restarts at 0) or a
 * second typist never collides with earlier keys the agent would answer from its cache.
 */
final class SendInput
{
    /** Seconds between last_activity_at writes (keeps the hot path to one write per window). */
    public const ACTIVITY_WRITE_INTERVAL = 15;

    public function __construct(private readonly AgentGateway $agents) {}

    /**
     * @throws AgentUnavailable
     */
    public function __invoke(TerminalSession $session, string $base64, int $seq, string $stream, string $userId): void
    {
        $this->agents->dispatch(
            $session->server_id,
            'terminal.input',
            ['session_id' => $session->id, 'data' => $base64],
            30,
            "terminal.input:{$session->id}:{$userId}:{$stream}:{$seq}",
        );

        if ($session->last_activity_at === null || $session->last_activity_at->lt(now()->subSeconds(self::ACTIVITY_WRITE_INTERVAL))) {
            TerminalSession::query()->whereKey($session->id)->update(['last_activity_at' => now()]);
        }
    }
}
