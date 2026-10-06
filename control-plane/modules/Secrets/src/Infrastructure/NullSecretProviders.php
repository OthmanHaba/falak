<?php

namespace Falak\Secrets\Infrastructure;

use Falak\Secrets\Contracts\Exceptions\SecretProviderUnavailable;
use Falak\Secrets\Contracts\SecretProviders;

/**
 * Until external providers are configured, a linked secret can't be resolved.
 */
final class NullSecretProviders implements SecretProviders
{
    public function resolve(string $reference, ?string $providerId, string $organizationId): string
    {
        $scheme = str_contains($reference, '://') ? strstr($reference, '://', true) : null;

        throw new SecretProviderUnavailable($scheme !== null && preg_match('/^[a-z0-9+.-]{1,32}$/', $scheme) === 1
            ? "provider not configured (no secret provider handles {$scheme}:// references)"
            : 'provider not configured');
    }
}
