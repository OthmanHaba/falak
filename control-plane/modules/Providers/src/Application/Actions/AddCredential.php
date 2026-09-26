<?php

namespace Kiln\Providers\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Providers\Domain\CredentialFields;
use Kiln\Providers\Domain\CredentialStatus;
use Kiln\Providers\Domain\Models\ProviderCredential;
use Kiln\Providers\Events\ProviderCredentialAdded;
use Kiln\Providers\Infrastructure\AdapterFactory;

/**
 * Stores a provider credential after verifying it against the provider API.
 */
final class AddCredential
{
    public function __construct(
        private readonly AdapterFactory $factory,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __invoke(string $organizationId, ProviderType $provider, string $name, array $credentials, ?string $createdBy = null): ProviderCredential
    {
        if (! $provider->hasApi()) {
            throw ValidationException::withMessages(['provider' => 'Custom servers do not need a provider credential.']);
        }

        $credentials = self::only($provider, $credentials);

        try {
            $this->factory->make($provider, $credentials)->verify();
        } catch (ProviderException $e) {
            throw ValidationException::withMessages(['credentials' => "Could not verify the credential: {$e->getMessage()}"]);
        }

        $credential = ProviderCredential::query()->create([
            'organization_id' => $organizationId,
            'provider' => $provider,
            'name' => $name,
            'credentials' => $credentials,
            'status' => CredentialStatus::Active,
            'last_verified_at' => now(),
            'created_by' => $createdBy,
        ]);

        $this->audit->record('provider_credential.created', 'provider_credential', $credential->id, [
            'name' => $name,
            'provider' => $provider->value,
        ], $organizationId);

        ProviderCredentialAdded::dispatch($organizationId, $credential->id, $provider->value);

        return $credential;
    }

    /**
     * Keep only the fields the provider declares (drops anything unexpected).
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    public static function only(ProviderType $provider, array $credentials): array
    {
        $clean = [];

        foreach (CredentialFields::for($provider) as $field) {
            $value = $credentials[$field['name']] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $clean[$field['name']] = trim($value);
            }
        }

        return $clean;
    }
}
