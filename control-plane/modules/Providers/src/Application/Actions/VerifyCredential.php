<?php

namespace Kiln\Providers\Application\Actions;

use Illuminate\Support\Str;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Domain\CredentialStatus;
use Kiln\Providers\Domain\Models\ProviderCredential;
use Kiln\Providers\Infrastructure\AdapterFactory;

/**
 * Re-checks a stored credential and records the outcome.
 */
final class VerifyCredential
{
    public function __construct(private readonly AdapterFactory $factory) {}

    public function __invoke(ProviderCredential $credential): bool
    {
        try {
            $this->factory->make($credential->provider, $credential->credentials)->verify();
        } catch (ProviderException $e) {
            // Only authentication failures invalidate the credential; outages are transient.
            $credential->forceFill([
                'status' => $e->isAuthenticationError() ? CredentialStatus::Invalid : $credential->status,
                'last_error' => Str::limit($e->getMessage(), 490),
            ])->save();

            return false;
        }

        $credential->forceFill([
            'status' => CredentialStatus::Active,
            'last_verified_at' => now(),
            'last_error' => null,
        ])->save();

        return true;
    }
}
