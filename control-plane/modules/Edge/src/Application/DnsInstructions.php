<?php

namespace Kiln\Edge\Application;

use Kiln\Edge\Contracts\Data\DnsTarget;

/**
 * The DNS records a user adds at their DNS provider for a custom domain.
 */
final class DnsInstructions
{
    public const TTL = 300;

    /**
     * Second-level public suffixes common enough to matter for apex detection (example.co.uk is an apex).
     */
    private const TWO_LEVEL_SUFFIXES = [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'co.nz', 'org.nz', 'net.nz',
        'co.jp', 'ne.jp', 'or.jp', 'co.kr', 'or.kr', 'com.br', 'net.br', 'org.br', 'com.ar', 'com.mx',
        'co.za', 'org.za', 'co.in', 'net.in', 'org.in', 'firm.in', 'com.cn', 'net.cn', 'org.cn', 'com.hk', 'com.sg',
        'com.tr', 'com.tw', 'co.il', 'co.id', 'com.my', 'com.ph', 'com.pl', 'com.ua', 'co.th', 'com.vn', 'com.eg',
    ];

    /**
     * @param  list<DnsTarget>  $targets  {@see DnsTargets}
     * @param  ?string  $generated  the site's generated name, offered as a CNAME target for subdomains
     * @param  ?string  $managedZone  the Cloudflare zone Kiln manages the records in (nothing to add by hand)
     * @return array{name: string, zone: string, host: string, apex: bool, ttl: int, records: list<array{type: string, name: string, host: string, value: string, target: string}>, alternative: ?array{type: string, name: string, host: string, value: string}, notes: list<string>}
     */
    public static function for(string $name, array $targets, ?string $generated = null, ?string $managedZone = null): array
    {
        [$zone, $host] = self::split($name);
        $apex = $host === '@';
        $records = [];

        foreach ($targets as $target) {
            foreach (['A' => $target->ipv4, 'AAAA' => $target->ipv6] as $type => $address) {
                if ($address !== null) {
                    $records[] = ['type' => $type, 'name' => $name, 'host' => $host, 'value' => $address, 'target' => $target->name];
                }
            }
        }

        $notes = [];
        $servers = array_values(array_filter($targets, fn (DnsTarget $target) => ! $target->loadBalancer));

        if ($targets === []) {
            $notes[] = 'Pick the server the site runs on to see the records.';
        } elseif (array_filter($targets, fn (DnsTarget $target) => $target->ipv4 !== null) === []) {
            $notes[] = 'The server has no public IPv4 address yet (it appears once its agent reports in).';
        }

        if (count($servers) > 1) {
            $notes[] = 'One A record per server spreads visitors across them (DNS round-robin, no health checks). Put a load balancer in front for failover; then point the domain at the load balancer only.';
        }

        if ($records !== [] && array_filter($records, fn (array $record) => $record['type'] === 'AAAA') === []) {
            $notes[] = 'Remove any existing AAAA record for this name: Let\'s Encrypt prefers IPv6 and fails if it points elsewhere.';
        }

        if ($apex) {
            $notes[] = 'This is the zone apex: use A/AAAA records (most DNS providers do not allow a CNAME here; ALIAS/ANAME work where offered).';
        }

        if ($managedZone !== null) {
            // Kiln creates these records itself and gets the certificate over DNS-01, proxied or not.
            $notes = ["Kiln manages these records in Cloudflare ({$managedZone}): nothing to add by hand. The certificate is issued over DNS, so the orange cloud is fine."];
        } else {
            $notes[] = 'Cloudflare: set the record to “DNS only” (grey cloud) until the certificate is issued, so Let\'s Encrypt reaches the server over HTTP on port 80.';
        }

        $alternative = ! $apex && $generated !== null && count($targets) === 1
            ? ['type' => 'CNAME', 'name' => $name, 'host' => $host, 'value' => $generated]
            : null;

        return [
            'name' => $name,
            'zone' => $zone,
            'host' => $host,
            'apex' => $apex,
            'ttl' => self::TTL,
            'records' => $records,
            'alternative' => $alternative,
            'notes' => $notes,
            'managed_by' => $managedZone !== null ? ['provider' => 'cloudflare', 'zone' => $managedZone] : null,
        ];
    }

    /**
     * @return array{0: string, 1: string} [zone, host relative to it ("@" for the apex)]
     */
    public static function split(string $name): array
    {
        $labels = explode('.', strtolower(trim($name, '.')));
        $suffixLabels = count($labels) >= 3 && in_array(implode('.', array_slice($labels, -2)), self::TWO_LEVEL_SUFFIXES, true) ? 3 : 2;

        if (count($labels) <= $suffixLabels) {
            return [implode('.', $labels), '@'];
        }

        return [implode('.', array_slice($labels, -$suffixLabels)), implode('.', array_slice($labels, 0, -$suffixLabels))];
    }
}
