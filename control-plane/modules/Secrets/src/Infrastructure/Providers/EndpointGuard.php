<?php

namespace Falak\Secrets\Infrastructure\Providers;

/**
 * Keeps provider requests away from the control plane's own network (SSRF): an endpoint must resolve only to
 * public addresses. A provider with "allow private network" (a self-hosted Vault or Infisical on the LAN) may
 * also reach private, loopback and CGNAT ranges. Link-local and cloud metadata addresses (169.254.169.254 and
 * friends) are refused always.
 *
 * Checked when a provider is saved and before every request; the request is then pinned to the addresses
 * checked here, so a DNS answer that changes in between (rebinding) can't redirect it.
 */
class EndpointGuard
{
    /** Never reachable, whatever the provider allows. */
    private const BLOCKED = [
        '0.0.0.0/8', '169.254.0.0/16', '100.100.100.200/32', '192.0.0.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', 'fe80::/10', 'fd00:ec2::254/128', 'ff00::/8',
    ];

    /** Reachable only with "allow private network". */
    private const PRIVATE = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '127.0.0.0/8', '198.18.0.0/15',
        '::1/128', 'fc00::/7',
    ];

    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param  callable(string): list<string>|null  $resolver  host → IP addresses
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /**
     * @return list<string> the addresses the host resolves to (all allowed)
     *
     * @throws ProviderFailure when the URL is not https, does not resolve, or reaches a refused address
     */
    public function check(string $url, bool $allowPrivate): array
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new ProviderFailure('Provider endpoints must use https://.');
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($host === '') {
            throw new ProviderFailure('The provider endpoint has no host.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            throw new ProviderFailure("The provider host {$host} does not resolve.");
        }

        foreach ($addresses as $address) {
            $refusal = $this->refusal($address, $allowPrivate);

            if ($refusal !== null) {
                throw new ProviderFailure("The provider host {$host} resolves to {$refusal}.");
            }
        }

        return array_values($addresses);
    }

    private function refusal(string $address, bool $allowPrivate): ?string
    {
        // IPv4-mapped IPv6 (::ffff:169.254.169.254) is checked as the IPv4 address it is.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $m) === 1) {
            $address = $m[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false || self::within($address, self::BLOCKED)) {
            return 'a link-local, metadata or reserved address, which is never allowed';
        }

        if ($allowPrivate) {
            return null;
        }

        if (self::within($address, self::PRIVATE) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'a private or reserved address (turn on "allow private network" for a self-hosted provider)';
        }

        return null;
    }

    /**
     * @param  list<string>  $ranges
     */
    private static function within(string $address, array $ranges): bool
    {
        $ip = inet_pton($address);

        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $net = inet_pton($network);

            if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
                continue;
            }

            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;

            if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }

            if ($rest === 0 || ((ord($ip[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
