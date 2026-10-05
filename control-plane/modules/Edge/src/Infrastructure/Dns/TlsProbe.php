<?php

namespace Falak\Edge\Infrastructure\Dns;

/**
 * Which certificate a server presents for a name (connects to one of the site's own servers only).
 */
interface TlsProbe
{
    /**
     * @return array{status: 'issued'|'pending', message: string, issuer: ?string, expires_at: ?string}
     */
    public function probe(string $name, string $address): array;
}
