<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\ProviderType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2 authorization-code flows for GitHub (OAuth app), GitLab and Bitbucket Cloud.
 */
class OAuthProviders
{
    public function configured(ProviderType $provider): bool
    {
        return $provider->hasApi()
            && (bool) config("source_control.{$provider->value}.client_id")
            && (bool) config("source_control.{$provider->value}.client_secret");
    }

    public function authorizeUrl(ProviderType $provider, string $state, string $redirectUri): string
    {
        $this->ensureConfigured($provider);
        $clientId = (string) config("source_control.{$provider->value}.client_id");

        [$url, $query] = match ($provider) {
            ProviderType::GitHub => [$this->web($provider).'/login/oauth/authorize', ['scope' => 'repo admin:repo_hook read:user']],
            ProviderType::GitLab => [$this->web($provider).'/oauth/authorize', ['response_type' => 'code', 'scope' => 'api read_user']],
            ProviderType::Bitbucket => [$this->web($provider).'/site/oauth2/authorize', ['response_type' => 'code']],
            ProviderType::Custom => throw new SourceControlException('Custom git has no OAuth.'),
        };

        return $url.'?'.http_build_query(['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state, ...$query]);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    public function exchange(ProviderType $provider, string $code, string $redirectUri): array
    {
        $this->ensureConfigured($provider);

        return $this->token($provider, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    public function refresh(ProviderType $provider, string $refreshToken): array
    {
        $this->ensureConfigured($provider);

        return $this->token($provider, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function token(ProviderType $provider, array $params): array
    {
        $clientId = (string) config("source_control.{$provider->value}.client_id");
        $secret = (string) config("source_control.{$provider->value}.client_secret");

        try {
            $response = match ($provider) {
                ProviderType::GitHub => Http::acceptJson()->asForm()->post($this->web($provider).'/login/oauth/access_token', [...$params, 'client_id' => $clientId, 'client_secret' => $secret]),
                ProviderType::GitLab => Http::acceptJson()->asForm()->post($this->web($provider).'/oauth/token', [...$params, 'client_id' => $clientId, 'client_secret' => $secret]),
                ProviderType::Bitbucket => Http::acceptJson()->asForm()->withBasicAuth($clientId, $secret)->post($this->web($provider).'/site/oauth2/access_token', $params),
                ProviderType::Custom => throw new SourceControlException('Custom git has no OAuth.'),
            };
        } catch (ConnectionException $e) {
            throw SourceControlException::provider($provider->label(), 'Could not reach the OAuth server: '.$e->getMessage());
        }

        return $this->parse($provider, $response);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    private function parse(ProviderType $provider, Response $response): array
    {
        $body = (array) $response->json();

        // GitHub answers 200 with {"error": "bad_verification_code"} on failures.
        if ($response->failed() || ! isset($body['access_token']) || ! is_string($body['access_token'])) {
            $reason = $body['error_description'] ?? $body['error'] ?? 'no access token returned';

            throw SourceControlException::provider($provider->label(), 'OAuth token exchange failed: '.(is_string($reason) ? $reason : 'unknown error'), $response->status());
        }

        $credentials = ['access_token' => $body['access_token']];

        if (isset($body['refresh_token']) && is_string($body['refresh_token'])) {
            $credentials['refresh_token'] = $body['refresh_token'];
        }

        if (isset($body['expires_in']) && is_numeric($body['expires_in'])) {
            $credentials['expires_at'] = time() + (int) $body['expires_in'];
        }

        return $credentials;
    }

    private function web(ProviderType $provider): string
    {
        return rtrim((string) config("source_control.{$provider->value}.url"), '/');
    }

    private function ensureConfigured(ProviderType $provider): void
    {
        if (! $this->configured($provider)) {
            throw SourceControlException::provider($provider->label(), 'OAuth is not configured.');
        }
    }
}
