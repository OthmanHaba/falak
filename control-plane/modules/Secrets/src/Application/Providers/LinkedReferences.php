<?php

namespace Falak\Secrets\Application\Providers;

use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\References;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A linked secret's reference must name a provider of the organization and match its type (`vault://…` for a
 * Vault provider). Without a provider id, the organization's only provider of the reference's type is used.
 */
final class LinkedReferences
{
    /**
     * @throws ValidationException on `reference` or `provider_id`
     */
    public function validate(string $organizationId, ?string $providerId, string $reference): SecretProvider
    {
        $reference = trim($reference);
        $scheme = ProviderType::schemeOf($reference);
        $type = $scheme !== null ? ProviderType::forScheme($scheme) : null;

        if ($providerId !== null && $providerId !== '') {
            $provider = SecretProvider::query()->where('organization_id', $organizationId)->find(strtolower($providerId))
                ?? throw ValidationException::withMessages(['provider_id' => 'Choose a secret provider of this organization.']);
        } else {
            if ($type === null) {
                throw ValidationException::withMessages(['reference' => 'Start the reference with a provider scheme: vault://, aws-sm://, aws-ssm://, op://, doppler://, infisical:// or https://.']);
            }

            $candidates = SecretProvider::query()->where('organization_id', $organizationId)->where('type', $type->value)->limit(2)->get();

            if ($candidates->count() !== 1) {
                throw ValidationException::withMessages(['provider_id' => $candidates->isEmpty()
                    ? "Add a {$type->label()} provider first (Settings → Secret providers)."
                    : "Several {$type->label()} providers are configured: choose one."]);
            }

            $provider = $candidates->first();
        }

        try {
            References::parse($provider->type, $reference, $provider->config);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reference' => $type !== $provider->type
                ? "{$provider->name} is a {$provider->type->label()} provider: its references look like {$provider->type->example()}."
                : $e->getMessage()]);
        }

        return $provider;
    }
}
