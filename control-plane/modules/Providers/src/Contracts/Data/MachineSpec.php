<?php

namespace Falak\Providers\Contracts\Data;

final readonly class MachineSpec
{
    /**
     * @param  list<string>  $sshKeyIds  provider-side key ids (from ProviderAdapter::uploadSshKey)
     * @param  array<string, string>  $labels  e.g. ['falak-server' => '<ulid>'] (sanitized per provider)
     * @param  ?string  $userData  cloud-init user data (the falak-agent install script)
     */
    public function __construct(
        public string $name,
        public string $region,
        public string $size,
        public string $image,
        public array $sshKeyIds = [],
        public ?string $userData = null,
        public array $labels = [],
        public bool $ipv6 = true,
    ) {}
}
