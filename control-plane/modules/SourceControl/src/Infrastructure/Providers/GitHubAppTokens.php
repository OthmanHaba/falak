<?php

namespace Kiln\SourceControl\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Infrastructure\GitHubApp\AppCredentials;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Throwable;

/**
 * GitHub App authentication: an RS256 JWT signed with the app's private key, exchanged for short-lived
 * installation access tokens. GitHub issues them for one hour; Kiln caches them for at most 50 minutes so a
 * token handed to a build always has 10+ minutes left. Tokens are never logged or persisted outside the cache.
 */
class GitHubAppTokens
{
    public const TOKEN_TTL = 3000;

    public function __construct(private readonly Cache $cache) {}

    public function jwt(AppCredentials $app, ?int $now = null): string
    {
        $now ??= time();
        $segments = [
            self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $app->appId])),
        ];

        try {
            $key = PublicKeyLoader::loadPrivateKey($app->privateKey);
        } catch (Throwable) {
            throw SourceControlException::provider('GitHub', 'The GitHub App private key is invalid.');
        }

        if (! $key instanceof RSA\PrivateKey) {
            throw SourceControlException::provider('GitHub', 'The GitHub App private key must be an RSA key.');
        }

        $signature = $key->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->sign(implode('.', $segments));

        return implode('.', [...$segments, self::base64url($signature)]);
    }

    public function installationToken(AppCredentials $app, string $installationId): string
    {
        if ($installationId === '') {
            throw SourceControlException::provider('GitHub', 'The connection has no GitHub App installation.');
        }

        $key = self::cacheKey($app, $installationId);
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->app($app, 'POST', "/app/installations/{$installationId}/access_tokens");
        $token = (string) ($response['token'] ?? '');

        if ($token === '') {
            throw SourceControlException::provider('GitHub', 'GitHub returned no installation token.');
        }

        $expires = isset($response['expires_at']) ? strtotime((string) $response['expires_at']) : false;
        $ttl = $expires ? min(self::TOKEN_TTL, max(60, $expires - now()->getTimestamp() - 600)) : self::TOKEN_TTL;

        $this->cache->put($key, $token, $ttl);

        return $token;
    }

    /**
     * @return array{id: int|string, account: string, target_type: ?string, suspended: bool}
     */
    public function installation(AppCredentials $app, string $installationId): array
    {
        $body = $this->app($app, 'GET', "/app/installations/{$installationId}");

        return [
            'id' => $body['id'] ?? $installationId,
            'account' => (string) ($body['account']['login'] ?? ''),
            'target_type' => $body['target_type'] ?? null,
            'suspended' => ($body['suspended_at'] ?? null) !== null,
        ];
    }

    /** Uninstall the app from the account (best effort from callers; 404 = already gone). */
    public function deleteInstallation(AppCredentials $app, string $installationId): void
    {
        $this->forget($app, $installationId);
        $this->app($app, 'DELETE', "/app/installations/{$installationId}", nullOn404: true);
    }

    public function forget(AppCredentials $app, string $installationId): void
    {
        $this->cache->forget(self::cacheKey($app, $installationId));
    }

    /**
     * @return array<string, mixed>
     */
    private function app(AppCredentials $app, string $method, string $path, bool $nullOn404 = false): array
    {
        try {
            $response = Http::withToken($this->jwt($app))
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(20)
                ->send($method, rtrim((string) config('source_control.github.api_url'), '/').$path);
        } catch (ConnectionException $e) {
            throw SourceControlException::provider('GitHub', 'Could not reach the API: '.$e->getMessage());
        }

        if ($nullOn404 && $response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw self::error($response);
        }

        return (array) $response->json();
    }

    private static function error(Response $response): SourceControlException
    {
        return SourceControlException::provider('GitHub', match ($response->status()) {
            404 => 'GitHub App installation not found.',
            403 => 'The GitHub App installation is suspended or lacks permission.',
            default => 'GitHub App authentication failed.',
        }, $response->status());
    }

    private static function cacheKey(AppCredentials $app, string $installationId): string
    {
        return "source-control:github-app:{$app->key}:{$app->appId}:{$installationId}:token";
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
