<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Secrets\Domain\Models\SecretProvider;
use Falak\Secrets\Infrastructure\Providers\ProviderClient;
use Falak\Secrets\Infrastructure\Providers\ProviderFailure;
use Falak\Secrets\Infrastructure\Providers\ProviderTokens;
use Illuminate\Http\Client\Response;

/**
 * HashiCorp Vault and OpenBao, KV v2 (or v1). Auth: a token, AppRole (role id and secret id) or a JWT / OIDC
 * role; the client token a login returns is kept until shortly before its lease ends. Namespaces (Vault
 * Enterprise / HCP) go in X-Vault-Namespace.
 *
 * @see https://developer.hashicorp.com/vault/api-docs/secret/kv/kv-v2#read-secret-version
 */
final class VaultDriver implements ProviderDriver
{
    public function __construct(
        private readonly ProviderClient $client,
        private readonly ProviderTokens $tokens,
    ) {}

    public function fetch(SecretProvider $provider, array $reference, string $display): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', $reference['path'])));
        $response = $this->get($provider, "/v1/{$path}", $display);
        $data = $provider->setting('kv_version', '2') === '1' ? $response->json('data') : $response->json('data.data');

        if (! is_array($data) || ! array_key_exists($reference['key'], $data)) {
            throw new ProviderFailure("{$display}: the secret has no key \"{$reference['key']}\".");
        }

        return Values::scalar($data[$reference['key']], $display);
    }

    public function test(SecretProvider $provider): void
    {
        $this->get($provider, '/v1/auth/token/lookup-self', 'the token');
    }

    private function get(SecretProvider $provider, string $path, string $what): Response
    {
        $response = $this->client->send($provider, 'GET', $this->address($provider).$path, ['headers' => [
            'X-Vault-Token' => $this->token($provider),
            ...$this->namespace($provider),
        ]]);

        if (! $response->successful()) {
            if ($response->status() === 403) {
                $this->tokens->forget($provider, 'vault');
            }

            throw ProviderClient::failure($provider, $response, $what);
        }

        return $response;
    }

    private function token(SecretProvider $provider): string
    {
        $method = $provider->setting('auth_method', 'token');

        if ($method === 'token') {
            return $provider->setting('token') ?? throw new ProviderFailure('The Vault provider has no token.');
        }

        return $this->tokens->remember($provider, 'vault', function () use ($provider, $method) {
            $body = $method === 'approle'
                ? ['role_id' => (string) $provider->setting('role_id'), 'secret_id' => (string) $provider->setting('secret_id')]
                : ['role' => (string) $provider->setting('role'), 'jwt' => (string) $provider->setting('jwt')];
            $mount = trim((string) $provider->setting('auth_mount', $method === 'approle' ? 'approle' : 'jwt'), '/');

            $response = $this->client->send($provider, 'POST', $this->address($provider).'/v1/auth/'.implode('/', array_map('rawurlencode', explode('/', $mount))).'/login', [
                'headers' => $this->namespace($provider),
                'json' => $body,
            ]);

            if (! $response->successful()) {
                throw ProviderClient::failure($provider, $response, "the {$method} login");
            }

            $token = $response->json('auth.client_token');

            if (! is_string($token) || $token === '') {
                throw new ProviderFailure("The Vault {$method} login returned no client token.");
            }

            return [$token, (int) $response->json('auth.lease_duration', 0)];
        });
    }

    private function address(SecretProvider $provider): string
    {
        return rtrim((string) $provider->setting('address'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function namespace(SecretProvider $provider): array
    {
        $namespace = $provider->setting('namespace');

        return $namespace !== null ? ['X-Vault-Namespace' => $namespace] : [];
    }
}
