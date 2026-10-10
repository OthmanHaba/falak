<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\ProviderClient;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Falak\Secrets\Infrastructure\Providers\ProviderTokens;

/**
 * Infisical (cloud or self-hosted) with a machine identity's universal auth: the client id and secret log in
 * for an access token, kept until shortly before it expires.
 *
 * @see https://infisical.com/docs/api-reference/endpoints/universal-auth/login
 * @see https://infisical.com/docs/api-reference/endpoints/secrets/read
 */
final class InfisicalDriver implements ProviderDriver
{
    public const CLOUD = 'https://app.infisical.com';

    public function __construct(
        private readonly ProviderClient $client,
        private readonly ProviderTokens $tokens,
    ) {}

    public function fetch(SecretProvider $provider, array $reference, string $display): string
    {
        $response = $this->client->send($provider, 'GET', $this->base($provider).'/api/v3/secrets/raw/'.rawurlencode($reference['name']), [
            'headers' => ['Authorization' => 'Bearer '.$this->token($provider)],
            'query' => ['workspaceId' => $reference['project_id'], 'environment' => $reference['environment'], 'secretPath' => $reference['path']],
        ]);

        if (! $response->successful()) {
            if ($response->status() === 401) {
                $this->tokens->forget($provider, 'infisical');
            }

            throw ProviderClient::failure($provider, $response, $display);
        }

        return Values::scalar($response->json('secret.secretValue'), $display);
    }

    public function test(SecretProvider $provider): void
    {
        $this->tokens->forget($provider, 'infisical');
        $this->token($provider);
    }

    private function token(SecretProvider $provider): string
    {
        return $this->tokens->remember($provider, 'infisical', function () use ($provider) {
            $response = $this->client->send($provider, 'POST', $this->base($provider).'/api/v1/auth/universal-auth/login', ['json' => [
                'clientId' => (string) $provider->setting('client_id'),
                'clientSecret' => (string) $provider->setting('client_secret'),
            ]]);

            if (! $response->successful()) {
                throw ProviderClient::failure($provider, $response, 'the universal auth login');
            }

            $token = $response->json('accessToken');

            if (! is_string($token) || $token === '') {
                throw new ProviderFailure('The Infisical login returned no access token.');
            }

            return [$token, (int) $response->json('expiresIn', 0)];
        });
    }

    private function base(SecretProvider $provider): string
    {
        return rtrim((string) $provider->setting('base_url', self::CLOUD), '/');
    }
}
