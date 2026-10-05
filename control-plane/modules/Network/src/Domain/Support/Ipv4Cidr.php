<?php

namespace Falak\Network\Domain\Support;

use InvalidArgumentException;

/**
 * An IPv4 network in CIDR notation, normalized to its network address (10.90.0.7/24 → 10.90.0.0/24).
 */
final readonly class Ipv4Cidr
{
    private function __construct(public int $network, public int $prefix) {}

    public static function parse(string $cidr): self
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($cidr), $m)
            || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || (int) $m[2] > 32) {
            throw new InvalidArgumentException("Invalid IPv4 CIDR [{$cidr}].");
        }

        $prefix = (int) $m[2];
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;

        return new self(((int) ip2long($m[1])) & $mask, $prefix);
    }

    public static function isValid(string $cidr): bool
    {
        try {
            self::parse($cidr);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function size(): int
    {
        return 2 ** (32 - $this->prefix);
    }

    public function broadcast(): int
    {
        return $this->network + $this->size() - 1;
    }

    public function contains(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && $long >= $this->network && $long <= $this->broadcast();
    }

    /**
     * Lowest usable host address not in $taken (network and broadcast addresses are never handed out).
     *
     * @param  list<string>  $taken
     */
    public function firstFree(array $taken): ?string
    {
        $used = array_flip(array_filter(array_map(fn (string $ip) => ip2long($ip), $taken), 'is_int'));

        for ($candidate = $this->network + 1; $candidate < $this->broadcast(); $candidate++) {
            if (! isset($used[$candidate])) {
                return (string) long2ip($candidate);
            }
        }

        return null;
    }

    public function overlaps(self $other): bool
    {
        return $this->network <= $other->broadcast() && $other->network <= $this->broadcast();
    }

    public function __toString(): string
    {
        return long2ip($this->network).'/'.$this->prefix;
    }
}
