<?php

namespace Kiln\Projects\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\Projects\Contracts\Data\EnvironmentData;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property bool $is_production
 * @property ?string $forked_from_id
 * @property ?string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Service> $services
 */
class Environment extends Model
{
    use HasUlids;

    /** Slugs that collide with /projects/{project}/… routes. */
    public const RESERVED_SLUGS = ['settings', 'environments', 'services', 'service', 'canvas', 'edit', 'create'];

    protected $table = 'projects_environments';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_production' => 'boolean'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class)->orderBy('created_at')->orderBy('id');
    }

    public function toData(): EnvironmentData
    {
        return new EnvironmentData($this->id, $this->organization_id, $this->project_id, $this->name, $this->slug, $this->is_production, $this->forked_from_id);
    }
}
