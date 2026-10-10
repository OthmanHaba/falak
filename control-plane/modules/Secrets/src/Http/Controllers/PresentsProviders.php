<?php

namespace Falak\Secrets\Http\Controllers;

use Falak\Secrets\Application\Providers\ProviderFields;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretProvider;

/**
 * JSON shapes of secret providers for the UI and the API. Credentials never go through here: only the settings
 * that are not secret, and the names of the credentials that are stored.
 */
trait PresentsProviders
{
    /**
     * @param  array<string, int>|null  $usage  provider id => linked secrets using it
     * @return array<string, mixed>
     */
    protected function presentProvider(SecretProvider $provider, ?array $usage = null): array
    {
        $secretNames = ProviderFields::secretNames($provider->type);
        $config = $provider->config;

        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'type' => $provider->type->value,
            'type_label' => $provider->type->label(),
            'scheme' => $provider->type->scheme(),
            'settings' => (object) array_diff_key($config, array_flip($secretNames)),
            'stored_credentials' => array_values(array_filter($secretNames, fn (string $name) => ($config[$name] ?? '') !== '')),
            'allow_private_network' => $provider->allow_private_network,
            'cache_ttl_seconds' => $provider->cache_ttl_seconds,
            'status' => $provider->status->value,
            'last_checked_at' => $provider->last_checked_at?->toIso8601String(),
            'last_error' => $provider->last_error,
            'secrets_count' => $usage !== null ? ($usage[$provider->id] ?? 0) : $this->providerUsage($provider->organization_id)[$provider->id] ?? 0,
            'created_at' => $provider->created_at->toIso8601String(),
            'updated_at' => $provider->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int> provider id => linked secrets using it
     */
    protected function providerUsage(string $organizationId): array
    {
        return Secret::query()->where('organization_id', $organizationId)->where('kind', SecretKind::Linked->value)->whereNotNull('provider_id')
            ->selectRaw('provider_id, count(*) as aggregate')->groupBy('provider_id')->pluck('aggregate', 'provider_id')
            ->map(fn ($count) => (int) $count)->all();
    }

    /**
     * The provider types with their settings, for the add / edit dialog.
     *
     * @return list<array<string, mixed>>
     */
    protected function providerTypes(): array
    {
        return array_map(fn (ProviderType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'scheme' => $type->scheme(),
            'example' => $type->example(),
            'self_hostable' => $type->selfHostable(),
            'fields' => array_map(fn (array $field) => array_diff_key($field, ['pattern' => true]), ProviderFields::for($type)),
        ], ProviderType::cases());
    }
}
