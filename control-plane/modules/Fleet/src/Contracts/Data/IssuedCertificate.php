<?php

namespace Falak\Fleet\Contracts\Data;

use DateTimeImmutable;
use Falak\Fleet\Contracts\ServerCertificates;

/**
 * A server certificate from {@see ServerCertificates}. The private key is a secret: store it
 * sealed, send it only to the server that serves it.
 */
final readonly class IssuedCertificate
{
    public function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        /** The Falak CA certificate (clients verify against it) */
        public string $caPem,
        public DateTimeImmutable $notAfter,
    ) {}
}
