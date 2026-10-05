<?php

namespace Falak\Terminal\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class TerminalSessionOpened
{
    use Dispatchable;

    public function __construct(
        public string $sessionId,
        public string $organizationId,
        public string $serverId,
        public string $userId,
        public string $unixUser,
    ) {}
}
