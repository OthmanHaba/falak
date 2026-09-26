<?php

namespace Kiln\SourceControl\Contracts\Exceptions;

use RuntimeException;

/**
 * A provider API call failed (authentication, permissions, not found, network).
 */
class SourceControlException extends RuntimeException
{
    public static function provider(string $provider, string $message, int $status = 0): self
    {
        return new self("{$provider}: {$message}".($status ? " (HTTP {$status})" : ''), $status);
    }
}
