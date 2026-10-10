<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Falak\Secrets\Infrastructure\Providers\References;

/**
 * One kind of external provider. Drivers do no caching of values and no fallback: that is the job of the
 * provider registry around them.
 */
interface ProviderDriver
{
    /**
     * The value a reference points at, fetched now.
     *
     * @param  array<string, string>  $reference  the parts parsed by {@see References}
     * @param  string  $display  the reference as written (for error messages)
     *
     * @throws ProviderFailure
     */
    public function fetch(SecretProvider $provider, array $reference, string $display): string;

    /**
     * Prove the endpoint is reachable and the credentials are accepted (no value is read where avoidable).
     *
     * @throws ProviderFailure
     */
    public function test(SecretProvider $provider): void;
}
