<?php

namespace Kiln\Providers\Contracts;

use Kiln\Providers\Contracts\Data\Image;
use Kiln\Providers\Contracts\Data\Machine;
use Kiln\Providers\Contracts\Data\MachineSpec;
use Kiln\Providers\Contracts\Data\Region;
use Kiln\Providers\Contracts\Data\Size;
use Kiln\Providers\Contracts\Exceptions\ProviderException;

/**
 * One cloud provider account (credentials already bound). All methods throw ProviderException
 * on API/authentication errors; destructive calls are idempotent (already-gone = success).
 */
interface ProviderAdapter
{
    public function type(): ProviderType;

    /**
     * Validate the credentials with a cheap authenticated call.
     *
     * @throws ProviderException
     */
    public function verify(): void;

    /**
     * @return list<Region>
     */
    public function regions(): array;

    /**
     * @return list<Size>
     */
    public function sizes(?string $region = null): array;

    /**
     * Ubuntu LTS images suitable for kiln-agent (22.04 / 24.04, amd64 + arm64 where offered).
     *
     * @return list<Image>
     */
    public function images(): array;

    public function createServer(MachineSpec $spec): Machine;

    /** Null when the machine no longer exists. */
    public function getServer(string $id): ?Machine;

    public function destroyServer(string $id): void;

    /**
     * Upload a public key (or return the existing provider id of an identical key).
     *
     * @return string provider-side key id
     */
    public function uploadSshKey(string $name, string $publicKey): string;

    public function deleteSshKey(string $id): void;
}
