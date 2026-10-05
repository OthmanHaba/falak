<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Cloudflare zone an organization lets Falak manage: DNS records for its domains, generated names under it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $dns_credential_id
 * @property string $zone_id Cloudflare's zone id
 * @property string $name e.g. example.com
 * @property bool $proxied default for new records (orange cloud)
 * @property ?string $plan Cloudflare plan (free | pro | business | enterprise), last read from the zone
 * @property bool $rate_limited Falak has rate limit rules in the zone
 * @property ?string $security_level_before the security level to return to while Under Attack mode is on
 * @property DnsCredential $credential
 */
class CloudflareZone extends Model
{
    use HasUlids;

    protected $table = 'edge_cloudflare_zones';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['proxied' => 'boolean', 'rate_limited' => 'boolean'];
    }

    /** @return BelongsTo<DnsCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(DnsCredential::class, 'dns_credential_id');
    }

    /** @return HasMany<DnsRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class, 'zone_id');
    }

    /** Whether $host is this zone or a name under it. */
    public function covers(string $host): bool
    {
        $host = strtolower(ltrim($host, '*.'));

        return $host === $this->name || str_ends_with($host, '.'.$this->name);
    }

    /** The enabled zone that covers $host (the most specific one), if any. */
    public static function forHost(string $organizationId, string $host): ?self
    {
        return self::query()->with('credential')->where('organization_id', $organizationId)->get()
            ->filter(fn (self $zone) => $zone->covers($host))
            ->sortByDesc(fn (self $zone) => strlen($zone->name))
            ->first();
    }
}
