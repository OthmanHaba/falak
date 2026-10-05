<?php

namespace Falak\Network\Domain\Support;

/**
 * Strict validation of firewall rule ports and sources.
 */
final class AddressRules
{
    /** "22" or "8000-8100", 1..65535, start ≤ end. */
    public static function isPort(string $port): bool
    {
        if (! preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $port, $m)) {
            return false;
        }

        $start = (int) $m[1];
        $end = isset($m[2]) ? (int) $m[2] : $start;

        return $start >= 1 && $end <= 65535 && $start <= $end && (string) $start === $m[1] && (! isset($m[2]) || (string) $end === $m[2]);
    }

    /** IPv4/IPv6 address, or CIDR with a prefix valid for its family (host bits may be set; nftables masks them). */
    public static function isSource(string $source): bool
    {
        [$address, $prefix] = array_pad(explode('/', $source, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $max = 32;
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $max = 128;
        } else {
            return false;
        }

        return $prefix === null || (preg_match('/^\d{1,3}$/', $prefix) === 1 && (int) $prefix <= $max && (string) (int) $prefix === $prefix);
    }
}
