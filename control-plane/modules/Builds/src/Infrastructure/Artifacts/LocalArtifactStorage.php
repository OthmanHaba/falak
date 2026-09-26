<?php

namespace Kiln\Builds\Infrastructure\Artifacts;

use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Kiln\Builds\Application\Artifacts\ArtifactStorage;

/**
 * Artifacts on the control-plane disk, uploaded and downloaded through signed, expiring URLs
 * (routes builds.artifacts.upload / builds.artifacts.download). Signatures cover the path only, so
 * the URLs stay valid behind a TLS-terminating proxy; the base URL is forced to https because
 * deploy.fetch only accepts https artifact URLs.
 */
final class LocalArtifactStorage implements ArtifactStorage
{
    public function __construct(
        private readonly string $root,
        private readonly string $baseUrl,
    ) {}

    public function driver(): string
    {
        return 'local';
    }

    public function uploadTarget(string $key, int $ttlSeconds): array
    {
        return ['url' => $this->signed('builds.artifacts.upload', $key, $ttlSeconds), 'headers' => ['Content-Type' => 'application/octet-stream']];
    }

    public function downloadUrl(string $key, int $ttlSeconds): string
    {
        return $this->signed('builds.artifacts.download', $key, $ttlSeconds);
    }

    public function checksum(string $key): ?string
    {
        $path = $this->path($key);

        return is_file($path) ? (hash_file('sha256', $path) ?: null) : null;
    }

    public function size(string $key): ?int
    {
        $path = $this->path($key);

        return is_file($path) ? (int) filesize($path) : null;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Absolute path of a key; rejects traversal. */
    public function path(string $key): string
    {
        if (! self::validKey($key)) {
            throw new InvalidArgumentException('Invalid artifact key.');
        }

        return rtrim($this->root, '/').'/'.$key;
    }

    public static function validKey(string $key): bool
    {
        return preg_match('#^[a-z0-9]+(/[a-z0-9]+)*\.(tar\.gz|tar\.zst|tar)$#', $key) === 1;
    }

    private function signed(string $route, string $key, int $ttlSeconds): string
    {
        $path = URL::temporarySignedRoute($route, now()->addSeconds($ttlSeconds), ['key' => $key], absolute: false);

        return self::https($this->baseUrl).$path;
    }

    private static function https(string $base): string
    {
        $base = rtrim($base, '/');

        return (string) preg_replace('#^http://#i', 'https://', $base);
    }
}
