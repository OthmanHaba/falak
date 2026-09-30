<?php

namespace Kiln\Edge\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Application\Jobs\SyncCloudflareDns;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\DnsCredential;
use Kiln\Edge\Domain\Models\DnsRecord;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\OrganizationSetting;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareApi;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Contracts\ServerDirectory;
use SensitiveParameter;

/**
 * Settings → Cloudflare: connect an API token, choose the zones Kiln manages, check and fix their TLS settings.
 *
 * A managed zone means: DNS records for its domains are created / updated / removed by Kiln (its own records only),
 * certificates come over DNS-01 with the connection, generated names may live under it, and every server of the
 * organization trusts Cloudflare's IP ranges for the visitor's address.
 */
final class CloudflareConnections
{
    /** Zone settings Kiln checks, with the value it recommends. */
    public const RECOMMENDED = ['ssl' => 'strict', 'min_tls_version' => '1.2'];

    public function __construct(
        private readonly CloudflareDns $dns,
        private readonly EdgeRoutes $routes,
        private readonly ServerDirectory $servers,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException when Cloudflare rejects the token or it sees no zone
     */
    public function connect(string $organizationId, string $name, #[SensitiveParameter] string $token, ?string $userId): DnsCredential
    {
        $token = trim($token);

        if (DnsCredential::query()->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'A connection with this name exists.']);
        }

        try {
            $api = CloudflareApi::with($token);
            if ($api->verify() !== 'active') {
                throw ValidationException::withMessages(['api_token' => 'This token is not active in Cloudflare.']);
            }
            $zones = $api->zones();
        } catch (CloudflareError $e) {
            throw ValidationException::withMessages(['api_token' => $e->getMessage()]);
        }

        if ($zones === []) {
            throw ValidationException::withMessages(['api_token' => 'The token works but sees no zone. Give it Zone → DNS → Edit on the zones Kiln should manage.']);
        }

        $credential = DnsCredential::query()->create([
            'organization_id' => $organizationId,
            'provider' => 'cloudflare',
            'name' => $name,
            'api_token' => $token,
            'account_id' => $zones[0]['account_id'],
            'verified_at' => now(),
            'created_by' => $userId,
        ]);

        $this->audit->record('edge.cloudflare_connected', 'dns_credential', $credential->id, ['name' => $name, 'zones' => count($zones)], $organizationId);

        return $credential;
    }

    /**
     * Zones the connection can see, with whether Kiln manages them.
     *
     * @return list<array{id: string, name: string, status: string, plan: string, managed: ?array{id: string, proxied: bool}}>
     */
    public function zones(DnsCredential $credential): array
    {
        $managed = CloudflareZone::query()->where('dns_credential_id', $credential->id)->get()->keyBy('zone_id');

        return array_map(fn (array $zone) => [
            'id' => $zone['id'], 'name' => $zone['name'], 'status' => $zone['status'], 'plan' => $zone['plan'],
            'managed' => ($m = $managed->get($zone['id'])) ? ['id' => $m->id, 'proxied' => $m->proxied] : null,
        ], CloudflareApi::with($credential->api_token)->zones());
    }

    public function enable(DnsCredential $credential, string $zoneId, bool $proxied): CloudflareZone
    {
        $zone = collect(CloudflareApi::with($credential->api_token)->zones())->firstWhere('id', $zoneId)
            ?? throw ValidationException::withMessages(['zone' => 'The connection cannot see this zone.']);

        if (CloudflareZone::query()->where('organization_id', $credential->organization_id)->where('name', $zone['name'])->exists()) {
            throw ValidationException::withMessages(['zone' => "{$zone['name']} is already managed by another connection."]);
        }

        $managed = CloudflareZone::query()->create([
            'organization_id' => $credential->organization_id,
            'dns_credential_id' => $credential->id,
            'zone_id' => $zone['id'],
            'name' => $zone['name'],
            'proxied' => $proxied,
        ]);

        // Existing domains under the zone: certificates move to DNS-01 (works behind the orange cloud), records sync.
        $this->domainsIn($managed)->each(function (Domain $domain) use ($credential) {
            if ($domain->tls_mode === TlsMode::Auto) {
                $domain->forceFill(['tls_mode' => TlsMode::Dns, 'dns_credential_id' => $credential->id])->save();
            }
            SyncCloudflareDns::domain($domain->id);
        });
        $this->reapply($credential->organization_id);

        $this->audit->record('edge.cloudflare_zone_enabled', 'dns_credential', $credential->id, ['zone' => $zone['name'], 'proxied' => $proxied], $credential->organization_id);

        return $managed;
    }

