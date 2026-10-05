<?php

namespace Falak\Telemetry\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $deployment_id
 * @property string $organization_id
 * @property ?string $site_id
 * @property int $grafana_id
 * @property string $status
 */
class DeploymentAnnotation extends Model
{
    protected $table = 'telemetry_annotations';

    protected $primaryKey = 'deployment_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['grafana_id' => 'integer'];
    }
}
