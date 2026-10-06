<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A new value (for a linked secret: a new reference) as the next version, which becomes current.
 */
final class SetSecretValue
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Secret $secret, #[\SensitiveParameter] string $value, ?string $userId, ?int $restoredFrom = null): SecretVersion
    {
        if ($secret->kind === SecretKind::Linked && trim($value) === '') {
            throw ValidationException::withMessages(['reference' => 'Enter the reference of the value at the provider.']);
        }

        $value = $secret->kind === SecretKind::Linked ? trim($value) : $value;

        return DB::transaction(function () use ($secret, $value, $userId, $restoredFrom) {
            /** @var Secret $locked */
            $locked = Secret::query()->whereKey($secret->id)->lockForUpdate()->firstOrFail();
            $number = (int) SecretVersion::query()->where('secret_id', $locked->id)->max('version') + 1;

            $version = SecretVersion::query()->create([
                'secret_id' => $locked->id,
                'version' => $number,
                'ciphertext' => $this->cipher->seal($locked, $number, $value),
                'restored_from' => $restoredFrom,
                'created_by' => $userId,
                'created_at' => now(),
            ]);

            $locked->forceFill(['current_version' => $number])->save();
            $secret->setRawAttributes($locked->getAttributes(), true);

            $this->audit->record(
                $restoredFrom !== null ? 'secret.rolled_back' : 'secret.value_set',
                'secret',
                $locked->id,
                ['name' => $locked->name, 'version' => $number, ...($restoredFrom !== null ? ['restored_from' => $restoredFrom] : [])],
                $locked->organization_id,
            );

            return $version;
        });
    }
}
