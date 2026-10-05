<?php

namespace Falak\Builds\Infrastructure\Artifacts;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Falak\Builds\Application\Artifacts\ArtifactStorage;
use Throwable;

/**
 * Artifacts in an S3-compatible bucket. The control plane never proxies the bytes: builders PUT and
 * agents GET through presigned URLs; pruning deletes through a presigned DELETE.
 */
final class S3ArtifactStorage implements ArtifactStorage
{
    public function __construct(
        private readonly SigV4Presigner $signer,
        private readonly HttpFactory $http,
        private readonly string $endpoint,
        private readonly string $bucket,
        private readonly string $prefix = '',
        private readonly bool $pathStyle = false,
    ) {}

    public function driver(): string
    {
        return 's3';
    }

    public function uploadTarget(string $key, int $ttlSeconds): array
    {
        return ['url' => $this->signer->presign('PUT', $this->url($key), $ttlSeconds), 'headers' => []];
    }

    public function downloadUrl(string $key, int $ttlSeconds): string
    {
        return $this->signer->presign('GET', $this->url($key), $ttlSeconds);
    }

    public function checksum(string $key): ?string
    {
        return null;
    }

    public function size(string $key): ?int
    {
        try {
            $response = $this->http->timeout(15)->head($this->signer->presign('HEAD', $this->url($key), 300));

            return $response->successful() ? (int) $response->header('Content-Length') : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->http->timeout(15)->delete($this->signer->presign('DELETE', $this->url($key), 300));
        } catch (Throwable $e) {
            Log::warning('builds: artifact delete failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    public function url(string $key): string
    {
        $key = trim(trim($this->prefix, '/').'/'.ltrim($key, '/'), '/');
        $endpoint = rtrim($this->endpoint, '/');

        if ($this->pathStyle || str_contains($this->bucket, '.')) {
            return "{$endpoint}/{$this->bucket}/{$key}";
        }

        $parts = (array) parse_url($endpoint);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.$this->bucket.'.'.($parts['host'] ?? '').$port.'/'.$key;
    }
}
