<?php

namespace Falak\Secrets\Application\Providers;

use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Kernel\Security\Sealer;
use Falak\Secrets\Domain\Models\ProviderValue;
use Falak\Secrets\Domain\Models\SecretProvider;
use Illuminate\Support\Carbon;

/**
 * The last good value of each reference, in the database (it must survive a cache flush: it is the fallback when
 * the provider is down), sealed under the organization's data key and bound to (organization, provider,
 * its config version, reference): a row copied to another reference, provider or organization does not open.
 */
final class ProviderValueCache
{
    public function __construct(private readonly Sealer $sealer) {}

    /**
     * @return array{value: string, fetched_at: Carbon}|null null when nothing usable is stored
     */
    public function get(SecretProvider $provider, string $reference): ?array
    {
        $hash = self::hash($reference);
        $row = ProviderValue::query()->where('provider_id', $provider->id)->where('reference_hash', $hash)->first();

        if ($row === null || $row->organization_id !== $provider->organization_id) {
            return null;
        }

        try {
            return ['value' => $this->sealer->open($row->ciphertext, $this->aad($provider, $hash), $provider->organization_id), 'fetched_at' => $row->fetched_at];
        } catch (DecryptionFailed|KeyUnavailable) {
            return null;
        }
    }

    public function put(SecretProvider $provider, string $reference, #[\SensitiveParameter] string $value): void
    {
        $hash = self::hash($reference);

        ProviderValue::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'reference_hash' => $hash],
            [
                'organization_id' => $provider->organization_id,
                'ciphertext' => $this->sealer->seal($value, $this->aad($provider, $hash), $provider->organization_id),
                'fetched_at' => now(),
            ],
        );
    }

    public static function hash(string $reference): string
    {
        return hash('sha256', $reference);
    }

    private function aad(SecretProvider $provider, string $hash): string
    {
        // The config version too: a value fetched before the provider's settings changed never opens after.
        return Sealer::aad('secret-provider-value', $provider->organization_id, $provider->id, (string) $provider->config_version, $hash);
    }
}
