<?php

namespace Kiln\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A DNS record Kiln created in a Cloudflare zone for a domain (tagged `kiln:<domain id>` there). Kiln only updates or
 * deletes records listed here; an existing record of the same name is reported as a conflict, never overwritten.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $domain_id
 * @property string $zone_id
 * @property string $name
 * @property string $type A | AAAA | CNAME
 * @property string $content
 * @property bool $proxied
 * @property ?string $record_id Cloudflare's record id once created
 * @property string $status pending | synced | conflict | error
 * @property ?string $error
 * @property ?Carbon $synced_at
 * @property CloudflareZone $zone
 */
class DnsRecord extends Model
{
    use HasUlids;

    public const SYNCED = 'synced';

    public const PENDING = 'pending';

    public const CONFLICT = 'conflict';

    public const ERROR = 'error';

    protected $table = 'edge_dns_records';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['proxied' => 'boolean', 'synced_at' => 'datetime'];
    }

    /** @return BelongsTo<CloudflareZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(CloudflareZone::class, 'zone_id');
    }

    public static function comment(string $domainId): string
    {
        return 'kiln:'.$domainId.' (managed by Kiln; edits are overwritten)';
    }
}
