<?php

namespace Kiln\Edge\Application;

use Illuminate\Support\Facades\Log;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\DnsRecord;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\TargetRole;

/**
 * Keeps Cloudflare DNS in line with a domain: one A / AAAA record per server the domain points at (the load balancer
 * of a load-balanced site), for the domain and its www redirect host, proxied per the domain or its zone.
 *
 * Kiln only changes records it created (edge_dns_records, tagged `kiln:<domain id>` in Cloudflare). A record of the
 * same name that Kiln did not create is a conflict: it is reported, never overwritten. The panel and agent API
 * hosts are never managed (agents authenticate with mTLS, which Cloudflare's proxy would terminate).
 */
final class CloudflareDns
{
    private const TYPES = ['A', 'AAAA', 'CNAME'];

    public function __construct(
        private readonly DnsTargets $targets,
        private readonly SiteDirectory $sites,
    ) {}

    public function syncSite(string $siteId): void
    {
        foreach (Domain::query()->where('site_id', $siteId)->get() as $domain) {
            $this->sync($domain);
        }
    }

    public function sync(Domain $domain): void
    {
        $zone = CloudflareZone::forHost($domain->organization_id, $domain->name);
        $desired = $zone !== null ? $this->desired($domain, $zone) : [];
        $tracked = DnsRecord::query()->with('zone.credential')->where('domain_id', $domain->id)->get();

        foreach ($tracked as $record) {
            if (! isset($desired[self::key($record->zone_id, $record->name, $record->type, $record->content)])) {
                $this->remove($record);
            }
        }

        if ($zone === null) {
            return;
        }

        $api = CloudflareApi::with($zone->credential->api_token);
        $remoteByName = [];

        foreach ($desired as $key => $want) {
            $record = $tracked->first(fn (DnsRecord $r) => self::key($r->zone_id, $r->name, $r->type, $r->content) === $key && $r->exists)
                ?? new DnsRecord(['organization_id' => $domain->organization_id, 'domain_id' => $domain->id, 'zone_id' => $zone->id, ...$want]);

            try {
                if ($record->record_id !== null) {
                    if ($record->proxied !== $want['proxied'] || $record->status !== DnsRecord::SYNCED) {
                        $api->updateRecord($zone->zone_id, $record->record_id, ['proxied' => $want['proxied'], 'comment' => DnsRecord::comment($domain->id)]);
                    }
                    $record->forceFill(['proxied' => $want['proxied'], 'status' => DnsRecord::SYNCED, 'error' => null, 'synced_at' => now()])->save();

                    continue;
                }

                $remote = $remoteByName[$want['name']] ??= $api->records($zone->zone_id, $want['name']);
                $ours = collect($remote)->first(fn (array $r) => $r['type'] === $want['type'] && $r['content'] === $want['content'] && str_starts_with((string) ($r['comment'] ?? ''), 'kiln:'.$domain->id));
                $foreign = collect($remote)->first(fn (array $r) => in_array($r['type'], self::TYPES, true) && ! str_starts_with((string) ($r['comment'] ?? ''), 'kiln:'.$domain->id));

                if ($ours !== null) {
                    $record->forceFill(['record_id' => (string) $ours['id']]);
                    if ((bool) $ours['proxied'] !== $want['proxied']) {
                        $api->updateRecord($zone->zone_id, (string) $ours['id'], ['proxied' => $want['proxied']]);
                    }
                } elseif ($foreign !== null) {
                    $record->forceFill(['status' => DnsRecord::CONFLICT, 'error' => "{$want['name']} already has a {$foreign['type']} record ({$foreign['content']}) that Kiln did not create. Delete it in Cloudflare, then click Sync.", 'synced_at' => now()])->save();

                    continue;
                } else {
                    $created = $api->createRecord($zone->zone_id, [...$want, 'comment' => DnsRecord::comment($domain->id)]);
                    $record->forceFill(['record_id' => (string) $created['id']]);
                    $remoteByName[$want['name']][] = $created;
                }

                $record->forceFill(['status' => DnsRecord::SYNCED, 'error' => null, 'synced_at' => now()])->save();
            } catch (CloudflareError $e) {
                $record->forceFill(['status' => DnsRecord::ERROR, 'error' => $e->getMessage(), 'synced_at' => now()])->save();
                Log::warning('edge: cloudflare dns sync failed', ['domain' => $domain->name, 'error' => $e->getMessage()]);
            }
        }
    }

    /** A domain was removed: delete the records Kiln created for it. */
    public function forget(string $domainId): void
    {
        foreach (DnsRecord::query()->with('zone.credential')->where('domain_id', $domainId)->get() as $record) {
            $this->remove($record);
        }
    }

    /**
     * @return array<string, array{name: string, type: string, content: string, proxied: bool}>
     */
    private function desired(Domain $domain, CloudflareZone $zone): array
    {
        $site = $this->sites->find($domain->site_id);

        if ($site === null) {
            return [];
        }

        $targets = $site->targets;
        usort($targets, fn ($a, $b) => ($b->role === TargetRole::Leader) <=> ($a->role === TargetRole::Leader));
        $pointsAt = $this->targets->for($domain->organization_id, array_map(fn ($t) => $t->serverId, $targets), $site->id);
        $proxied = $domain->cloudflare_proxied ?? $zone->proxied;
        $out = [];

        foreach ($domain->hosts() as $host) {
            if (! $zone->covers($host) || self::reserved($host)) {
                continue;
            }

            foreach ($pointsAt as $target) {
                foreach (['A' => $target->ipv4, 'AAAA' => $target->ipv6] as $type => $address) {
                    if ($address !== null && $address !== '') {
                        $out[self::key($zone->id, $host, $type, $address)] = ['name' => $host, 'type' => $type, 'content' => $address, 'proxied' => $proxied];
                    }
                }
            }
        }

        return $out;
    }

    private function remove(DnsRecord $record): void
    {
        try {
            if ($record->record_id !== null && $record->zone?->credential !== null) {
                CloudflareApi::with($record->zone->credential->api_token)->deleteRecord($record->zone->zone_id, $record->record_id);
            }
            $record->delete();
        } catch (CloudflareError $e) {
            $record->forceFill(['status' => DnsRecord::ERROR, 'error' => 'Could not delete: '.$e->getMessage()])->save();
        }
    }

    /** The panel and agent API hosts stay out of Kiln's hands. */
    public static function reserved(string $host): bool
    {
        $hosts = array_filter(array_map(fn ($url) => is_string($url) ? parse_url($url, PHP_URL_HOST) : null, [config('app.url'), config('fleet.panel_url'), config('fleet.api_url')]));

        return in_array(strtolower($host), array_map('strtolower', $hosts), true);
    }

    private static function key(string $zoneId, string $name, string $type, string $content): string
    {
        return "{$zoneId}|{$name}|{$type}|{$content}";
    }
}
