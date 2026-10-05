<?php

namespace Falak\Providers\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Falak\Providers\Contracts\ProviderType;
use Falak\Providers\Domain\CredentialStatus;
use Falak\Providers\Domain\Models\ProviderCredential;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    protected $model = ProviderCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::ulid(),
            'provider' => ProviderType::Hetzner,
            'name' => 'Hetzner '.Str::random(6),
            'credentials' => ['token' => 'hcloud-'.Str::random(40)],
            'status' => CredentialStatus::Active,
            'last_verified_at' => now(),
        ];
    }

    public function forOrganization(string $organizationId): static
    {
        return $this->state(fn () => ['organization_id' => $organizationId]);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function provider(ProviderType $type, array $credentials): static
    {
        return $this->state(fn () => ['provider' => $type, 'name' => $type->label().' '.Str::random(6), 'credentials' => $credentials]);
    }
}
