<?php

namespace Kiln\Fleet\Contracts\Exceptions;

use RuntimeException;

final class AgentUnavailable extends RuntimeException
{
    public static function forServer(string $serverId): self
    {
        return new self("Server [{$serverId}] has no enrolled agent.");
    }
}
