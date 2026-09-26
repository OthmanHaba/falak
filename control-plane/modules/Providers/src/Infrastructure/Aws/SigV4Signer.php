<?php

namespace Kiln\Providers\Infrastructure\Aws;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AWS Signature Version 4 request signer (header-based).
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final class SigV4Signer
{
    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $service,
    ) {}

    /**
     * Returns the headers to send: the given headers plus X-Amz-Date and Authorization.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, string $body, DateTimeInterface $now): array
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');

        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        $utc = (new DateTimeImmutable('@'.$now->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $utc->format('Ymd\THis\Z');
        $date = $utc->format('Ymd');

        $headers = ['Host' => $host, ...$headers, 'X-Amz-Date' => $amzDate];

        $canonical = [];

        foreach ($headers as $name => $value) {
            $canonical[strtolower(trim($name))] = trim((string) preg_replace('/\s+/', ' ', $value));
        }

        ksort($canonical, SORT_STRING);

        $signedHeaders = implode(';', array_keys($canonical));
        $canonicalHeaders = '';

        foreach ($canonical as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalUri((string) ($parts['path'] ?? '/')),
            $this->canonicalQuery((string) ($parts['query'] ?? '')),
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $body),
        ]);

        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4'.$this->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        unset($headers['Host']);

        return [
            ...$headers,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    private function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return implode('/', array_map(fn (string $segment) => rawurlencode(rawurldecode($segment)), explode('/', $path)));
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
        }

        usort($pairs, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn (array $pair) => "{$pair[0]}={$pair[1]}", $pairs));
    }
}
