<?php

namespace Falak\Providers\Contracts;

use Falak\Providers\Contracts\Data\Image;
use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Data\Region;
use Falak\Providers\Contracts\Data\Size;
use Falak\Providers\Contracts\Exceptions\ProviderException;

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
     * Ubuntu LTS images suitable for falak-agent (22.04 / 24.04, amd64 + arm64 where offered).
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
