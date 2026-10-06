<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Contracts\SecretProviders;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Validation\ValidationException;

/**
 * Metadata only (the name, scope and kind are fixed: references and history depend on them). Sensitive is
 * one-way: a write-only value never becomes readable again.
 */
final class UpdateSecret
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly SecretProviders $providers,
    ) {}

    /**
     * @param  array{description?: ?string, sensitive?: bool, available_to_previews?: bool, rotation_days?: ?int, provider_id?: ?string}  $data
     */
    public function __invoke(Secret $secret, array $data): Secret
    {
        if (array_key_exists('sensitive', $data) && $secret->sensitive && ! $data['sensitive']) {
            throw ValidationException::withMessages(['sensitive' => 'A sensitive secret stays sensitive: its value is write-only. Create a new secret instead.']);
        }

        $changes = array_intersect_key($data, array_flip(['description', 'sensitive', 'available_to_previews', 'rotation_days']));

        if (array_key_exists('provider_id', $data)) {
            $providerId = $data['provider_id'] !== null ? strtolower((string) $data['provider_id']) : null;

            if ($providerId !== null && ($secret->kind !== SecretKind::Linked || ! $this->providers->exists($providerId, $secret->organization_id))) {
                throw ValidationException::withMessages(['provider_id' => 'Choose a secret provider of this organization (linked secrets only).']);
            }

            $changes['provider_id'] = $providerId;
        }

        $secret->fill($changes);
        $dirty = array_keys($secret->getDirty());

        if ($dirty === []) {
            return $secret;
        }

        $secret->save();

        $this->audit->record('secret.updated', 'secret', $secret->id, ['name' => $secret->name, 'changed' => $dirty], $secret->organization_id);

        return $secret;
    }
}
