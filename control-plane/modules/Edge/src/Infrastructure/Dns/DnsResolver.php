<?php

namespace Falak\Edge\Infrastructure\Dns;

/**
 * Resolves a name's A / AAAA records (following CNAMEs). A name that does not exist yields an empty answer.
 */
interface DnsResolver
{
    /**
     * @throws DnsLookupFailed when the resolver could not answer (timeout, SERVFAIL, network)
     */
    public function resolve(string $name): DnsAnswer;
}
