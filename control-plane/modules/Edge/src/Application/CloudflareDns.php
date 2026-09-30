<?php

namespace Kiln\Edge\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kiln\Edge\Domain\Models\CloudflareTunnel;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\DnsRecord;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;

/**
 * Keeps Cloudflare DNS in line with a site's names: one A / AAAA record per server the name points at (the load
 * balancer of a load-balanced site), for each domain and its www redirect host, and for the domains of a compose
 * site's public services; proxied per the domain or its zone.
 *
 * Kiln only changes records it created (edge_dns_records, tagged `kiln:<domain id>` or `kiln:site:<site id>` in
 * Cloudflare). A record of the same name that Kiln did not create is a conflict: it is reported, never overwritten.
 * The panel and agent API hosts are never managed (agents authenticate with mTLS, which the proxy would terminate).
 */
final class CloudflareDns
{
    private const TYPES = ['A', 'AAAA', 'CNAME'];

    public function __construct(
        private readonly DnsTargets $targets,
        private readonly SiteDirectory $sites,
    ) {}

    /** Every name of a site: its domains, and its compose public services' domains. */
    public function syncSite(string $siteId): void
    {
        foreach (Domain::query()->where('site_id', $siteId)->get() as $domain) {
            $this->sync($domain);
        }

        $site = $this->sites->find($siteId);
        $hosts = $site?->runtime === SiteRuntime::Compose && $site->compose !== null
            ? array_values(array_filter(array_map(fn ($public) => $public->domain, $site->compose->publicServices)))
            : [];

        $this->reconcile(
            $site?->organizationId,
            ['site_id' => $siteId, 'domain_id' => null],
            'kiln:site:'.$siteId,
            $site !== null ? $this->desired($site->organizationId, $site, $hosts, null) : [],
        );
    }

    public function sync(Domain $domain): void
    {
        $site = $this->sites->find($domain->site_id);

        $this->reconcile(
            $domain->organization_id,
            ['domain_id' => $domain->id],
            'kiln:'.$domain->id,
            $site !== null ? $this->desired($domain->organization_id, $site, $domain->hosts(), $domain->cloudflare_proxied) : [],
        );
    }

    /** A domain was removed: delete the records Kiln created for it. */
    public function forget(string $domainId): void
    {
        $this->reconcile(null, ['domain_id' => $domainId], 'kiln:'.$domainId, []);
    }

    /** A site was deleted: delete the records of its compose public services. */
    public function forgetSite(string $siteId): void
    {
        $this->reconcile(null, ['site_id' => $siteId, 'domain_id' => null], 'kiln:site:'.$siteId, []);
    }

    /**
     * Brings Cloudflare in line with $desired for one owner (a domain, or a site's compose hosts).
     *
     * @param  ?string  $organizationId  null for cleanup (read from the tracked rows)
     * @param  array<string, ?string>  $owner  edge_dns_records columns identifying the owner
     * @param  array<string, array{zone: CloudflareZone, name: string, type: string, content: string, proxied: bool}>  $desired
     */
    private function reconcile(?string $organizationId, array $owner, string $tag, array $desired): void
    {
        // Cleanup of a removed owner: its organization is on the rows it left (nothing tracked, nothing to do).
        $organizationId ??= DnsRecord::query()->where($owner)->value('organization_id');

        if ($organizationId === null) {
            return;
        }

        // One sync per organization at a time: the queue's uniqueness is released when a job starts, and a domain job
        // and a site job can overlap; two concurrent runs would both create the same record. The wait stays under the
        // worker timeout (60 s); a job that cannot get the lock is retried by the queue.
        Cache::lock("edge:cloudflare-dns:{$organizationId}", 120)->block(45, fn () => $this->reconcileLocked($owner, $tag, $desired));
    }

