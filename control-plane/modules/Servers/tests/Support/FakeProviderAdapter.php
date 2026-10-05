<?php

namespace Falak\Servers\Tests\Support;

use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\ProviderAdapter;
use Falak\Providers\Contracts\ProviderType;

final class FakeProviderAdapter implements ProviderAdapter
{
    public function __construct(private readonly FakeProviderGateway $gateway) {}

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

    public function type(): ProviderType
    {
        return ProviderType::Hetzner;
    }

    public function verify(): void {}

    public function createServer(MachineSpec $spec): Machine
    {
        if ($this->gateway->failCreate) {
            throw $this->gateway->failCreate;
        }

        if ($this->gateway->beforeCreate) {
            ($this->gateway->beforeCreate)($spec);
        }

        $this->gateway->created[] = $spec;

        return new Machine('machine-'.count($this->gateway->created), $spec->name, Machine::STATUS_PROVISIONING, $this->gateway->ipv4, '2001:db8::1', null, $spec->region);
    }

    public function getServer(string $id): ?Machine
    {
        return new Machine($id, 'x', Machine::STATUS_RUNNING, '198.51.100.99');
    }

    public function destroyServer(string $id): void
    {
        if ($this->gateway->failDestroy) {
            throw $this->gateway->failDestroy;
        }

        $this->gateway->destroyed[] = $id;
    }

    public function uploadSshKey(string $name, string $publicKey): string
    {
        return $this->gateway->uploadedKeys[$publicKey] ??= 'key-'.(count($this->gateway->uploadedKeys) + 1);
    }

    public function deleteSshKey(string $id): void {}
}
