<?php

namespace Falak\Providers\Contracts;

use Falak\Providers\Contracts\Data\CredentialSummary;
use Falak\Providers\Contracts\Data\Image;
use Falak\Providers\Contracts\Data\Region;
use Falak\Providers\Contracts\Data\Size;
use Falak\Providers\Contracts\Exceptions\ProviderException;

/**
 * Entry point for other modules (Servers): resolves an organization's stored credential into a ready adapter.
 * Credentials never leave the Providers module.
 */
interface ProviderGateway
{
    /**
     * @return list<CredentialSummary>
     */
    public function credentials(string $organizationId): array;

    public function credential(string $organizationId, string $credentialId): ?CredentialSummary;

    /**
     * @throws ProviderException when the credential does not exist in the organization
     */
    public function adapter(string $organizationId, string $credentialId): ProviderAdapter;

    /**
     * Cached catalog lookups (regions / sizes / images) for UI pickers.
     *
     * @return list<Region>
     */
    public function regions(string $organizationId, string $credentialId): array;

    /**
     * @return list<Size>
     */
    public function sizes(string $organizationId, string $credentialId, ?string $region = null): array;

    /**
     * @return list<Image>
     */
    public function images(string $organizationId, string $credentialId): array;
}
