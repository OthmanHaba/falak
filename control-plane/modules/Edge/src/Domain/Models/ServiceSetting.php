<?php

namespace Kiln\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * IP lists of one public service of a compose site, on top of the site's ({@see SiteSetting}): its allow list replaces
 * the site's when set, its deny list adds to the site's.
 *
 * @property string $site_id
 * @property string $service
 * @property list<string> $allow_ips
 * @property list<string> $deny_ips
 */
class ServiceSetting extends Model
{
    protected $table = 'edge_service_settings';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['allow_ips' => '[]', 'deny_ips' => '[]'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['allow_ips' => 'array', 'deny_ips' => 'array'];
    }

    public static function for(string $siteId, string $service): self
    {
        return self::query()->where('site_id', $siteId)->where('service', $service)->first()
            ?? new self(['site_id' => $siteId, 'service' => $service]);
    }

    /**
     * Composite key: save() updates by both columns.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query->where('site_id', $this->getOriginal('site_id') ?? $this->site_id)->where('service', $this->getOriginal('service') ?? $this->service);
    }
}
