<?php

namespace Kiln\Providers\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Domain\CredentialStatus;
use Kiln\Providers\Domain\Models\ProviderCredential;
use Kiln\Providers\Infrastructure\AdapterFactory;

/**
 * Renames a credential and/or rotates its secret (new secrets are verified before they replace the old ones).
 */
final class UpdateCredential
{
    public function __construct(
        private readonly AdapterFactory $factory,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $credentials  partial update: fields left empty keep their current value
     */
    public function __invoke(ProviderCredential $credential, ?string $name = null, ?array $credentials = null): ProviderCredential
    {
        $changes = [];

        if ($name !== null && $name !== $credential->name) {
            $changes['name'] = ['from' => $credential->name, 'to' => $name];
            $credential->name = $name;
        }

        $rotated = $credentials !== null ? AddCredential::only($credential->provider, $credentials) : [];

        if ($rotated !== []) {
            $merged = [...$credential->credentials, ...$rotated];

            try {
                $this->factory->make($credential->provider, $merged)->verify();
            } catch (ProviderException $e) {
                throw ValidationException::withMessages(['credentials' => "Could not verify the credential: {$e->getMessage()}"]);
            }

            $credential->credentials = $merged;
            $credential->status = CredentialStatus::Active;
            $credential->last_verified_at = now();
            $credential->last_error = null;
            $changes['rotated_fields'] = array_keys($rotated);
        }

        if ($changes === []) {
            return $credential;
        }

        $credential->save();

        $this->audit->record('provider_credential.updated', 'provider_credential', $credential->id, $changes, $credential->organization_id);

        return $credential;
    }
}
