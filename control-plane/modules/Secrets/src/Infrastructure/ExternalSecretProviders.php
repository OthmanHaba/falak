<?php

namespace Falak\Secrets\Infrastructure;

use Falak\Secrets\Application\Providers\ProviderValueCache;
use Falak\Secrets\Contracts\Exceptions\SecretProviderUnavailable;
use Falak\Secrets\Contracts\SecretProviders;
use Falak\Secrets\Domain\Enums\ProviderStatus;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Events\ProviderRecovered;
use Falak\Secrets\Events\ProviderUnreachable;
use Falak\Secrets\Infrastructure\Providers\Drivers\AwsDriver;
use Falak\Secrets\Infrastructure\Providers\Drivers\DopplerDriver;
use Falak\Secrets\Infrastructure\Providers\Drivers\HttpDriver;
use Falak\Secrets\Infrastructure\Providers\Drivers\InfisicalDriver;
use Falak\Secrets\Infrastructure\Providers\Drivers\OnePasswordDriver;
use Falak\Secrets\Infrastructure\Providers\Drivers\ProviderDriver;
use Falak\Secrets\Infrastructure\Providers\Drivers\VaultDriver;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Falak\Secrets\Infrastructure\Providers\References;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Linked secrets resolved through the organization's providers, with the caching and fallback the
 * {@see SecretProviders} contract describes. Each call records the provider's status (and raises or clears
 * the secrets.provider_unreachable alert).
 */
class ExternalSecretProviders implements SecretProviders
{
    /** Status writes on success are skipped when the last one is this recent (seconds). */
    private const STATUS_INTERVAL = 60;

    public function __construct(private readonly ProviderValueCache $cache) {}

    public function resolve(#[\SensitiveParameter] string $reference, ?string $providerId, string $organizationId): string
    {
        $provider = $this->provider($reference, $providerId, $organizationId);
        $cached = $this->cache->get($provider, $reference);

        if ($cached !== null && $cached['fetched_at']->gt(now()->subSeconds($provider->cache_ttl_seconds))) {
            return $cached['value'];
        }

        try {
            return $this->fetch($provider, $reference, staleAvailable: $cached !== null);
        } catch (ProviderFailure $e) {
            if ($cached === null) {
                throw new SecretProviderUnavailable("{$provider->name}: {$e->getMessage()}");
            }

            // Never the value or the reference's credentials: the provider and what went wrong.
            Log::warning('Secret provider unreachable; using the last good value', [
                'organization_id' => $organizationId,
                'provider_id' => $provider->id,
                'fetched_at' => $cached['fetched_at']->toIso8601String(),
                'error' => $e->getMessage(),
            ]);

            return $cached['value'];
        }
    }

    public function exists(string $providerId, string $organizationId): bool
    {
        return SecretProvider::query()->where('organization_id', $organizationId)->whereKey(strtolower($providerId))->exists();
    }

    /**
     * Ask the provider now, whatever is cached (the watch), and cache the answer.
     *
     * @throws SecretProviderUnavailable
     */
    public function refresh(#[\SensitiveParameter] string $reference, ?string $providerId, string $organizationId): string
    {
        $provider = $this->provider($reference, $providerId, $organizationId);

        try {
            return $this->fetch($provider, $reference, staleAvailable: $this->cache->get($provider, $reference) !== null);
        } catch (ProviderFailure $e) {
            throw new SecretProviderUnavailable("{$provider->name}: {$e->getMessage()}");
        }
    }

    /**
     * Prove the provider answers and accepts its credentials; records the status either way.
     *
     * @throws ProviderFailure
     */
    public function test(SecretProvider $provider): void
    {
        try {
            $this->driver($provider->type)->test($provider);
        } catch (ProviderFailure $e) {
            $this->failed($provider, $e, false);

            throw $e;
        }

        $this->succeeded($provider, force: true);
    }

    public function driver(ProviderType $type): ProviderDriver
    {
        return app(match ($type) {
            ProviderType::Vault => VaultDriver::class,
            ProviderType::AwsSecretsManager, ProviderType::AwsSsm => AwsDriver::class,
            ProviderType::OnePassword => OnePasswordDriver::class,
            ProviderType::Doppler => DopplerDriver::class,
            ProviderType::Infisical => InfisicalDriver::class,
            ProviderType::Http => HttpDriver::class,
        });
    }

    /**
     * @throws ProviderFailure
     */
    private function fetch(SecretProvider $provider, string $reference, bool $staleAvailable): string
    {
        try {
            $parts = References::parse($provider->type, $reference, $provider->config);
        } catch (InvalidArgumentException $e) {
            throw new SecretProviderUnavailable($e->getMessage());
        }

        try {
            $value = $this->driver($provider->type)->fetch($provider, $parts, $reference);
        } catch (ProviderFailure $e) {
            $this->failed($provider, $e, $staleAvailable);

            throw $e;
        }

        $this->cache->put($provider, $reference, $value);
        $this->succeeded($provider);

        return $value;
    }

    private function provider(string $reference, ?string $providerId, string $organizationId): SecretProvider
    {
        if ($providerId !== null) {
            $provider = SecretProvider::query()->where('organization_id', $organizationId)->find($providerId);

            return $provider ?? throw new SecretProviderUnavailable('its provider no longer exists; choose another one');
        }

        $scheme = ProviderType::schemeOf($reference);
        $type = $scheme !== null ? ProviderType::forScheme($scheme) : null;
        $candidates = $type !== null
            ? SecretProvider::query()->where('organization_id', $organizationId)->where('type', $type->value)->limit(2)->get()
            : collect();

        return match ($candidates->count()) {
            1 => $candidates->first(),
            0 => throw new SecretProviderUnavailable($scheme !== null && preg_match('/^[a-z0-9+.-]{1,32}$/', $scheme) === 1
                ? "provider not configured (no secret provider handles {$scheme}:// references)"
                : 'provider not configured'),
            default => throw new SecretProviderUnavailable("several {$type?->label()} providers are configured; choose one for this secret"),
        };
    }

    private function succeeded(SecretProvider $provider, bool $force = false): void
    {
        $recovered = $provider->status === ProviderStatus::Error;

        if (! $force && ! $recovered && $provider->status === ProviderStatus::Ok
            && $provider->last_checked_at?->gt(now()->subSeconds(self::STATUS_INTERVAL))) {
            return;
        }

        $this->record($provider, ProviderStatus::Ok, null);

        if ($recovered) {
            ProviderRecovered::dispatch($provider->organization_id, $provider->id, $provider->name);
        }
    }

    private function failed(SecretProvider $provider, ProviderFailure $e, bool $usedStale): void
    {
        $this->record($provider, ProviderStatus::Error, mb_substr($e->getMessage(), 0, 500));

        ProviderUnreachable::dispatch($provider->organization_id, $provider->id, $provider->name, $e->getMessage(), $usedStale);
    }

    /** Without touching updated_at: that is the credentials' version (cached login tokens are keyed by it). */
    private function record(SecretProvider $provider, ProviderStatus $status, ?string $error): void
    {
        $provider->timestamps = false;
        $provider->forceFill(['status' => $status, 'last_error' => $error, 'last_checked_at' => now()])->save();
        $provider->timestamps = true;
    }
}