    /**
     * @param  array<string, ?string>  $owner
     * @param  array<string, array{zone: CloudflareZone, name: string, type: string, content: string, proxied: bool}>  $desired
     */
    private function reconcileLocked(array $owner, string $tag, array $desired): void
    {
        $tracked = DnsRecord::query()->with('zone.credential')->where($owner)->get();

        foreach ($tracked as $record) {
            if (! isset($desired[self::key($record->zone_id, $record->name, $record->type, $record->content)])) {
                $this->remove($record);
            }
        }

        $remoteByName = [];
        $comment = $tag.' (managed by Kiln; edits are overwritten)';

        foreach ($desired as $key => $want) {
            $zone = $want['zone'];
            $fields = ['name' => $want['name'], 'type' => $want['type'], 'content' => $want['content'], 'proxied' => $want['proxied']];
            $record = $tracked->first(fn (DnsRecord $r) => $r->exists && self::key($r->zone_id, $r->name, $r->type, $r->content) === $key)
                ?? new DnsRecord(['organization_id' => $zone->organization_id, 'zone_id' => $zone->id, ...$owner, ...$fields]);
            $api = CloudflareApi::with($zone->credential->api_token);

            try {
                if ($record->record_id !== null) {
                    if ($record->proxied !== $want['proxied'] || $record->status !== DnsRecord::SYNCED) {
                        $api->updateRecord($zone->zone_id, $record->record_id, ['proxied' => $want['proxied'], 'comment' => $comment]);
                    }
                    $record->forceFill(['proxied' => $want['proxied'], 'status' => DnsRecord::SYNCED, 'error' => null, 'synced_at' => now()])->save();

                    continue;
                }

                $remote = $remoteByName[$zone->id.'|'.$want['name']] ??= $api->records($zone->zone_id, $want['name']);
                $mine = fn (array $r) => str_starts_with((string) ($r['comment'] ?? ''), $tag.' ') || ($r['comment'] ?? '') === $tag;
                $ours = collect($remote)->first(fn (array $r) => $r['type'] === $want['type'] && $r['content'] === $want['content'] && $mine($r));
                $foreign = collect($remote)->first(fn (array $r) => in_array($r['type'], self::TYPES, true) && ! $mine($r));

                if ($ours !== null) {
                    $record->forceFill(['record_id' => (string) $ours['id']]);
                    if ((bool) $ours['proxied'] !== $want['proxied']) {
                        $api->updateRecord($zone->zone_id, (string) $ours['id'], ['proxied' => $want['proxied']]);
                    }
                } elseif ($foreign !== null) {
                    $record->forceFill(['status' => DnsRecord::CONFLICT, 'error' => "{$want['name']} already has a {$foreign['type']} record ({$foreign['content']}) that Kiln did not create. Delete it in Cloudflare, then click Sync.", 'synced_at' => now()])->save();

                    continue;
                } else {
                    $created = $api->createRecord($zone->zone_id, [...$fields, 'comment' => $comment]);
                    $record->forceFill(['record_id' => (string) $created['id']]);
                    $remoteByName[$zone->id.'|'.$want['name']][] = $created;
                }

                $record->forceFill(['status' => DnsRecord::SYNCED, 'error' => null, 'synced_at' => now()])->save();
            } catch (CloudflareError $e) {
                $record->forceFill(['status' => DnsRecord::ERROR, 'error' => $e->getMessage(), 'synced_at' => now()]);
                $this->saveOrConflict($record, $want['name']);
                Log::warning('edge: cloudflare dns sync failed', ['name' => $want['name'], 'error' => $e->getMessage()]);
            } catch (UniqueConstraintViolationException) {
                // Another domain of the organization wants the same record (the host check raced): keep the first.
                Log::warning('edge: cloudflare dns record already tracked for another owner', ['name' => $want['name']]);
            }
        }
    }

    /**
     * @param  list<string>  $hosts
     * @return array<string, array{zone: CloudflareZone, name: string, type: string, content: string, proxied: bool}>
     */
    private function desired(string $organizationId, SiteData $site, array $hosts, ?bool $override): array
    {
        $targets = $site->targets;
        usort($targets, fn ($a, $b) => ($b->role === TargetRole::Leader) <=> ($a->role === TargetRole::Leader));
        $pointsAt = $this->targets->for($organizationId, array_map(fn ($t) => $t->serverId, $targets), $site->id);
        $zones = self::zones($organizationId);
        $tunnels = CloudflareTunnel::query()->where('organization_id', $organizationId)->whereIn('server_id', array_map(fn ($t) => $t->serverId, $pointsAt))->get()->keyBy('server_id');
        $out = [];

        foreach ($hosts as $host) {
            $zone = $zones->filter(fn (CloudflareZone $z) => $z->covers($host))->sortByDesc(fn (CloudflareZone $z) => strlen($z->name))->first();

            if ($zone === null || self::reserved($host)) {
                continue;
            }

            // Through a tunnel (the first target that has one, leader first): one proxied CNAME, no address records
            // (a name cannot have both). The tunnel must belong to the zone's Cloudflare account.
            $tunnel = collect($pointsAt)->map(fn ($t) => $tunnels->get($t->serverId))->filter()
                ->first(fn (CloudflareTunnel $t) => $t->account_id === $zone->credential->account_id);

            if ($tunnel !== null) {
                $out[self::key($zone->id, $host, 'CNAME', $tunnel->hostname())] = ['zone' => $zone, 'name' => $host, 'type' => 'CNAME', 'content' => $tunnel->hostname(), 'proxied' => true];

                continue;
            }

            foreach ($pointsAt as $target) {
                foreach (['A' => $target->ipv4, 'AAAA' => $target->ipv6] as $type => $address) {
                    if ($address !== null && $address !== '') {
                        $out[self::key($zone->id, $host, $type, $address)] = ['zone' => $zone, 'name' => $host, 'type' => $type, 'content' => $address, 'proxied' => $override ?? $zone->proxied];
                    }
                }
            }
        }

        return $out;
    }

    /** @return Collection<int, CloudflareZone> */
    private static function zones(string $organizationId): Collection
    {
        return CloudflareZone::query()->with('credential')->where('organization_id', $organizationId)->get();
    }

    private function saveOrConflict(DnsRecord $record, string $name): void
    {
        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            Log::warning('edge: cloudflare dns record already tracked for another owner', ['name' => $name]);
        }
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
