<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Application\Providers\LinkedReferences;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Illuminate\Validation\ValidationException;

/**
 * Metadata only (the name, scope and kind are fixed: references and history depend on them). Sensitive is
 * one-way: a write-only value never becomes readable again. Linked secrets also change their provider (the
 * current reference must suit it) and their watch.
 */
final class UpdateSecret
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly LinkedReferences $references,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{description?: ?string, sensitive?: bool, available_to_previews?: bool, rotation_days?: ?int, provider_id?: ?string, watch_minutes?: ?int, on_change?: string}  $data
     */
    public function __invoke(Secret $secret, array $data): Secret
    {
        if (array_key_exists('sensitive', $data) && $secret->sensitive && ! $data['sensitive']) {
            throw ValidationException::withMessages(['sensitive' => 'A sensitive secret stays sensitive: its value is write-only. Create a new secret instead.']);
        }

        $changes = array_intersect_key($data, array_flip(['description', 'sensitive', 'available_to_previews', 'rotation_days']));

        if ($secret->kind !== SecretKind::Linked && ($data['provider_id'] ?? null) !== null) {
            throw ValidationException::withMessages(['provider_id' => 'Choose a secret provider of this organization (linked secrets only).']);
        }

        if ($secret->kind === SecretKind::Linked) {
            if (array_key_exists('provider_id', $data) && ($data['provider_id'] === null || strtolower((string) $data['provider_id']) !== $secret->provider_id)) {
                // The current reference must suit the new provider.
                $current = $secret->currentVersion();
                $reference = $current !== null ? $this->cipher->open($secret, $current) : '';
                $changes['provider_id'] = $this->references->validate($secret->organization_id, $data['provider_id'], $reference)->id;
                $changes['value_hmac'] = null;
            }

            $changes += array_intersect_key($data, array_flip(['watch_minutes', 'on_change']));
        }

        $secret->fill($changes);

        if ($secret->isDirty('watch_minutes')) {
            $secret->next_poll_at = $secret->watch_minutes !== null ? now() : null;
        }

        $dirty = array_values(array_diff(array_keys($secret->getDirty()), ['value_hmac', 'next_poll_at']));

        if ($dirty === []) {
            return $secret;
        }

        $secret->save();

        $this->audit->record('secret.updated', 'secret', $secret->id, ['name' => $secret->name, 'changed' => $dirty], $secret->organization_id);

        return $secret;
    }
}
