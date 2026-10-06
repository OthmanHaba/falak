<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Secrets\Application\Providers\ProviderValueCache;
use Falak\Secrets\Application\SecretCipher;
use Falak\Secrets\Domain\Enums\SecretKind;
use Falak\Secrets\Domain\Models\ProviderValue;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;
use Illuminate\Support\Facades\DB;

/**
 * Delete a secret and every version. Its access log stays (until pruned); references to it fail the next deploy.
 * A linked secret's cached provider values go too, unless another linked secret of the same provider still
 * uses the reference.
 */
final class DeleteSecret
{
    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Secret $secret): void
    {
        DB::transaction(function () use ($secret) {
            if ($secret->kind === SecretKind::Linked && $secret->provider_id !== null) {
                $this->purgeCachedValues($secret);
            }

            $secret->versions()->delete();
            $secret->delete();

            $this->audit->record('secret.deleted', 'secret', $secret->id, ['name' => $secret->name, 'scope' => $secret->scope_type->value, 'scope_id' => $secret->scope_id], $secret->organization_id);
        });
    }

    private function purgeCachedValues(Secret $secret): void
    {
        $references = fn (Secret $owner, iterable $versions) => collect($versions)
            ->map(function (SecretVersion $version) use ($owner) {
                try {
                    return ProviderValueCache::hash($this->cipher->open($owner, $version));
                } catch (DecryptionFailed|KeyUnavailable) {
                    return null;
                }
            })->filter();

        $mine = $references($secret, SecretVersion::query()->where('secret_id', $secret->id)->get());

        $others = Secret::query()->where('organization_id', $secret->organization_id)->where('kind', SecretKind::Linked->value)
            ->where('provider_id', $secret->provider_id)->whereKeyNot($secret->id)->get()
            ->flatMap(fn (Secret $other) => $references($other, SecretVersion::query()->where('secret_id', $other->id)->get()));

        $unused = $mine->diff($others)->unique()->values()->all();

        if ($unused !== []) {
            ProviderValue::query()->where('provider_id', $secret->provider_id)->whereIn('reference_hash', $unused)->delete();
        }
    }
}
