<?php

namespace Kiln\Fleet\Contracts\Exceptions;

use InvalidArgumentException;

final class UnknownCommandType extends InvalidArgumentException
{
    public static function named(string $type): self
    {
        return new self("No agent-protocol schema exists for command type [{$type}].");
    }
}
