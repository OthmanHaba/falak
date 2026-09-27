<?php

namespace Kiln\Templates\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An organization's own template (template.yaml + compose.yaml). Every save appends a revision.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property string $name
 * @property string $version
 * @property string $category
 * @property string $description
 * @property string $template_yaml
 * @property string $compose_yaml
 * @property int $revision
 * @property ?string $created_by
 * @property ?string $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CustomTemplate extends Model
{
    use HasUlids;

    protected $table = 'templates_custom';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    /**
     * @return HasMany<CustomTemplateRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CustomTemplateRevision::class, 'template_id')->orderByDesc('revision');
    }
}
