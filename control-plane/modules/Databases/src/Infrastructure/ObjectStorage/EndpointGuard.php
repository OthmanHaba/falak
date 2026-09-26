<?php

namespace Kiln\Databases\Infrastructure\ObjectStorage;

/**
 * Keeps control-plane storage requests (verification probe, pruning) away from internal services:
 * a storage endpoint must resolve only to public addresses unless the instance explicitly allows
 * private ones (`databases.allow_private_endpoints`, e.g. a MinIO on the same LAN).
 *
 * Checked when saving a provider and again right before every request (DNS may change in between).
 */
final class EndpointGuard
{
    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param  callable(string): list<string>|null  $resolver  host → IP addresses
     */
    public function __construct(private readonly bool $allowPrivate = false, ?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /**
     * @return string|null why the URL is refused, or null when it is allowed
     */
    public function refusal(string $url): ?string
    {
        if ($this->allowPrivate) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = trim($host, '[]');

        if ($host === '') {
            return 'The storage endpoint has no host.';
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            return "The storage endpoint host \"{$host}\" does not resolve.";
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return "The storage endpoint resolves to a private or reserved address ({$address}).";
            }
        }

        return null;
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
