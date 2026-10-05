<?php

namespace Falak\Templates\Infrastructure;

use Falak\Templates\Application\Import\FetchFailed;
use Falak\Templates\Application\Import\HostResolver;
use Falak\Templates\Application\Import\RemoteFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * SSRF-guarded GET: https only, no credentials in the URL, every address the host resolves to must be public
 * (no private, loopback, link-local, CGNAT or reserved ranges), the connection is pinned to the checked address
 * (no DNS rebinding), redirects are followed manually and re-checked, and the body is capped at `max_bytes`.
 */
final class GuardedHttpFetcher implements RemoteFetcher
{
    public function __construct(
        private readonly Http $http,
        private readonly HostResolver $resolver,
        private readonly int $maxBytes,
        private readonly int $timeout = 10,
        private readonly int $maxRedirects = 3,
    ) {}

    public function fetch(string $url): string
    {
        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            [$host, $port, $ip] = $this->check($url);

            try {
                $response = $this->http
                    ->withOptions([
                        'stream' => true,
                        'allow_redirects' => false,
                        'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                    ])
                    ->withHeaders(['Accept' => 'application/yaml, text/yaml, text/plain, */*'])
                    ->connectTimeout(5)
                    ->timeout($this->timeout)
                    ->get($url);
            } catch (ConnectionException) {
                throw new FetchFailed('Could not connect to '.$host.'.');
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new FetchFailed('The server redirected without a location.');
                }

                $url = self::absolute($url, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new FetchFailed("The server answered HTTP {$response->status()}.");
            }

            $length = $response->header('Content-Length');

            if ($length !== '' && (int) $length > $this->maxBytes) {
                throw new FetchFailed('The file is larger than '.intdiv($this->maxBytes, 1024).' KB.');
            }

            $body = $response->toPsrResponse()->getBody();
            $content = '';

            while (! $body->eof()) {
                $content .= $body->read(8192);

                if (strlen($content) > $this->maxBytes) {
                    throw new FetchFailed('The file is larger than '.intdiv($this->maxBytes, 1024).' KB.');
                }
            }

            if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
                throw new FetchFailed('The file is not UTF-8 text.');
            }

            return $content;
        }

        throw new FetchFailed('Too many redirects.');
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port and the public address to connect to
     */
    private function check(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new FetchFailed('Only https:// URLs can be imported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchFailed('URLs with credentials are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? 443);
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw new FetchFailed("{$host} does not resolve.");
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new FetchFailed("{$host} resolves to a private or reserved address.");
            }
        }

        return [$host, $port, $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        // IPv4-mapped / -compatible IPv6 (::ffff:127.0.0.1) is checked as the IPv4 address it carries.
        if (preg_match('/^::(ffff:)?(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            $ip = $m[2];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        // Ranges FILTER_FLAG_GLOBAL_RANGE does not cover on every PHP build.
        foreach (['100.64.0.0/10', '198.18.0.0/15', '0.0.0.0/8', '224.0.0.0/4', 'ff00::/8', 'fc00::/7', 'fe80::/10', '64:ff9b::/96', '2002::/16'] as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        return $rest === 0 || ((ord($ipBin[$bytes]) ^ ord($subnetBin[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base) ?: [];
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';

        return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$location;
    }
}
