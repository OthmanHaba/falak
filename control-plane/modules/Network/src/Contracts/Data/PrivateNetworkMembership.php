<?php

namespace Kiln\Network\Contracts\Data;

final readonly class PrivateNetworkMembership
{
    public function __construct(
        public string $networkId,
        public string $name,
        public string $interface,
        public string $address,
        public string $cidr,
        public bool $applied,
    ) {}
}
