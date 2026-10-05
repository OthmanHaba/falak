<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Infrastructure\GitHubApp\GitHubAppResolver;

/**
 * Resolves a usable API token for a connection: refreshes expiring OAuth tokens and mints
 * GitHub App installation tokens.
 */
class AccessTokens
{
    public function __construct(
        private readonly OAuthProviders $oauth,
        private readonly GitHubAppTokens $githubApp,
        private readonly GitHubAppResolver $apps,
    ) {}

    public function token(Connection $connection): string
    {
        $token = match ($connection->auth_type) {
            'app' => $this->installationToken($connection),
            'oauth' => $this->oauthToken($connection),
            'token' => $connection->credential('token'),
            default => null,
        };

        if (! is_string($token) || $token === '') {
            throw SourceControlException::provider($connection->provider->label(), 'The connection has no API token.');
        }

        return $token;
    }

    private function installationToken(Connection $connection): string
    {
        if ($connection->status !== 'active') {
            throw SourceControlException::provider('GitHub', $connection->status === 'suspended'
                ? 'The GitHub App installation is suspended on GitHub. Unsuspend it to deploy again.'
                : 'The GitHub App was uninstalled on GitHub. Install it again from Settings → Source control.');
        }

        $app = $this->apps->forConnection($connection)
            ?? throw SourceControlException::provider('GitHub', 'The GitHub App of this connection is no longer configured.');

        return $this->githubApp->installationToken($app, $connection->installationId());
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
