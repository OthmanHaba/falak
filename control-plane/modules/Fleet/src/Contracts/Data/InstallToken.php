<?php

namespace Kiln\Fleet\Contracts\Data;

use DateTimeImmutable;

final readonly class InstallToken
{
    public function __construct(
        public string $id,
        public string $token,
        public string $url,
        public string $command,
        public DateTimeImmutable $expiresAt,
    ) {}
}