    public function updateZone(CloudflareZone $zone, bool $proxied): void
    {
        $zone->forceFill(['proxied' => $proxied])->save();
        $this->domainsIn($zone)->whereNull('cloudflare_proxied')->each(fn (Domain $domain) => SyncCloudflareDns::domain($domain->id));
        $this->audit->record('edge.cloudflare_zone_updated', 'dns_credential', $zone->dns_credential_id, ['zone' => $zone->name, 'proxied' => $proxied], $zone->organization_id);
    }

    /** Stop managing a zone. Kiln's records stay in Cloudflare unless $deleteRecords. */
    public function disable(CloudflareZone $zone, bool $deleteRecords): void
    {
        if ($deleteRecords) {
            foreach (DnsRecord::query()->where('zone_id', $zone->id)->pluck('domain_id')->filter()->unique() as $domainId) {
                $this->dns->forget((string) $domainId);
            }
        }

        DB::transaction(function () use ($zone) {
            $setting = OrganizationSetting::for($zone->organization_id);
            if ($setting->exists && $setting->generated_domain_provider === GeneratedDomains::CLOUDFLARE.$zone->name) {
                $setting->forceFill(['generated_domain_provider' => null])->save();
            }
            $zone->delete();
        });
        $this->reapply($zone->organization_id);

        $this->audit->record('edge.cloudflare_zone_disabled', 'dns_credential', $zone->dns_credential_id, ['zone' => $zone->name, 'records_deleted' => $deleteRecords], $zone->organization_id);
    }

    /** Remove the connection: its zones stop being managed, domains using it for certificates go back to HTTP-01. */
    public function disconnect(DnsCredential $credential): void
    {
        foreach (CloudflareZone::query()->where('dns_credential_id', $credential->id)->get() as $zone) {
            $this->disable($zone, false);
        }

        $domains = Domain::query()->where('dns_credential_id', $credential->id)->get();
        foreach ($domains as $domain) {
            $domain->forceFill(['tls_mode' => TlsMode::Auto, 'dns_credential_id' => null])->save();
        }

        $credential->delete();
        $this->reapply($credential->organization_id);
        $this->audit->record('edge.cloudflare_disconnected', 'dns_credential', $credential->id, ['name' => $credential->name, 'domains_moved_to_http01' => $domains->count()], $credential->organization_id);
    }

    /**
     * The zone's TLS settings Kiln checks, with the recommended value (null when Cloudflare did not answer).
     *
     * @return array<string, array{value: mixed, recommended: string, ok: bool}>|null
     */
    public function health(CloudflareZone $zone): ?array
    {
        try {
            $api = CloudflareApi::with($zone->credential->api_token);
            $out = [];
            foreach (self::RECOMMENDED as $key => $recommended) {
                $value = $api->setting($zone->zone_id, $key);
                $out[$key] = ['value' => $value, 'recommended' => $recommended, 'ok' => $key === 'min_tls_version' ? version_compare((string) $value, $recommended, '>=') : $value === $recommended];
            }

            return $out;
        } catch (CloudflareError) {
            return null;
        }
    }

    public function applyRecommended(CloudflareZone $zone, string $key): void
    {
        if (! array_key_exists($key, self::RECOMMENDED)) {
            throw ValidationException::withMessages(['setting' => 'Unknown setting.']);
        }

        try {
            CloudflareApi::with($zone->credential->api_token)->updateSetting($zone->zone_id, $key, self::RECOMMENDED[$key]);
        } catch (CloudflareError $e) {
            throw ValidationException::withMessages(['setting' => $e->getMessage()]);
        }

        $this->audit->record('edge.cloudflare_zone_setting', 'dns_credential', $zone->dns_credential_id, ['zone' => $zone->name, $key => self::RECOMMENDED[$key]], $zone->organization_id);
    }

    /**
     * @return Builder<Domain>
     */
    private function domainsIn(CloudflareZone $zone)
    {
        return Domain::query()->where('organization_id', $zone->organization_id)
            ->where(fn ($q) => $q->where('name', $zone->name)->orWhere('name', 'like', '%.'.$zone->name));
    }

    /** Trusted proxies follow whether the organization has a managed zone: re-apply its servers. */
    private function reapply(string $organizationId): void
    {
        $this->routes->schedule(...array_map(fn ($server) => $server->id, $this->servers->forOrganization($organizationId)));
    }
}
