<?php

namespace Kiln\SourceControl\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Throwable;

/**
 * GitHub App authentication: an RS256 JWT signed with the app's private key, exchanged for
 * short-lived installation access tokens (cached until shortly before they expire).
 */
class GitHubAppTokens
{
    public function __construct(private readonly Cache $cache) {}

    public function configured(): bool
    {
        return (bool) config('source_control.github.app.id') && (bool) config('source_control.github.app.private_key');
    }

    public function jwt(?int $now = null): string
    {
        if (! $this->configured()) {
            throw SourceControlException::provider('GitHub', 'The GitHub App is not configured.');
        }

        $now ??= time();
        $segments = [
            self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) config('source_control.github.app.id')])),
        ];

        try {
            $key = PublicKeyLoader::loadPrivateKey(str_replace('\n', "\n", (string) config('source_control.github.app.private_key')));
        } catch (Throwable) {
            throw SourceControlException::provider('GitHub', 'The GitHub App private key is invalid.');
        }

        if (! $key instanceof RSA\PrivateKey) {
            throw SourceControlException::provider('GitHub', 'The GitHub App private key must be an RSA key.');
        }

        $signature = $key->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->sign(implode('.', $segments));

        return implode('.', [...$segments, self::base64url($signature)]);
    }

    public function installationToken(string $installationId): string
    {
        if ($installationId === '') {
            throw SourceControlException::provider('GitHub', 'The connection has no GitHub App installation.');
        }

        $key = "source-control:github-app:{$installationId}:token";
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->app('POST', "/app/installations/{$installationId}/access_tokens");
        $token = (string) $response['token'];
        $expires = isset($response['expires_at']) ? strtotime((string) $response['expires_at']) : false;
        $ttl = $expires ? max(60, $expires - time() - 300) : 1800;

        $this->cache->put($key, $token, $ttl);

        return $token;
    }

    /**
     * @return array{id: int|string, account: string, target_type: ?string}
     */
    public function installation(string $installationId): array
    {
        $body = $this->app('GET', "/app/installations/{$installationId}");

        return [
            'id' => $body['id'] ?? $installationId,
            'account' => (string) ($body['account']['login'] ?? ''),
            'target_type' => $body['target_type'] ?? null,
        ];
    }

    public function forget(string $installationId): void
    {
        $this->cache->forget("source-control:github-app:{$installationId}:token");
    }

    /**
     * @return array<string, mixed>
     */
    private function app(string $method, string $path): array
    {
        try {
            $response = Http::withToken($this->jwt())
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(20)
                ->send($method, rtrim((string) config('source_control.github.api_url'), '/').$path);
        } catch (ConnectionException $e) {
            throw SourceControlException::provider('GitHub', 'Could not reach the API: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw SourceControlException::provider('GitHub', $response->status() === 404 ? 'GitHub App installation not found.' : 'GitHub App authentication failed.', $response->status());
        }

        return (array) $response->json();
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
