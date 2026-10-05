<?php

namespace Falak\Terminal\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Falak\Servers\Events\ServerDeleted;
use Falak\Terminal\Application\SessionTransitions;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalSession;

/**
 * The server is gone: end its live sessions. Recordings are kept.
 */
final class CloseSessionsOfDeletedServer implements ShouldQueue
{
    public function __construct(private readonly SessionTransitions $transitions) {}

    public function handle(ServerDeleted $event): void
    {
        TerminalSession::query()
            ->where('server_id', $event->serverId)
            ->where('organization_id', $event->organizationId)
            ->whereIn('status', SessionStatus::live())
            ->each(fn (TerminalSession $session) => $this->transitions->close($session, 'server_deleted'));
    }
}
