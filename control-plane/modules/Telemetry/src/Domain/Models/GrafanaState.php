<?php

namespace Kiln\Telemetry\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $organization_id
 * @property ?string $folder_uid
 * @property ?array<string, string> $dashboards base uid => imported uid
 * @property ?Carbon $provisioned_at
 * @property ?string $last_error
 */
class GrafanaState extends Model
{
    protected $table = 'telemetry_grafana_states';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['dashboards' => 'array', 'provisioned_at' => 'datetime'];
    }
}
