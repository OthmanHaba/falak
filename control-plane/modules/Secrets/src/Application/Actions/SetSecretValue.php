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

        return $this->write($secret, $value, $userId, $restoredFrom, $snapshot, $note, null, null)
            ?? throw new \LogicException('An unconditional write always creates a version.');
    }

    /**
     * The watch saw a new value upstream: record it, unless the secret changed since the poll read it (a rollback,
     * a new reference, another poll): then nothing is written and null is returned.
     */
    public function upstreamChange(Secret $secret, int $polledVersion, string $reference, #[\SensitiveParameter] string $value, string $valueHmac): ?SecretVersion
    {
        return $this->write($secret, $reference, null, null, $value, 'Changed upstream', $polledVersion, $valueHmac);
    }

    private function write(
        Secret $secret,
        #[\SensitiveParameter] string $value,
        ?string $userId,
        ?int $restoredFrom,
        #[\SensitiveParameter] ?string $snapshot,
        ?string $note,
        ?int $ifCurrent,
        ?string $valueHmac,
    ): ?SecretVersion {
        $linked = $secret->kind === SecretKind::Linked;

        return DB::transaction(function () use ($secret, $value, $userId, $restoredFrom, $linked, $snapshot, $note, $ifCurrent, $valueHmac) {
            /** @var Secret $locked */
            $locked = Secret::query()->whereKey($secret->id)->lockForUpdate()->firstOrFail();

            if ($ifCurrent !== null && ! $this->stillCurrent($locked, $ifCurrent, $value)) {
                return null;
            }

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
            $locked->forceFill([
                'current_version' => $number,
                ...($linked && $snapshot === null ? ['value_hmac' => null] : []),
                ...($valueHmac !== null ? ['value_hmac' => $valueHmac] : []),
            ])->save();
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

    /** The polled version is still current, not pinned, and still holds the reference the poll asked for. */
    private function stillCurrent(Secret $locked, int $polledVersion, string $reference): bool
    {
        if ($locked->current_version !== $polledVersion) {
            return false;
        }

        $current = SecretVersion::query()->where('secret_id', $locked->id)->where('version', $polledVersion)->first();

        return $current !== null && $current->disabled_at === null && ! $current->pinned() && $this->cipher->open($locked, $current) === $reference;
    }
}
