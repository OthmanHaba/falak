<?php

namespace Falak\Databases\Application;

/**
 * FALAK_DOCKER_NETWORKS: the Docker address ranges containers connect from. The agent refuses a db.user.apply whose
 * ranges are not canonical IPv4 networks, so the list is checked once, when the configuration loads: host bits are
 * cleared (172.16.0.1/12 → 172.16.0.0/12), entries that are not IPv4 CIDRs with a /8–/30 prefix (or that cover
 * 0.0.0.0/8 or loopback) are dropped, and when nothing valid is left Falak falls back to Docker's default pools.
 * Dropped entries are logged at boot (DatabasesServiceProvider). An empty value turns container access off.
 */
final class ContainerNetworks
{
    public const DEFAULT = ['172.16.0.0/12', '192.168.0.0/16'];

    /**
     * @return array{networks: list<string>, invalid: list<string>}
     */
    public static function parse(?string $value): array
    {
        $entries = array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $e) => $e !== ''));
        $networks = [];
        $invalid = [];

        foreach ($entries as $entry) {
            $network = self::canonical($entry);

            if ($network === null) {
                $invalid[] = $entry;
            } elseif (! in_array($network, $networks, true)) {
                $networks[] = $network;
            }
        }

        if ($entries !== [] && $networks === []) {
            $networks = self::DEFAULT;
        }

        return ['networks' => $networks, 'invalid' => $invalid];
    }

    /** "a.b.c.d/n" as its network address, or null when it is not a usable IPv4 range. */
    public static function canonical(string $cidr): ?string
    {
        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m) !== 1 || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $prefix = (int) $m[2];

        if ($prefix < 8 || $prefix > 30) {
            return null;
        }

        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $network = long2ip(ip2long($m[1]) & $mask);
        $first = (int) explode('.', $network)[0];

        return $first === 0 || $first === 127 ? null : "{$network}/{$prefix}";
    }
}
