<?php

namespace Falak\Templates\Application\Import;

interface HostResolver
{
    /**
     * @return list<string> the host's IPv4 and IPv6 addresses (empty when it does not resolve)
     */
    public function resolve(string $host): array;
}
