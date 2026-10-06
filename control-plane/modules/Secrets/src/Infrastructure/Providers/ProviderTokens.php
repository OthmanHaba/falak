<?php

namespace Falak\Secrets\Infrastructure\Providers;

use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Kernel\Security\Sealer;
use Falak\Secrets\Domain\Models\SecretProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived credentials a driver obtains by logging in (Vault AppRole / JWT client tokens, Infisical access
 * tokens, AWS AssumeRole sessions), kept in the cache until shortly before they expire. Sealed under the
 * organization's data key and bound to the provider and its config version, so editing the provider's
 * credentials starts over and nothing in the cache is readable without the key.
 */
class ProviderTokens
{
    /** Renew this long before the provider's expiry. */
    private const MARGIN = 60;

    public function __construct(private readonly Sealer $sealer) {}

    /**
     * @param  callable(): array{0: string, 1: int}  $login  [token, lifetime in seconds]
     */
    public function remember(SecretProvider $provider, string $name, callable $login): string
    {
        $key = $this->key($provider, $name);
        $sealed = Cache::get($key);

        if (is_string($sealed)) {
            try {
                return $this->sealer->open($sealed, $this->aad($provider, $name), $provider->organization_id);
            } catch (DecryptionFailed|KeyUnavailable) {
                Cache::forget($key);
            }
        }

        [$token, $lifetime] = $login();

        if ($lifetime > self::MARGIN * 2) {
            Cache::put($key, $this->sealer->seal($token, $this->aad($provider, $name), $provider->organization_id), $lifetime - self::MARGIN);
        }

        return $token;
    }

    /** After the provider refused a cached token (revoked early): log in again next time. */
    public function forget(SecretProvider $provider, string $name): void
    {
        Cache::forget($this->key($provider, $name));
    }

    private function key(SecretProvider $provider, string $name): string
    {
        return "secrets:provider-token:{$provider->id}:{$name}:{$provider->config_version}";
    }

    private function aad(SecretProvider $provider, string $name): string
    {
        return Sealer::aad('secret-provider-token', $provider->organization_id, $provider->id, (string) $provider->config_version, $name);
    }
}
