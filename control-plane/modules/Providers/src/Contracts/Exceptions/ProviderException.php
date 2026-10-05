<?php

namespace Falak\Providers\Contracts\Exceptions;

use RuntimeException;
use Throwable;

final class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function isAuthenticationError(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
