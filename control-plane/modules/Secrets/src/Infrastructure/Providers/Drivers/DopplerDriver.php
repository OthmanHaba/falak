<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\ProviderClient;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Illuminate\Http\Client\Response;

/**
 * Doppler with a service token (or a personal / service account token that can read the config). The
 * computed value is used (references to other secrets expanded), else the raw one.
 *
 * @see https://docs.doppler.com/reference/secrets-get
 */
final class DopplerDriver implements ProviderDriver
{
    public const API = 'https://api.doppler.com';

    public function __construct(private readonly ProviderClient $client) {}

    public function fetch(SecretProvider $provider, array $reference, string $display): string
    {
        $response = $this->get($provider, '/v3/configs/config/secret', ['project' => $reference['project'], 'config' => $reference['config'], 'name' => $reference['name']]);

        if (! $response->successful()) {
            throw ProviderClient::failure($provider, $response, $display);
        }

        return Values::scalar($response->json('value.computed') ?? $response->json('value.raw'), $display);
    }

    public function test(SecretProvider $provider): void
    {
        $response = $this->get($provider, '/v3/me', []);

        if (! $response->successful()) {
            throw ProviderClient::failure($provider, $response, 'the token');
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(SecretProvider $provider, string $path, array $query): Response
    {
        return $this->client->send($provider, 'GET', self::API.$path, [
            'headers' => ['Authorization' => 'Bearer '.($provider->setting('token') ?? throw new ProviderFailure('The Doppler provider has no token.'))],
            'query' => $query,
        ]);
    }
}
