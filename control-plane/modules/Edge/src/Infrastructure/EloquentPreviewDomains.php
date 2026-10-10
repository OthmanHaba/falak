<?php

namespace Falak\Edge\Infrastructure;

use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\Actions\AddSecurityRule;
use Falak\Edge\Contracts\Data\PreviewDomainData;
use Falak\Edge\Contracts\EdgeRoutes;
use Falak\Edge\Contracts\PreviewDomains;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\PreviewDomain;
use Falak\Edge\Domain\Models\PreviewRecord;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EloquentPreviewDomains implements PreviewDomains
{
    /** Tag of the records Falak creates for previews (comments in Cloudflare). */
    public const TAG = 'falak:preview';

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly SiteDirectory $sites,
        private readonly EdgeRoutes $routes,
        private readonly AuditLog $audit,
    ) {}

    public function settings(): ?PreviewDomainData
    {
        return PreviewDomain::current()?->toData();
    }

    public function configure(string $organizationId, string $domain, ?string $dnsCredentialId, string $serverId, ?string $actorId = null): PreviewDomainData
    {
        $domain = strtolower(trim(rtrim(trim($domain), '.')));

        if (preg_match(Domain::HOSTNAME, $domain) !== 1 || str_starts_with($domain, '*.') || substr_count($domain, '.') < 1 || strlen($domain) > 190) {
            throw ValidationException::withMessages(['domain' => 'Enter a domain such as prv.example.com (previews are served as pr-1-web.prv.example.com).']);
        }

        $server = $this->servers->find(strtolower($serverId));

        if ($server === null || $server->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['server_id' => 'Pick a server of this organization.']);
        }

        if ($server->ipv4 === null || $server->ipv4 === '') {
            throw ValidationException::withMessages(['server_id' => 'The preview edge server needs a public IPv4 address.']);
        }

        $credential = $dnsCredentialId !== null
            ? DnsCredential::query()->where('organization_id', $organizationId)->find(strtolower($dnsCredentialId))
                ?? throw ValidationException::withMessages(['dns_credential_id' => 'Pick a DNS credential of this organization.'])
            : null;

        if (Domain::query()->where(fn ($q) => $q->where('name', $domain)->orWhere('name', 'like', '%.'.$domain))->exists()) {
            throw ValidationException::withMessages(['domain' => 'Sites already use names under this domain: pick a domain only previews use.']);
        }

        $current = PreviewDomain::current();
        $previousServer = $current?->server_id;

        if ($current !== null && ($current->domain !== $domain || $current->dns_credential_id !== $credential?->id)) {
            $this->deleteWildcard($current);
        }

        $preview = $current ?? new PreviewDomain;
        $preview->forceFill([
            'organization_id' => $organizationId,
            'domain' => $domain,
            'dns_credential_id' => $credential?->id,
            'server_id' => $server->id,
            'status' => $credential?->provider === 'cloudflare' ? 'pending' : 'manual',
            'error' => null,
            'created_by' => $current?->created_by ?? $actorId,
        ])->save();
        $preview->load('dnsCredential');

        if ($preview->managed()) {
            $this->syncWildcard($preview, $server->ipv4);
        }

        $this->audit->record('edge.preview_domain_configured', 'preview_domain', $preview->id, [
            'domain' => $domain,
            'server_id' => $server->id,
            'dns' => $credential?->provider,
            'status' => $preview->status,
        ], $organizationId);

        $this->routes->schedule(...array_values(array_unique(array_filter([$server->id, $previousServer]))));

        return $preview->refresh()->toData();
    }

    public function clear(): void
    {
        $preview = PreviewDomain::current();

        if ($preview === null) {
            return;
        }

        $this->deleteWildcard($preview);
        $preview->delete();
        $this->routes->schedule($preview->server_id);
        $this->audit->record('edge.preview_domain_cleared', 'preview_domain', $preview->id, ['domain' => $preview->domain], $preview->organization_id);
    }

    public function route(string $siteId, string $host, ?string $service = null): void
    {
        $preview = PreviewDomain::current() ?? throw ValidationException::withMessages(['domain' => 'No preview domain is configured (Settings → Previews).']);
        $site = $this->sites->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);
        $host = strtolower($host);

        if (! $preview->covers($host)) {
            throw ValidationException::withMessages(['domain' => "{$host} is not a name directly under {$preview->domain}."]);
        }

        $serverId = $site->leader()?->serverId ?? ($site->serverIds()[0] ?? null);
        $server = $serverId !== null ? $this->servers->find($serverId) : null;

        if ($server === null) {
            throw ValidationException::withMessages(['server_id' => 'The preview has no server.']);
        }

        // Off the edge server, the wildcard record points elsewhere: the host needs its own record.
        $needsRecord = $server->id !== $preview->server_id && ! $this->orgZoneCovers($site->organizationId, $host);

        if ($needsRecord && ! $preview->managed()) {
            throw ValidationException::withMessages(['server_id' => 'Previews run on the preview edge server unless Falak manages the preview domain\'s DNS (Cloudflare).']);
        }

        if ($needsRecord && ($server->ipv4 === null || $server->ipv4 === '')) {
            throw ValidationException::withMessages(['server_id' => "{$server->name} has no public IPv4 address for the preview's DNS record."]);
        }

        app(AddDomain::class)($site, $host, TlsMode::Auto, WwwRedirect::None, service: $service, preview: true);

        if ($needsRecord) {
            $this->pointHost($preview, $site->organizationId, $site->id, $host, (string) $server->ipv4);
        }
    }

    public function release(string $siteId): void
    {
        $preview = PreviewDomain::current();

        foreach (PreviewRecord::query()->where('site_id', strtolower($siteId))->get() as $record) {
            try {
                if ($preview?->managed() && $record->record_id !== null) {
                    CloudflareApi::with($preview->dnsCredential->api_token)->deleteRecord($record->zone_id, $record->record_id);
                }
                $record->delete();
            } catch (CloudflareError $e) {
                Log::warning('edge: preview record not deleted', ['host' => $record->host, 'error' => $e->getMessage()]);
            }
        }
    }

    public function available(string $host): bool
    {
        $host = strtolower($host);

        return ! Domain::query()->where('name', $host)->exists() && ! PreviewRecord::query()->where('host', $host)->exists();
    }

    public function protect(string $siteId, string $username, #[\SensitiveParameter] string $password): void
    {
        $site = $this->sites->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);

        app(AddSecurityRule::class)($site, 'Preview access', null, $username, $password);
    }

    /** `*.<domain>` → the edge server, in the zone of the credential that covers the domain. */
    private function syncWildcard(PreviewDomain $preview, string $ipv4): void
    {
        $api = CloudflareApi::with($preview->dnsCredential->api_token);
        $name = '*.'.$preview->domain;

        try {
            $zone = collect($api->zones())
                ->filter(fn (array $z) => $preview->domain === $z['name'] || str_ends_with($preview->domain, '.'.$z['name']))
                ->sortByDesc(fn (array $z) => strlen($z['name']))
                ->first() ?? throw new CloudflareError("The Cloudflare token can't see a zone for {$preview->domain}.", 404);
            $existing = collect($api->records($zone['id'], $name));
            $ours = $existing->first(fn (array $r) => str_starts_with((string) ($r['comment'] ?? ''), self::TAG));
            $foreign = $existing->first(fn (array $r) => ! str_starts_with((string) ($r['comment'] ?? ''), self::TAG) && in_array($r['type'] ?? '', ['A', 'AAAA', 'CNAME'], true));

            if ($foreign !== null) {
                throw new CloudflareError("{$name} already has a {$foreign['type']} record that Falak did not create: delete it in Cloudflare, then save again.", 409);
            }

            $record = $ours !== null
                ? $api->updateRecord($zone['id'], (string) $ours['id'], ['type' => 'A', 'content' => $ipv4, 'proxied' => false])
                : $api->createRecord($zone['id'], ['type' => 'A', 'name' => $name, 'content' => $ipv4, 'proxied' => false, 'comment' => self::TAG.' wildcard (managed by Falak)']);

            $preview->forceFill(['zone_id' => $zone['id'], 'record_id' => (string) $record['id'], 'status' => 'active', 'error' => null])->save();
        } catch (CloudflareError $e) {
            $preview->forceFill(['status' => 'error', 'error' => Str::limit($e->getMessage(), 990)])->save();
        }
    }

    private function deleteWildcard(PreviewDomain $preview): void
    {
        if (! $preview->managed() || $preview->zone_id === null || $preview->record_id === null) {
            return;
        }

        try {
            CloudflareApi::with($preview->dnsCredential->api_token)->deleteRecord($preview->zone_id, $preview->record_id);
        } catch (CloudflareError $e) {
            Log::warning('edge: preview wildcard record not deleted', ['domain' => $preview->domain, 'error' => $e->getMessage()]);
        }

        $preview->forceFill(['zone_id' => null, 'record_id' => null])->save();
    }

    /** An A record for one preview host on a server other than the edge server. */
    private function pointHost(PreviewDomain $preview, string $organizationId, string $siteId, string $host, string $ipv4): void
    {
        if ($preview->zone_id === null) {
            throw ValidationException::withMessages(['domain' => 'The preview domain\'s DNS is not set up yet: '.($preview->error ?? 'save Settings → Previews again.')]);
        }

        $record = PreviewRecord::query()->firstOrNew(['host' => $host]);
        $record->forceFill(['organization_id' => $organizationId, 'site_id' => $siteId, 'zone_id' => $preview->zone_id, 'content' => $ipv4]);

        try {
            $api = CloudflareApi::with($preview->dnsCredential->api_token);
            $created = $record->record_id !== null
                ? $api->updateRecord($preview->zone_id, $record->record_id, ['content' => $ipv4])
                : $api->createRecord($preview->zone_id, ['type' => 'A', 'name' => $host, 'content' => $ipv4, 'proxied' => false, 'comment' => self::TAG.':'.$siteId.' (managed by Falak)']);
            $record->forceFill(['record_id' => (string) $created['id']])->save();
        } catch (CloudflareError $e) {
            throw ValidationException::withMessages(['domain' => "The DNS record for {$host} could not be created: {$e->getMessage()}"]);
        }
    }

    /** The site's own organization manages DNS for the host (Edge's Cloudflare sync gives it a record already). */
    private function orgZoneCovers(string $organizationId, string $host): bool
    {
        return CloudflareZone::query()->where('organization_id', $organizationId)->get()->contains(fn (CloudflareZone $zone) => $zone->covers($host));
    }
}
