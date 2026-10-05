<?php

namespace Falak\Databases\Infrastructure\ObjectStorage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\StorageProvider;

/**
 * Minimal S3-compatible client: presigned PUT/GET URLs for agents and header-signed requests
 * issued by the control plane itself (verification probe, pruning deletes).
 */
final class ObjectStore
{
    public function __construct(
        private readonly StorageProvider $provider,
        private readonly HttpFactory $http,
        private readonly int $timeout = 30,
        private readonly EndpointGuard $guard = new EndpointGuard(allowPrivate: true),
    ) {}

    /**
     * The https endpoint for a driver (without the bucket).
     */
    public static function endpointFor(StorageDriver $driver, string $region, ?string $endpoint = null, ?string $accountId = null): string
    {
        return rtrim(match ($driver) {
            StorageDriver::S3 => "https://s3.{$region}.amazonaws.com",
            StorageDriver::R2 => $accountId !== null && $accountId !== '' ? "https://{$accountId}.r2.cloudflarestorage.com" : (string) $endpoint,
            StorageDriver::B2 => "https://s3.{$region}.backblazeb2.com",
            StorageDriver::Spaces => "https://{$region}.digitaloceanspaces.com",
            StorageDriver::Minio => (string) $endpoint,
        }, '/');
    }

    public function provider(): StorageProvider
    {
        return $this->provider;
    }

    /**
     * Object key under the provider's prefix.
     */
    public function key(string ...$segments): string
    {
        $parts = array_filter([trim((string) $this->provider->prefix, '/'), ...array_map(fn (string $s) => trim($s, '/'), $segments)], fn (string $s) => $s !== '');

        return implode('/', $parts);
    }

    public function url(string $key): string
    {
        $endpoint = rtrim((string) ($this->provider->endpoint ?: self::endpointFor($this->provider->driver, $this->provider->region)), '/');
        $bucket = $this->provider->bucket;
        $path = '/'.ltrim($key, '/');

        // Dotted bucket names break TLS for virtual-hosted style (the wildcard cert covers one label).
        if ($this->provider->path_style || str_contains($bucket, '.')) {
            return "{$endpoint}/{$bucket}{$path}";
        }

        $parts = (array) parse_url($endpoint);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.$bucket.'.'.($parts['host'] ?? '').$port.$path;
    }

    public function presignPut(string $key, int $ttlSeconds): string
    {
        return $this->signer()->presign('PUT', $this->url($key), $ttlSeconds);
    }

    public function presignGet(string $key, int $ttlSeconds): string
    {
        return $this->signer()->presign('GET', $this->url($key), $ttlSeconds);
    }

    /**
     * @throws StorageRequestFailed
     */
    public function put(string $key, string $body, string $contentType = 'application/octet-stream'): void
    {
        $url = $this->url($key);
        $headers = $this->signer()->signHeaders('PUT', $url, ['content-type' => $contentType], hash('sha256', $body));

        $this->send(fn () => $this->http->withHeaders($headers)->timeout($this->timeout)->withBody($body, $contentType)->put($url), 'PUT', $key);
    }

    /**
     * Delete an object. A missing object counts as deleted.
     *
     * @throws StorageRequestFailed
     */
    public function delete(string $key): void
    {
        $url = $this->url($key);
        $headers = $this->signer()->signHeaders('DELETE', $url);

        $this->send(fn () => $this->http->withHeaders($headers)->timeout($this->timeout)->delete($url), 'DELETE', $key, allowNotFound: true);
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws StorageRequestFailed
     */
    private function send(callable $request, string $method, string $key, bool $allowNotFound = false): void
    {
        if ($refusal = $this->guard->refusal($this->url($key))) {
            throw new StorageRequestFailed($refusal);
        }

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new StorageRequestFailed("{$method} {$key}: could not connect to the storage endpoint ({$e->getMessage()}).", 0, $e);
        }

        if ($response->successful() || ($allowNotFound && $response->status() === 404)) {
            return;
        }

        $code = null;

        if (preg_match('#<Code>([^<]+)</Code>#', $response->body(), $m) === 1) {
            $code = $m[1];
        }

        throw new StorageRequestFailed("{$method} {$key} failed: HTTP {$response->status()}".($code ? " ({$code})" : '').'.');
    }

    private function signer(): SigV4Signer
    {
        return new SigV4Signer(
            $this->provider->access_key_id,
            $this->provider->secret_access_key,
            $this->provider->region,
        );
    }
}
