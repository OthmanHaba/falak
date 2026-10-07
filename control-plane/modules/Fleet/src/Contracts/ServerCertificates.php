<?php

namespace Falak\Fleet\Contracts;

use Falak\Fleet\Contracts\Data\IssuedCertificate;
use InvalidArgumentException;

/**
 * TLS server certificates signed by the Falak CA, for services Falak runs on servers (database containers): clients
 * that trust the CA verify them.
 */
interface ServerCertificates
{
    /**
     * Issue a certificate (with a new EC private key) for these DNS names and IP addresses (subjectAltName; the first is
     * the common name).
     *
     * @param  list<string>  $hostnames
     *
     * @throws InvalidArgumentException when $hostnames is empty
     */
    public function issue(array $hostnames, int $validityDays = 397): IssuedCertificate;
}
