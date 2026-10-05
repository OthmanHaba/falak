<?php

namespace Falak\Sites\Domain\Models;

use Falak\Sites\Contracts\Data\EnvironmentData;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable version of a site's environment. Values are encrypted at rest.
 *
 * @property string $id
 * @property string $site_id
 * @property int $version
 * @property array<string, string> $variables
 * @property list<string> $exposed keys exported into the deploy script
 * @property list<string> $changed_keys
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class EnvironmentVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'sites_environment_versions';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['variables'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'variables' => 'encrypted:array',
            'exposed' => 'array',
            'changed_keys' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function toData(): EnvironmentData
    {
        /** @var array<string, string> $variables */
        $variables = array_map('strval', $this->variables);

        return new EnvironmentData($this->site_id, $this->version, $variables, array_values(array_intersect($this->exposed, array_keys($variables))));
    }
}
