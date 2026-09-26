<?php

namespace Kiln\SourceControl\Infrastructure\Providers;

use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Domain\Models\Connection;

/**
 * Resolves a usable API token for a connection: refreshes expiring OAuth tokens and mints
 * GitHub App installation tokens.
 */
class AccessTokens
{
    public function __construct(
        private readonly OAuthProviders $oauth,
        private readonly GitHubAppTokens $githubApp,
    ) {}

    public function token(Connection $connection): string
    {
        $token = match ($connection->auth_type) {
            'app' => $this->githubApp->installationToken((string) $connection->credential('installation_id')),
            'oauth' => $this->oauthToken($connection),
            'token' => $connection->credential('token'),
            default => null,
        };

        if (! is_string($token) || $token === '') {
            throw SourceControlException::provider($connection->provider->label(), 'The connection has no API token.');
        }

        return $token;
    }

    private function oauthToken(Connection $connection): ?string
    {
        $expiresAt = $connection->credential('expires_at');

        if (is_numeric($expiresAt) && (int) $expiresAt <= time() + 60 && $connection->credential('refresh_token')) {
            $connection->mergeCredentials($this->oauth->refresh($connection->provider, (string) $connection->credential('refresh_token')));
        }

        $token = $connection->credential('access_token');

        return is_string($token) ? $token : null;
    }
}
