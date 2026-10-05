<?php

namespace Falak\Builds\Infrastructure\Artifacts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AWS Signature Version 4 query-string presigning for S3-compatible stores (S3, R2, B2, MinIO…).
 * Only `host` is signed and the payload is UNSIGNED-PAYLOAD, so the URL holder may send any body.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
 */
final class SigV4Presigner
{
    public const MAX_EXPIRES = 604800;

    public function __construct(
        private readonly string $accessKeyId,
        #[\SensitiveParameter] private readonly string $secretAccessKey,
        private readonly string $region,
    ) {}

    public function presign(string $method, string $url, int $expires, ?DateTimeInterface $now = null): string
    {
        if ($expires < 1 || $expires > self::MAX_EXPIRES) {
            throw new InvalidArgumentException('Presigned URLs must expire within 1 second and 7 days.');
        }

        $now = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable)->setTimezone(new DateTimeZone('UTC'));
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Invalid URL to sign.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '/';
        $scope = $now->format('Ymd')."/{$this->region}/s3/aws4_request";

        $query = [
            ['X-Amz-Algorithm', 'AWS4-HMAC-SHA256'],
            ['X-Amz-Credential', "{$this->accessKeyId}/{$scope}"],
            ['X-Amz-Date', $now->format('Ymd\THis\Z')],
            ['X-Amz-Expires', (string) $expires],
            ['X-Amz-SignedHeaders', 'host'],
        ];
        $canonicalQuery = self::canonicalQuery($query);
        $canonicalUri = implode('/', array_map(fn (string $s) => rawurlencode(rawurldecode($s)), explode('/', $path)));

        $canonicalRequest = implode("\n", [strtoupper($method), $canonicalUri, $canonicalQuery, "host:{$host}\n", 'host', 'UNSIGNED-PAYLOAD']);
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $now->format('Ymd\THis\Z'), $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $now->format('Ymd'), 'AWS4'.$this->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return "{$scheme}://{$host}{$canonicalUri}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private static function canonicalQuery(array $pairs): string
    {
        $encoded = array_map(fn (array $p) => [rawurlencode($p[0]), rawurlencode($p[1])], $pairs);
        usort($encoded, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn (array $p) => "{$p[0]}={$p[1]}", $encoded));
    }
}
