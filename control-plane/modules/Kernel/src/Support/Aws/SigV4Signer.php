<?php

namespace Falak\Kernel\Support\Aws;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AWS Signature Version 4 (header signing and query-string presigning), implemented in-house so no
 * AWS SDK is needed. Works for every S3-compatible store (AWS S3, Cloudflare R2, Backblaze B2,
 * DigitalOcean Spaces, MinIO) and, with $s3 = false, for other AWS APIs such as KMS.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
 */
final class SigV4Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    public const EMPTY_PAYLOAD_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** Longest validity SigV4 allows for presigned URLs (7 days). */
    public const MAX_EXPIRES = 604800;

    /**
     * @param  bool  $s3  S3 rules: the path is URI-encoded once (not normalized / double-encoded) and
     *                    x-amz-content-sha256 is sent with header-signed requests.
     */
    public function __construct(
        private readonly string $accessKeyId,
        #[\SensitiveParameter] private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $service = 's3',
        #[\SensitiveParameter] private readonly ?string $sessionToken = null,
        private readonly bool $s3 = true,
    ) {}

    /**
     * Presign a request: every auth parameter goes in the query string. Only `host` (plus any
     * $headers given) is signed, and the payload is UNSIGNED-PAYLOAD, so the holder of the URL can
     * send any body — which is the point of handing it to a server for an upload.
     *
     * @param  array<string, string>  $headers  extra headers the client MUST send verbatim
     */
    public function presign(string $method, string $url, int $expires, ?DateTimeInterface $now = null, array $headers = []): string
    {
        if ($expires < 1 || $expires > self::MAX_EXPIRES) {
            throw new InvalidArgumentException('Presigned URLs must expire within 1 second and 7 days.');
        }

        $now = $this->utc($now);
        $parts = $this->parse($url);
        $amzDate = $now->format('Ymd\THis\Z');
        $scope = $this->scope($now);

        $headers = $this->normalizeHeaders(['host' => $parts['host'], ...$headers]);
        $signedHeaders = implode(';', array_keys($headers));

        $query = $parts['query'];
        $query[] = ['X-Amz-Algorithm', self::ALGORITHM];
        $query[] = ['X-Amz-Credential', "{$this->accessKeyId}/{$scope}"];
        $query[] = ['X-Amz-Date', $amzDate];
        $query[] = ['X-Amz-Expires', (string) $expires];

        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $query[] = ['X-Amz-Security-Token', $this->sessionToken];
        }

        $query[] = ['X-Amz-SignedHeaders', $signedHeaders];

        $canonicalQuery = self::canonicalQuery($query);
        $canonicalRequest = $this->canonicalRequest($method, $parts['path'], $canonicalQuery, $headers, $signedHeaders, self::UNSIGNED_PAYLOAD);
        $signature = $this->signature($canonicalRequest, $now);

        return "{$parts['scheme']}://{$parts['host']}{$this->canonicalUri($parts['path'])}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    /**
     * Sign a request with the Authorization header.
     *
     * @param  array<string, string>  $headers
     * @param  string|null  $payloadHash  hex SHA-256 of the body (defaults to the empty body)
     * @return array<string, string> the headers to send (input headers + x-amz-date, x-amz-content-sha256, Authorization)
     */
    public function signHeaders(string $method, string $url, array $headers = [], ?string $payloadHash = null, ?DateTimeInterface $now = null): array
    {
        $now = $this->utc($now);
        $parts = $this->parse($url);
        $payloadHash ??= self::EMPTY_PAYLOAD_SHA256;
        $amzDate = $now->format('Ymd\THis\Z');

        $extra = ['x-amz-date' => $amzDate];

        if ($this->s3) {
            $extra['x-amz-content-sha256'] = $payloadHash;
        }

        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $extra['x-amz-security-token'] = $this->sessionToken;
        }

        $send = array_merge($headers, $extra);
        $signed = $this->normalizeHeaders(['host' => $parts['host'], ...$send]);
        $signedHeaders = implode(';', array_keys($signed));

        $canonicalRequest = $this->canonicalRequest($method, $parts['path'], self::canonicalQuery($parts['query']), $signed, $signedHeaders, $payloadHash);
        $signature = $this->signature($canonicalRequest, $now);

        $send['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKeyId,
            $this->scope($now),
            $signedHeaders,
            $signature,
        );

        return $send;
    }

    /**
     * @param  array<string, string>  $headers  normalized (lowercase, sorted)
     */
    public function canonicalRequest(string $method, string $path, string $canonicalQuery, array $headers, string $signedHeaders, string $payloadHash): string
    {
        $canonicalHeaders = '';

        foreach ($headers as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        return implode("\n", [strtoupper($method), $this->canonicalUri($path), $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);
    }

    public function stringToSign(string $canonicalRequest, DateTimeInterface $now): string
    {
        $now = $this->utc($now);

        return implode("\n", [self::ALGORITHM, $now->format('Ymd\THis\Z'), $this->scope($now), hash('sha256', $canonicalRequest)]);
    }

    public function signature(string $canonicalRequest, DateTimeInterface $now): string
    {
        $now = $this->utc($now);

        $key = hash_hmac('sha256', $now->format('Ymd'), 'AWS4'.$this->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $this->stringToSign($canonicalRequest, $now), $key);
    }

    /**
     * RFC 3986 encoding of each path segment (unreserved characters and "/" kept). S3 paths are
     * encoded once; other services encode the already-encoded path again.
     */
    public function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $segments = explode('/', $path);
        $encoded = array_map(fn (string $segment) => rawurlencode(rawurldecode($segment)), $segments);
        $uri = implode('/', $encoded);

        if (! $this->s3) {
            $uri = implode('/', array_map(fn (string $segment) => rawurlencode($segment), explode('/', $uri)));
        }

        return $uri;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  decoded key/value pairs
     */
    public static function canonicalQuery(array $pairs): string
    {
        $encoded = array_map(fn (array $pair) => [rawurlencode($pair[0]), rawurlencode($pair[1])], $pairs);

        usort($encoded, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn (array $pair) => "{$pair[0]}={$pair[1]}", $encoded));
    }

    private function scope(DateTimeInterface $now): string
    {
        return $now->format('Ymd')."/{$this->region}/{$this->service}/aws4_request";
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string> lowercase names, trimmed values with inner whitespace collapsed, sorted by name
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim((string) $name))] = (string) preg_replace('/\s+/', ' ', trim((string) $value));
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return array{scheme: string, host: string, path: string, query: list<array{0: string, 1: string}>}
     */
    private function parse(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Invalid URL to sign.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (isset($parts['port']) && ! (($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80))) {
            $host .= ':'.$parts['port'];
        }

        $query = [];

        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[] = [rawurldecode($key), rawurldecode($value)];
        }

        return ['scheme' => $scheme, 'host' => $host, 'path' => $parts['path'] ?? '/', 'query' => $query];
    }

    private function utc(?DateTimeInterface $now): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable)->setTimezone(new DateTimeZone('UTC'));
    }
}
