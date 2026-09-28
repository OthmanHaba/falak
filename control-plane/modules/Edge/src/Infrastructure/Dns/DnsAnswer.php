<?php

namespace Kiln\Edge\Infrastructure\Dns;

final readonly class DnsAnswer
{
    /**
     * @param  list<string>  $cnames  CNAME chain, in order
     * @param  list<string>  $ipv4
     * @param  list<string>  $ipv6
     */
    public function __construct(
        public array $cnames = [],
        public array $ipv4 = [],
        public array $ipv6 = [],
    ) {}

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return [...$this->ipv4, ...$this->ipv6];
    }
}
