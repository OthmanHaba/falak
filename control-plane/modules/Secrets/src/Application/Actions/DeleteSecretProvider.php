<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\ProviderValue;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a provider and its cached values. Refused while linked secrets resolve through it: they would fail
 * their next deployment.
 */
final class DeleteSecretProvider
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(SecretProvider $provider): void
    {
        DB::transaction(function () use ($provider) {
            $names = Secret::query()->where('organization_id', $provider->organization_id)->where('kind', SecretKind::Linked->value)
                ->where('provider_id', $provider->id)->orderBy('name')->lockForUpdate()->pluck('name')->unique()->values();

            if ($names->isNotEmpty()) {
                throw ValidationException::withMessages(['provider' => "{$names->count()} linked secret(s) use {$provider->name} ({$names->take(5)->implode(', ')}). Link them to another provider or delete them first."]);
            }

            ProviderValue::query()->where('provider_id', $provider->id)->delete();
            $provider->delete();

            $this->audit->record('secret_provider.deleted', 'secret_provider', $provider->id, ['name' => $provider->name, 'type' => $provider->type->value], $provider->organization_id);
        });
    }
}
