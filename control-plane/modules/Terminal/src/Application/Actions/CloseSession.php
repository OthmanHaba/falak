<?php

namespace Kiln\Terminal\Application\Actions;

use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Terminal\Application\SessionTransitions;
use Kiln\Terminal\Domain\Models\TerminalSession;

/**
 * Close a session on the host (terminal.close is a no-op for unknown sessions) and record the reason.
 * The agent's later `finished` event does not override it.
 */
final class CloseSession
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly SessionTransitions $transitions,
    ) {}

    public function __invoke(TerminalSession $session, string $reason = 'closed', bool $failed = false, ?string $error = null): bool
    {
        if (! $session->isLive()) {
            return false;
        }

        try {
            $this->agents->dispatch($session->server_id, 'terminal.close', ['session_id' => $session->id], 60, "terminal.close:{$session->id}");
        } catch (AgentUnavailable) {
            // Nothing to close on the host; the PTY dies with the agent's idle timeout anyway.
        }

        return $this->transitions->close($session, $reason, null, $error, $failed);
    }
}
