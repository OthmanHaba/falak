<?php

namespace Falak\Edge\Contracts\Data;

/**
 * Where a domain's DNS must point: a server that serves the site, or the load balancer in front of it.
 */
final readonly class DnsTarget
{
    public function __construct(
        public string $serverId,
        public string $name,
        public ?string $ipv4,
        public ?string $ipv6,
        public bool $loadBalancer = false,
    ) {}

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return array_values(array_filter([$this->ipv4, $this->ipv6]));
    }

    /**
     * @return array{server_id: string, name: string, ipv4: ?string, ipv6: ?string, load_balancer: bool}
     */
    public function toArray(): array
    {
        return ['server_id' => $this->serverId, 'name' => $this->name, 'ipv4' => $this->ipv4, 'ipv6' => $this->ipv6, 'load_balancer' => $this->loadBalancer];
    }
}
