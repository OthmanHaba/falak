<?php

namespace Kiln\Fleet\Contracts\Exceptions;

use RuntimeException;

final class CommandTimedOut extends RuntimeException
{
    public static function waiting(string $commandId, int $seconds): self
    {
        return new self("Command [{$commandId}] did not finish within {$seconds}s.");
    }
}
