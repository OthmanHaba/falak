<?php

namespace Falak\Providers\Infrastructure\Adapters;

use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderAdapter;
use Falak\Providers\Contracts\ProviderType;

/**
 * "Bring your own server": nothing is created through an API; servers enroll via the install command.
 */
final class CustomAdapter implements ProviderAdapter
{
    public function type(): ProviderType
    {
        return ProviderType::Custom;
    }

    public function verify(): void {}

    public function regions(): array
    {
        return [];
    }

    public function sizes(?string $region = null): array
    {
        return [];
    }

    public function images(): array
    {
        return [];
    }

    public function createServer(MachineSpec $spec): Machine
    {
        throw new ProviderException('Custom servers are registered via install command.', $this->type()->value);
    }

    public function getServer(string $id): ?Machine
    {
        return null;
    }

    public function destroyServer(string $id): void {}

    public function uploadSshKey(string $name, string $publicKey): string
    {
        throw new ProviderException('Custom servers are registered via install command.', $this->type()->value);
    }

    public function deleteSshKey(string $id): void {}
}
