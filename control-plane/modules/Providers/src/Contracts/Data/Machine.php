<?php

namespace Falak\Providers\Contracts\Data;

final readonly class Machine
{
    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_RUNNING = 'running';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?string $ipv4 = null,
        public ?string $ipv6 = null,
        public ?string $privateIpv4 = null,
        public ?string $region = null,
    ) {}
}
