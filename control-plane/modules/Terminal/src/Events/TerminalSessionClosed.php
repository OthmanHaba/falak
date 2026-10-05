<?php

namespace Falak\Terminal\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A terminal session ended. $reason: exited | closed | idle | timeout | failed | server_deleted.
 */
final class TerminalSessionClosed
{
    use Dispatchable;

    public function __construct(
        public string $sessionId,
        public string $organizationId,
        public string $serverId,
        public string $userId,
        public string $reason,
    ) {}
}
