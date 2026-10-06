<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Application\Providers\LinkedReferences;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new value (for a linked secret: a new reference) as the next version, which becomes current. For linked
 * secrets, $snapshot is the value resolved upstream (a change seen by the watch, or the value pinned by a
 * rollback); a new reference from a user has none and restarts change detection.
 */
final class SetSecretValue
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly LinkedReferences $references,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(
        Secret $secret,
        #[\SensitiveParameter] string $value,
        ?string $userId,
        ?int $restoredFrom = null,
        #[\SensitiveParameter] ?string $snapshot = null,
        ?string $note = null,
    ): SecretVersion {
        $linked = $secret->kind === SecretKind::Linked;

        if ($linked && trim($value) === '') {
            throw ValidationException::withMessages(['reference' => 'Enter the reference of the value at the provider.']);
        }

        $value = $linked ? trim($value) : $value;

        if ($linked && $snapshot === null) {
            $this->references->validate($secret->organization_id, $secret->provider_id, $value);
        }

        return DB::transaction(function () use ($secret, $value, $userId, $restoredFrom, $linked, $snapshot, $note) {
            /** @var Secret $locked */
            $locked = Secret::query()->whereKey($secret->id)->lockForUpdate()->firstOrFail();
            $number = (int) SecretVersion::query()->where('secret_id', $locked->id)->max('version') + 1;

            $version = SecretVersion::query()->create([
                'secret_id' => $locked->id,
                'version' => $number,
                'ciphertext' => $this->cipher->seal($locked, $number, $value),
                'snapshot' => $linked && $snapshot !== null ? $this->cipher->sealSnapshot($locked, $number, $snapshot) : null,
                'note' => $note,
                'restored_from' => $restoredFrom,
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            // A reference typed by a user (no snapshot) starts change detection over: its value is a new baseline.
            $locked->forceFill(['current_version' => $number, ...($linked && $snapshot === null ? ['value_hmac' => null] : [])])->save();
            $secret->setRawAttributes($locked->getAttributes(), true);

            $this->audit->record(
                match (true) {
                    $restoredFrom !== null => 'secret.rolled_back',
                    $linked && $snapshot !== null => 'secret.changed_upstream',
                    default => 'secret.value_set',
                },
                'secret',
                $locked->id,
                ['name' => $locked->name, 'version' => $number, ...($restoredFrom !== null ? ['restored_from' => $restoredFrom] : [])],
                $locked->organization_id,
            );

            return $version;
        });
    }
}
