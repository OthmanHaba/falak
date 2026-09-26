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

        $session->forceFill(['shared' => $shared])->save();

        $this->audit->record($shared ? 'terminal.session_shared' : 'terminal.session_unshared', 'terminal_session', $session->id, [
            'server_id' => $session->server_id,
        ], $session->organization_id);

        $this->transitions->broadcast($session);
    }
}
