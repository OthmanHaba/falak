<?php

namespace Falak\Fleet\Infrastructure;

use Falak\Fleet\Contracts\Data\IssuedCertificate;
use Falak\Fleet\Contracts\ServerCertificates;
use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use InvalidArgumentException;

/**
 * Server certificates from the Falak CA (the one agents pin).
 */
final class CaServerCertificates implements ServerCertificates
{
    public function __construct(private readonly CertificateAuthorityService $ca) {}

    public function issue(array $hostnames, int $validityDays = 397): IssuedCertificate
    {
        $hostnames = array_values(array_unique(array_filter(array_map('strval', $hostnames), fn (string $host) => $host !== '')));

        if ($hostnames === []) {
            throw new InvalidArgumentException('At least one hostname is required.');
        }

        ['certificate' => $certificate, 'private_key_pem' => $key] = $this->ca->issueServerCertificate($hostnames, $validityDays);

        return new IssuedCertificate(
            CertificateAuthorityService::normalizePem($certificate->pem),
            $key,
            $this->ca->caPem(),
            $certificate->notAfter,
        );
    }
}
