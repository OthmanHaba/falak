<?php

namespace Kiln\Terminal\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Terminal\Application\SessionTransitions;
use Kiln\Terminal\Domain\Models\TerminalSession;

final class ShareSession
{
    public function __construct(
        private readonly SessionTransitions $transitions,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(TerminalSession $session, bool $shared): void
    {
        if ($session->shared === $shared) {
            return;
        }

        $previousEpoch = $session->channel_epoch;

        // Presence channels are authorized only on subscribe: unsharing moves the session to a new
        // channel name so watchers that are no longer allowed cannot keep receiving output.
        $session->forceFill(['shared' => $shared, 'channel_epoch' => $shared ? $previousEpoch : $previousEpoch + 1])->save();

        $this->audit->record($shared ? 'terminal.session_shared' : 'terminal.session_unshared', 'terminal_session', $session->id, [
            'server_id' => $session->server_id,
        ], $session->organization_id);

        if (! $shared) {
            // Tell everyone on the old channel (the owner resubscribes to the new epoch; others are refused).
            $this->transitions->broadcast($session, $previousEpoch);
        }

        $this->transitions->broadcast($session);
    }
}
