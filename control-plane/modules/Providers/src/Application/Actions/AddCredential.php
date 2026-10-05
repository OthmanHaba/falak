<?php

namespace Falak\Providers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderType;
use Falak\Providers\Domain\CredentialFields;
use Falak\Providers\Domain\CredentialStatus;
use Falak\Providers\Domain\Models\ProviderCredential;
use Falak\Providers\Events\ProviderCredentialAdded;
use Falak\Providers\Infrastructure\AdapterFactory;
use Illuminate\Validation\ValidationException;

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
