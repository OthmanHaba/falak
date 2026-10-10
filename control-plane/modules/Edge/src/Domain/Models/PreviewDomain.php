<?php

namespace Falak\Edge\Domain\Models;

use Falak\Edge\Contracts\Data\PreviewDomainData;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The instance's preview domain (a single row).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $domain
 * @property ?string $dns_credential_id
 * @property string $server_id
 * @property ?string $zone_id
 * @property ?string $record_id
 * @property string $status pending | active | manual | error
 * @property ?string $error
 * @property ?string $created_by
 * @property-read ?DnsCredential $dnsCredential
 */
class PreviewDomain extends Model
{
    use HasUlids;

    protected $table = 'edge_preview_domain';

    /** @var list<string> */
    protected $guarded = [];

    public static function current(): ?self
    {
        return self::query()->with('dnsCredential')->orderBy('created_at')->first();
    }

    /**
     * @return BelongsTo<DnsCredential, $this>
     */
    public function dnsCredential(): BelongsTo
    {
        return $this->belongsTo(DnsCredential::class);
    }

    /** Falak manages the DNS (Cloudflare): the wildcard record and the DNS-01 wildcard certificate. */
    public function managed(): bool
    {
        return $this->dnsCredential !== null && $this->dnsCredential->provider === 'cloudflare';
    }

    /** One label below the domain (what a wildcard certificate covers). */
    public function covers(string $host): bool
    {
        $suffix = '.'.$this->domain;

        return str_ends_with($host, $suffix) && ! str_contains(substr($host, 0, -strlen($suffix)), '.') && strlen($host) > strlen($suffix);
    }

    /** Under the domain at any depth (names reserved for previews). */
    public function contains(string $host): bool
    {
        return $host === $this->domain || str_ends_with($host, '.'.$this->domain);
    }

    public function toData(): PreviewDomainData
    {
        return new PreviewDomainData(
            domain: $this->domain,
            organizationId: $this->organization_id,
            serverId: $this->server_id,
            dnsCredentialId: $this->dns_credential_id,
            managedDns: $this->managed(),
            status: $this->status,
            error: $this->error,
        );
    }
}
