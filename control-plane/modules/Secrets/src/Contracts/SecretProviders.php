<?php

namespace Falak\Secrets\Contracts;

use Falak\Secrets\Contracts\Exceptions\SecretProviderUnavailable;

/**
 * External secret providers (Vault / OpenBao, AWS Secrets Manager and SSM, 1Password, Doppler, Infisical, generic
 * HTTPS) behind linked secrets. A linked secret stores a reference such as `vault://kv/data/app#DB_PASS` instead
 * of a value; it is resolved here, on the control plane, at deploy time.
 *
 * Caching: a successful lookup is cached sealed (under the organization's data key) for the provider's TTL.
 * Within the TTL the cached value is returned without calling the provider. After it, the provider is asked
 * again; if it is unreachable, the last good value is returned and an alert is raised instead of failing the
 * deployment. Only when there is no last good value does resolve() throw.
 */
interface SecretProviders
{
    /**
     * @param  string|null  $providerId  the organization's provider the reference belongs to (null: by its scheme)
     *
     * @throws SecretProviderUnavailable when no value can be produced (provider not configured or unreachable
     *                                   with nothing cached, reference not found, access denied)
     */
    public function resolve(string $reference, ?string $providerId, string $organizationId): string;
}
