<?php

namespace Falak\Edge\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Organization-wide domain settings (Settings → Domains).
 *
 * @property string $organization_id
 * @property ?string $generated_domain_provider null = server default; a suffix (sslip.io, nip.io, …) or "off"
 */
class OrganizationSetting extends Model
{
    protected $table = 'edge_organization_settings';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    public static function for(string $organizationId): self
    {
        return self::query()->find($organizationId) ?? new self(['organization_id' => $organizationId]);
    }
}
