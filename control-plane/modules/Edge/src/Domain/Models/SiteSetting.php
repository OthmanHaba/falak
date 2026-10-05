<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-site edge settings (IP rules, request body limit, compression).
 *
 * @property string $site_id
 * @property list<string> $allow_ips
 * @property list<string> $deny_ips
 * @property ?int $max_body_bytes
 * @property bool $encode
 * @property ?string $compose_primary compose sites: the service whose domains are the site's own rows, as Edge last saw it
 */
class SiteSetting extends Model
{
    protected $table = 'edge_site_settings';

    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['allow_ips' => '[]', 'deny_ips' => '[]', 'encode' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['allow_ips' => 'array', 'deny_ips' => 'array', 'max_body_bytes' => 'integer', 'encode' => 'boolean'];
    }

    public static function for(string $siteId): self
    {
        return self::query()->find($siteId) ?? new self(['site_id' => $siteId]);
    }
}
