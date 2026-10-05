<?php

namespace Falak\Fleet\Infrastructure\Pki;

use DateTimeImmutable;

final readonly class IssuedCertificate
{
    public function __construct(
        public string $pem,
        public string $serial,
        public string $fingerprint,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $notAfter,
    ) {}
}
