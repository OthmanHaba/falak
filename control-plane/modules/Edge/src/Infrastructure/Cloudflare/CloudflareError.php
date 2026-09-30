<?php

namespace Kiln\Edge\Infrastructure\Cloudflare;

use RuntimeException;

final class CloudflareError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly int $cloudflareCode = 0)
    {
        parent::__construct($message);
    }
}
